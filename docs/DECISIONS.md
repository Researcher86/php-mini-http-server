# Decisions

Why this codebase is the way it is: what was chosen, what was rejected, and
which bugs forced a design to change.

The code says what it does and its comments say why each line is there; this
is the layer above that — the decisions that shaped whole components, the
alternatives that were considered and dropped, and the failures that were
only found by running the thing. It is the least reconstructible knowledge in
the repository, which is why it is written down.

For how the server was built step by step, see [PHASES.md](PHASES.md). For
how the pieces fit together today, see [ARCHITECTURE.md](ARCHITECTURE.md).

## The decisions themselves

The sections below are grouped by subject. This table is the other index:
one row per decision that still governs the code, plus the ones that were
reversed — because a decision that was later undone is exactly the one a
reader is most likely to act on by mistake.

| Decision | Status |
|---|---|
| `stream_select()`, not an event-loop extension | current, [why](#the-loop-is-stream_select) |
| One process, one loop — no forking, no threads | current, [why](#one-process-one-loop) |
| `Content-Length` is the only body framing the parser implements | current, [why](#framing-is-content-length-or-nothing) |
| A request announcing any other transfer coding is refused with 501 | current, [why](#framing-is-content-length-or-nothing) |
| Every response is framed, empty ones included; 204 is the one exception | current, [why](#every-response-states-its-length) |
| HEAD is answered from the GET routes, then stripped of its body | current, [why](#head-is-get-minus-the-bytes) |
| A refused request carries the status that answers it | current, [why](#one-table-not-two) |
| Error handling is a middleware — and the runtime does not rely on it alone | current, [why](#the-middleware-is-not-the-only-net) |
| Backpressure pauses reads; it never drops the client | current, [why](#a-slow-client-is-paused-not-punished) |
| Two clocks: an idle timeout and a header timeout | current, [why](#two-clocks-idle-and-slowloris) |
| Draining refuses new requests with 503 + close, rather than hanging up | current, [why](#shutdown-says-why) |
| The connection state enum is set by the code, not just drawn in a diagram | current, [why](#states-the-server-actually-enters) |
| Buffers hold bytes and know nothing about HTTP | current, [why](#buffers-do-not-parse) |
| Periodic timers are anchored to their original schedule, not the last firing | current, [why](#anchored-timers) |
| `Logger` is one method, not PSR-3 | current, [why](#one-log-method) |
| Responses are always HTTP/1.1, even to an HTTP/1.0 client | current, known simplification, [why](#what-is-deliberately-missing) |
| No TLS, no HTTP/2, no chunked responses, no static files | current, [why](#what-is-deliberately-missing) |
| **`ReadBuffer` searched for `\r\n\r\n` itself** | **removed** — [why](#buffers-do-not-parse). Three layers knew where a request ended; only the parser needs to. |
| **`Connection::hasCompleteRequest()`** | **removed** — same reason, same commit. |

---

# The loop is stream_select()

`ext-event`, `ev` and `uv` all scale better than `stream_select()`: they
scale with the number of *ready* descriptors rather than with the number of
watched ones, and they have no 1024-descriptor ceiling. All three were
rejected anyway.

The reason is what the project is for. `stream_select()` is in every PHP
build, needs no extension to install, and — most of the point — it is the
system call the whole idea rests on, visible in fifteen lines of
[SelectLoop](../src/EventLoop/SelectLoop.php) rather than hidden behind a C
extension. A reader can see the wait, see the two arrays go in, and see
which streams come back ready.

What it costs, stated plainly so nobody mistakes this for a production
choice:

- the loop rebuilds and scans its watch arrays on every pass, so cost grows
  with the number of *open* connections, not the number of active ones;
- `FD_SETSIZE` caps the descriptor set (commonly 1024), which is a real
  ceiling, not a theoretical one;
- a timer's resolution is the select timeout, so timers are approximate.

The [benchmark](../bin/bench.php) drives 1000 concurrent connections
precisely because that is where this starts to show.

Two details inside the loop are worth knowing about, both of them the kind
of thing that only appears once the server runs for real:

- **A handled signal interrupts `select()`**, and PHP surfaces that as a
  warning. That is not a failure — it is how graceful shutdown gets a chance
  to run — so the call is silenced and the loop simply re-waits.
- **A watched stream can be closed between two loop passes** (the idle sweep
  closes connections on a timer, without telling the loop). PHP cannot build
  a descriptor set from a dead resource and raises `ValueError`; the loop
  catches it, drops the closed watchers and re-waits. It costs one wasted
  pass and self-heals, which is why the sweep is allowed to stay ignorant of
  the loop.

# One process, one loop

No forking, no threads, no worker pool. One process multiplexes every
connection.

That is the lesson of the project: concurrency without a thread or a process
per client. Adding a pre-fork pool would double the moving parts and teach a
different thing — which is exactly what the sibling project
[php-worker-pool](https://github.com/Researcher86/php-worker-pool) is for.

The consequence is the constraint every handler lives under: **blocking in a
handler blocks the entire server**. There is no other thread to make
progress. That is not a flaw to be patched around here; it is the thing the
architecture makes visible.

# Framing is Content-Length, or nothing

The parser implements exactly one way of finding where a request body ends:
the `Content-Length` header. `Transfer-Encoding: chunked` is not decoded.

Chunked decoding is a small state machine, and it would be a reasonable
Phase 22. What is *not* reasonable is what the code did before: ignore
`Transfer-Encoding` entirely and frame by `Content-Length` anyway. That is
not "partial support", it is request smuggling. A chunked request with no
`Content-Length` parsed as a request with an empty body — and the chunk
payload stayed in the read buffer, where Phase 15 pipelining then served it
as the next request. Bytes a front-end proxy counted as one request's body
became a second request here.

So the parser refuses. Only `identity` (which means "no coding was applied")
is accepted; anything else is 501 Not Implemented and the connection closes,
because once framing is unknowable there is no safe place to resume.

The same reasoning governs conflicting `Content-Length` headers: repeated
identical values are accepted, disagreeing ones are a 400. Two lengths mean
two possible request boundaries, and picking one is picking an attacker's.

# Every response states its length

Including the empty ones. On a connection that closes after the response,
"the body ends when the socket closes" is a valid framing; on a kept-alive
connection it is not, and a bare `200 OK` with no `Content-Length` leaves the
client waiting for a body that never comes. That was a real hang, found by
keep-alive tests, and it is why the encoder adds `Content-Length: 0` rather
than leaving an empty response unframed.

204 is the exception, and the only one: its emptiness is part of the status
line's meaning, and it must carry no `Content-Length` at all — not even zero.
`HttpStatusCode::framesBody()` is that rule, in one place, consulted by both
the encoder and the HEAD path.

One related bug is worth recording because the fix is so easy to undo. The
encoder used to ask a plain array whether `Content-Length` was already set,
which is a case-sensitive lookup. A handler that wrote `content-length` got
its own header *and* a canonical copy — two `Content-Length` lines, which a
recipient is required to reject. The question now goes through `Headers`,
whose entire job is knowing that header names are case-insensitive.

# HEAD is GET minus the bytes

HEAD is defined as GET without a response body, so the router answers it
from the GET table rather than requiring every route to be registered twice,
and the body is dropped afterwards — not earlier.

The order matters. `Content-Length` must be the length the equivalent GET
*would* have sent, so it is taken while the body is still in hand and only
then are the bytes discarded. A HEAD on a 204 still gets no length, because
that rule outranks this one.

# One table, not two

Four things can go wrong while parsing: a malformed request, a header block
over the limit, a body over the limit, an undecodable transfer coding. Each
has a status: 400, 431, 413, 501.

That mapping used to be written twice — once in `ConnectionHandler`, where
parsing happens before any middleware runs, and once in
`ErrorHandlerMiddleware`, in case one surfaced from inside a handler. Two
tables for one mapping is one table too many; they drift.

The status now lives on the exception itself
([RequestException](../src/Http/Protocol/RequestException.php)), stated once
beside the explanation of what the failure is, and both sites collapse to a
single `catch`. The error body is the status' own reason phrase, so a code
and its phrase cannot disagree either.

# The middleware is not the only net

Phase 13's promise is that one failed request does not stop the server, and
`ErrorHandlerMiddleware` is where that is meant to happen. It is not enough
on its own, because two things happen *outside* the pipeline — and both of
them were killing the process until a review went looking.

- **Encoding runs after the pipeline returns.** The encoder refuses header
  values containing CR/LF (they would end the header block early and let the
  rest be read as a second, attacker-chosen response). Its own docblock
  claimed the error middleware turned that refusal into a 500; it could not
  — the middleware had already returned. The exception reached the event
  loop and stopped the server, so a single buggy handler was a denial of
  service for every connected client. `ConnectionHandler` now answers that
  request with a plain 500 and keeps going.
- **Writing runs on a later loop pass entirely.** A client that closes
  without reading makes the kernel answer the bytes still in flight with
  RST, so the next write fails outright rather than merely blocking.
  `WriteBuffer` raises for exactly that, and nothing caught it: one killed
  browser tab took every other connected client down with it. The write path
  now closes that one connection and carries on.

The general shape of the rule: the pipeline protects the application, and
the runtime protects itself. Anything the runtime does after the pipeline —
encoding, writing, closing — needs its own guard, because by then there is
nobody left to catch for it.

# A slow client is paused, not punished

Once a connection's queued responses pass 64 KiB, the server stops reading
from that client until the buffer drains, then resumes. It does not close
the connection, and it does not drop responses.

The alternative — keep reading and keep buffering — is the failure the phase
exists to prevent: a client that reads slowly (or not at all) while
pipelining requests makes the server buffer unbounded work on its behalf.
Pausing moves the cost back onto the client's own TCP window, where it
belongs, and costs the client nothing but time.

One consequence is easy to get wrong and has a test of its own: requests
already sitting in the read buffer when the pause hit will never be woken by
a new network event, because no new bytes are being read. They have to be
served explicitly when reads resume.

# Two clocks: idle and Slowloris

A single idle timeout is not enough, and the reason is a real attack.

The idle sweep closes connections that have gone quiet — the client that
connects and says nothing. But a Slowloris client is never quiet: it sends
one byte at a time, resetting the idle clock forever while never finishing a
header block. So there is a second clock that starts when a connection is
waiting for the rest of its headers and stops when a complete request is
parsed. A connection stuck mid-header past `headerTimeout` is closed no
matter how busily it is dribbling.

Both sweeps run from the same periodic timer, which is Phase 16's reason for
existing: not every unit of work in a server is triggered by a socket.

# Shutdown says why

Draining could simply stop reading and let connections time out. It does
more:

- the listening socket closes immediately, so nothing new is accepted;
- a request already in flight finishes and its response flushes;
- a *new* request arriving on an established keep-alive connection is
  refused with `503` and `Connection: close`, so the client learns what
  happened instead of watching a socket go quiet;
- connections sitting at rest between requests are reaped at once rather
  than waiting out the idle timeout, since they will never be allowed to
  start another request anyway.

A second signal skips the waiting. The distinction between `drain()` (stop
accepting) and `finish()` (close what is left) is what makes both behaviours
one state machine rather than two code paths.

# States the server actually enters

`ConnectionState` draws NEW → CONNECTED → READING → PROCESSING → WRITING →
READING → CLOSED, and for a long time nothing ever entered PROCESSING: the
handler went from reading straight to writing, so a connection sitting
inside application code still reported READING.

A state no code sets is a comment pretending to be a type. `ConnectionHandler`
now enters PROCESSING when a request has been parsed and is about to be
handled, and a test observes it from inside a route handler — the one moment
it is visible from outside.

The same principle explains why illegal transitions throw rather than being
tolerated: these states are a debugging aid first and a contract second, and
a debugging aid that can lie is worse than none.

# Buffers do not parse

`ReadBuffer` accumulates bytes and drops the ones somebody else has
consumed. It does not search for `\r\n\r\n`.

It used to. `ReadBuffer::contains()` and `extractThrough()` looked for the
header terminator, and `Connection::hasCompleteRequest()` wrapped them — so
three layers knew where an HTTP request ends, while the actual read path had
been asking the parser since Phase 5. The buffer's own docblock said this was
the parser's job, which is how the duplication was noticed. Both were
removed; the Phase 4 tests now ask the parser, exactly as the server does.

The general rule it leaves behind: a layer that holds bytes should not also
interpret them, or the two copies of the framing rule will eventually
disagree — and a framing disagreement is a smuggling bug.

# Anchored timers

A periodic timer reschedules to the next slot of its *original* schedule,
not to "now plus the interval". Under a loop that runs late — a slow handler,
a burst of connections — the sliding version drifts further behind on every
tick and a one-second sweep quietly becomes a three-second one. The anchored
version is merely late once.

# One log method

`Logger` has a single method: `log(string $message)`. No levels, no
channels, no placeholders, no PSR-3.

This project has no dependencies, and the only thing it needs from logging is
"record that this happened". A real application swaps PSR-3 in behind the
same interface. The one decision worth keeping is that `StderrLogger` writes
to STDERR rather than STDOUT, so server logs and script output (client
responses, benchmark tables, `/metrics` dumps) can be redirected separately.

# What is deliberately missing

Not oversights. Each of these would add mechanism without adding
understanding, and the project optimises for the second:

- **TLS** — a handshake and a certificate story, no new server concept.
- **HTTP/2** — a different framing, multiplexing and flow-control model
  entirely; it would replace the lesson rather than extend it.
- **Chunked responses** — the server always knows its body length, because
  handlers return a complete `HttpResponse`. Streaming responses would need
  a different handler contract first.
- **Static file serving** — an application concern, and one that invites
  path-traversal bugs into a codebase whose point is elsewhere.
- **Cookies, sessions, body parsing** — framework territory. A handler
  receives the raw body and may do what it likes with it.

One simplification is not a scope decision but a known inaccuracy, recorded
here so it is not mistaken for correctness: **responses are always sent as
HTTP/1.1**, including to an HTTP/1.0 client. Keep-alive negotiation does
respect the client's version (HTTP/1.0 closes unless it asks otherwise), so
the behaviour is right even where the version string is not.
