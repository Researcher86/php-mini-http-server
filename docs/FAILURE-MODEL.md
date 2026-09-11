# Failure Model

What breaks, what survives it, and what this server does **not** guarantee.

The README's Failure Scenarios section covers the first two: a partial
request, a slow client, a handler that throws, a client that vanishes
mid-response — all of them survivable, all of them tested. This document is
the other half, and the less comfortable one: the limits, the guarantees
that were never made, and the ways this server fails when pushed past what
it was built for.

Read it before trusting it with anything beyond learning how an HTTP server
works. It is an educational implementation, not a production one, and the
difference is mostly written down here.

---

## One process, one point of failure

The listening socket, every connection, every buffer and the event loop
live in one PHP process.

```text
The process dies
       │
       ▼
Every connected client is dropped mid-response
       │
       ▼
Nothing restarts it
```

There is no supervisor, no second process, no shared state to recover from.
Whatever ran `bin/server.php` is responsible for restarting it —
`systemd`, Docker's restart policy, a process manager. That is deliberate:
a supervisor is an operational concern, and the sibling project
[php-worker-pool](https://github.com/Researcher86/php-worker-pool) is where
the multi-process question is actually studied.

## A blocking handler blocks everyone

This is the central trade of the architecture, and it is not a bug to be
fixed — it is the thing the architecture makes visible.

```text
Handler calls a slow database
       │
       ▼
The loop is inside that call
       │
       ▼
No other socket is read, no timer runs, no connection is accepted
```

There is no other thread to make progress. A handler that sleeps for 500ms
adds 500ms to everyone waiting behind it.

The one mercy is that this is measured rather than merely warned about:
`GET /metrics` reports `loop_max_lag_ms`, the longest single stretch the
loop has ever spent inside handlers. If that number is large, the request
counters beside it are lying about how healthy the server is.

## Responses are written once, and never retried

If a client's socket is gone when the server tries to write, the response
is dropped and that connection is closed. Nothing is buffered past the
connection's life, nothing is retried, nothing is reported to anyone but
the log.

So a client that disconnects between sending a request and receiving its
response never learns whether the request was handled — and it may well
have been. Handlers are not required to be idempotent, because nothing here
retries on their behalf; but a *client-side* retry after a dropped
connection can double-apply a non-idempotent request, and that is outside
this server's control entirely.

## The connection ceiling is low, and hit before you would expect

`stream_select()` cannot be given a descriptor numbered at or above
`FD_SETSIZE` — 1024 in a standard PHP build — and past that it refuses the
whole wait rather than the offending stream. A loop that cannot wait cannot
serve anybody.

So `ServerConfig::maxConnections` (512 by default) stops the server well
short of it: past the ceiling a connection is accepted and closed at once,
and `refused_connections` in `/metrics` counts them. Note *descriptor
numbers*, not count — a process that already holds many open files can run
into the same wall with far fewer connections.

Raising the ceiling much past the default needs a PHP built with a larger
`FD_SETSIZE`, or an event loop that does not use `select()` at all. The
[decision to use `stream_select()` anyway](DECISIONS.md#the-loop-is-stream_select)
explains why the project accepts this.

## Limits are per-connection, and coarse

| What | Limit | What it does not do |
| --- | --- | --- |
| Header block | 8 KiB | nothing about many small headers arriving slowly |
| Request body | 1 MiB | nothing about many bodies at once |
| Queued responses | 64 KiB | pauses reads on *that* connection only |
| Idle connection | 30s | reaped on a 1s tick, so accurate to about a second |
| Header arrival | 5s | the Slowloris clock, same tick, same accuracy |
| Connections | 512 | no per-client or per-IP accounting at all |

Every one of these is per-connection. One client with an oversized body is
refused; five hundred clients each sending a merely large one are bounded
only by `maxConnections` multiplied by the body limit. There is no rate
limiting, no per-IP accounting, and no global memory budget.

## Timers are best-effort

A timer fires on the first loop pass at or after its deadline, so its
resolution is however long the loop is busy. The sweeps that enforce the
idle and header timeouts run on a one-second tick, which makes those
timeouts accurate to roughly a second — and less than that if a handler is
holding the loop (see above).

A periodic timer is anchored to its original schedule rather than to its
last firing, so a slow pass makes it late once instead of drifting further
behind for ever. What it will not do is fire repeatedly to catch up on runs
it missed.

## No TLS, no authentication, no authorisation

Everything is plain text on a plain TCP socket. There is no certificate, no
`https://`, no `Authorization` handling, no session, no CSRF protection, no
rate limiting, no per-IP anything.

Anything sensitive would need all of that in front, and at that point the
thing in front is the real server.

## Framing is Content-Length, in both directions

A request that announces any transfer coding but `identity` is refused with
501, because decoding it is not implemented and guessing where it ends is
how request smuggling works.

The same limit applies on the way out: a handler returns a complete
`HttpResponse`, so the server always knows the body length before it writes
anything. There is no chunked response, no streaming body, no server-sent
events, and no way for a handler to start writing before it has finished
computing. A large response is held in memory in full.

Request bodies are buffered whole as well: a handler never sees the first
byte until the last has arrived.

## The response is framed; the content is not checked

The encoder refuses CR/LF in a header name or value, so a handler cannot
split the response into two. It does nothing about what is *in* a body: no
escaping, no content-type enforcement, no output filtering. A handler that
echoes a request parameter into an HTML body has written a cross-site
scripting hole, and this server will deliver it faithfully.

## Requests are answered in the order they arrive, always

HTTP/1.1 pipelining requires responses in request order, and that is what
the server does — which means one slow request at the front holds up every
pipelined request behind it on that connection. This is head-of-line
blocking, it is inherent to HTTP/1.1, and it is one of the reasons HTTP/2
exists.

## Version handling is approximate

Responses are always sent as `HTTP/1.1`, including to an HTTP/1.0 client.
Keep-alive negotiation does respect the client's version — HTTP/1.0 closes
unless it asks otherwise — so the behaviour is right even where the version
string on the status line is not.

`Host` is required on HTTP/1.1 and validated, but never used: there is no
virtual hosting, and every request is routed by path alone regardless of
which name it was addressed to.

## Shutdown is graceful, not seamless

`SIGTERM` stops new connections, lets in-flight requests finish, refuses
new requests on established connections with 503, and exits when the last
one is done. What it does not do is hand anything over: there is no socket
handoff, no second process picking up the listener, no zero-downtime
reload. Between the old process exiting and a new one binding, connections
are refused by the kernel.

A second signal skips the waiting entirely and closes whatever is still
open, mid-response if necessary.
