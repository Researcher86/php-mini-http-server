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
| A failed `select()` means nothing is ready, never everything | current, [why](#a-failed-wait-is-not-a-ready-list) |
| The server refuses connections past `maxConnections`, well under FD_SETSIZE | current, [why](#a-failed-wait-is-not-a-ready-list) |
| A stream closed mid-pass is never handed to its handler | current, [why](#a-failed-wait-is-not-a-ready-list) |
| The parser refuses what RFC 7230 says to refuse, rather than normalising it | current, [why](#refusing-beats-normalising) |
| The loop measures its own busy/idle split and worst lag | current, [why](#measuring-the-thing-the-readme-warns-about) |
| Formatting is settled by php-cs-fixer, not per file | current, [why](#borrowed-from-the-sibling-projects) |
| **`ReadBuffer` searched for `\r\n\r\n` itself** | **removed** — [why](#buffers-do-not-parse). Three layers knew where a request ended; only the parser needs to. |
| **`Connection::hasCompleteRequest()`** | **removed** — same reason, same commit. |
| **`?float $now` / `?float $startedAt` seams** | **replaced** by one `Clock` — [why](#one-clock-and-one-rule-for-which-to-ask) |

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
  warning plus a `false` return. That is not a failure — it is how graceful
  shutdown gets a chance to run — so the call is silenced and the loop
  re-waits. Reading that `false` correctly turns out to matter a great deal;
  see [A failed wait is not a ready list](#a-failed-wait-is-not-a-ready-list).
- **A watched stream can be closed between two loop passes** (the idle sweep
  closes connections on a timer, without telling the loop). PHP cannot build
  a descriptor set from a dead resource and raises `ValueError`; the loop
  catches that, and sweeps closed streams at the top of every pass so it
  rarely has to.

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

204 and 304 are the exceptions: their emptiness is part of the status line's
meaning, so this server emits no `Content-Length` for either.
`HttpStatusCode::framesBody()` keeps that rule in one place, consulted by both
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

# A failed wait is not a ready list

`stream_select()` has three outcomes and the loop used to notice only two.
It can report how many streams are ready, and it can report zero. It can
also report **failure** — by returning `false` and leaving the arrays it was
given exactly as they were passed in.

That third case is not exotic. It is what a handled signal looks like from
inside the wait, and the signal that does it in practice is the SIGTERM
asking for a graceful shutdown. The loop ignored the return value, so those
untouched arrays — the entire watch list — were read as the set of ready
streams, and every watched connection had its read handler called. A read
on an idle connection returns nothing, and a read that returns nothing is
how a handler recognises a closed peer. So the server dropped every quiet
keep-alive client the instant it was signalled: graceful shutdown began by
abruptly closing the connections it exists to let finish.

It was found by writing `examples/graceful-shutdown.php`, where a client
asks for one more thing after the signal and is supposed to be told 503.
It was told nothing, because its connection was already gone.

Two consequences followed from fixing it.

**The connection ceiling had to become explicit.** `select()` also fails
when a descriptor is numbered at or above `FD_SETSIZE` (1024 in a standard
PHP build), and it fails the *whole* wait, not the offending stream. Before
the fix, that failure accidentally self-corrected: everything looked ready,
the idle connections were closed, the count came down. Handled correctly,
the same situation is a loop spinning on a wait that can never succeed —
worse. So `ServerConfig::maxConnections` (512) stops the server well short
of the wall, a refused connection is accepted and closed at once rather than
left queued, and `refused_connections` is reported at `/metrics`, because a
server that is smaller than its traffic should say so rather than be
guessed at.

**A stream can also die inside a pass.** `select()` reports what was ready
when it returned; a handler earlier in the same pass may have closed one of
those streams since — any sweep, broadcast or shutdown that closes a
connection it does not own has that shape. Handing the next handler a
closed resource is a `TypeError`, and it ends the loop for everybody. So
readiness is re-checked against the resource immediately before the call,
and closed streams are swept at the top of each pass rather than only when
`select()` complains about one (that path returned early, costing every
other connection its turn).

# Refusing beats normalising

The parser's job is to produce a request or refuse to. What it must never do
is accept something questionable and quietly tidy it into something valid.
Every place it did was a place where this server and whatever sits in front
of it could come to different conclusions about the same bytes — and two
machines disagreeing about where a request ends, or which header it carried,
is request smuggling.

They were found by writing the contract down as a table
([tests/Http/Protocol/HttpParserFuzzTest.php](../tests/Http/Protocol/HttpParserFuzzTest.php),
the idea taken from php-mini-redis), which forced a verdict on cases nobody
had thought to have an opinion about:

- **`Host : evil`** was trimmed into a valid `Host`. RFC 7230 3.2.4 makes
  rejecting whitespace before the colon a MUST, precisely because a proxy
  that forwards `Host ` as an unknown header while this server reads it as
  `Host` is the disagreement in its purest form.
- **A folded continuation line containing a colon** was read as a header of
  its own.
- **A lone CR, an LF, a NUL or a DEL inside a value** passed through. The
  response side has refused those since Phase 7; the request side now
  agrees.
- **`get / HTTP/1.1`** was upper-cased and served. RFC 7230 3.1.1: the
  method token is case-sensitive, so `get` is an unknown method.
- **Host itself** — missing, empty, or repeated — was accepted. All three
  are a MUST-reject in RFC 7230 5.4, and the repeat is the dangerous one: a
  front-end routing on the first `Host` and a server reading the second
  send one request to two different places.

The rule that came out of it, and the reason the fuzz table is worth
keeping: **anything present must either be valid or be refused**. "Probably
meant X" is not a third option.

# Measuring the thing the README warns about

The README has always said that a blocking call in a handler blocks the
entire server. Nothing measured it, and the request counters could not:
they read perfectly healthy through exactly the stretch in which nobody
else's socket was being looked at.

So the loop times each pass — waiting in `select()` is idle, running
handlers and timers is busy — and reports both to
[LoopMetrics](../src/Metrics/LoopMetrics.php), along with the longest single
busy stretch there has ever been. That last number is the useful one: it is
the worst delay any other connection could have suffered waiting its turn.
`GET /metrics` prints all of it.

The accounting uses `hrtime()` rather than `microtime()`, for the reason in
the next section.

# One clock, and one rule for which to ask

Three ad-hoc seams for time had grown: an optional `?float $now` on each
sweep, an optional `?float $startedAt` on the metrics constructor, and bare
`microtime()` calls inside `Connection` that nothing could reach at all.
Tests worked around the last by computing `connectedAt() + 10.0` and handing
it back in.

They are one [Clock](../src/Support/Clock.php) now, with a `FakeClock` for
tests. The rule the interface documents:

```text
Clock     when is it now?        deadlines, uptime      injectable
hrtime()  how long did it take?  durations, loop lag    not injectable
```

Elapsed time is measured monotonically because a wall-clock correction
mid-request would otherwise produce a negative duration. Deadlines ask the
Clock because a test should be able to reach five seconds from now without
waiting five seconds.

`SelectLoop` is the deliberate exception: it keeps asking `microtime()`
directly, because its timer deadlines have to agree with the kernel that
`select()` is waiting against, and a fake clock there would simply
desynchronise from it.

# Borrowed from the sibling projects

Several things here came from reading
[php-mini-redis](https://github.com/Researcher86/php-mini-redis) and
[php-job-queue](https://github.com/Researcher86/php-job-queue), which solve
different problems with the same shape of runtime. Recorded because
"where did this come from" is exactly what a reader cannot reconstruct:

- **The parser fuzz table** and **the event-loop metrics** are php-mini-redis's
  `RespParserFuzzTest` and `EventLoopMetrics`, adapted. Both earned their
  keep immediately: the table found five leniencies, and the metrics made
  a documented warning into a number.
- **`Clock` / `SystemClock` / `FakeClock`** and **`maxConnections`** are
  php-mini-redis's too.
- **The `examples/` shape** — one script, one question in its docblock, the
  answer printed against a real server — is php-mini-redis's, and writing
  the graceful-shutdown one found the `select()` bug above. Which is the
  argument for having examples at all: a test asserts what you thought to
  assert, and a script you have to watch run shows you what you did not.
- **php-cs-fixer with an explained config** is php-job-queue's. Formatting
  became a settled question rather than a per-file judgement call, so a
  diff shows a change in behaviour and never a change in brace placement.

One thing was deliberately *not* taken. php-mini-redis reschedules a
periodic timer to `now + interval`; this project anchors it to the original
schedule instead, so a slow pass makes a timer late once rather than sliding
its whole cadence later. Neither fires repeatedly to catch up, which is the
part that actually matters; ours keeps the cadence honest as well.

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
