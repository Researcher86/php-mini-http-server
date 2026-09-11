# PHP Mini HTTP Server — How It Was Built

The plan this project was built from: twenty-two phases, each with what it
had to achieve and how it was confirmed done. Every one of them is finished —
this is kept as the record of the order things were built in and what each
step was actually for, not as work outstanding.

Each phase ends with a **Tests** section: the tests that hold that phase's
Definition of Done, named down to the individual test method where one test
answers for one line of the plan. Phase 21 is the exception and says why.
The whole suite runs with `make test`.

Two things that used to live here have moved:

- **Why the design turned out this way**, what was rejected, and the bugs
  that changed a decision: [DECISIONS.md](DECISIONS.md).
- **What the finished server does not promise** — its limits and the
  guarantees it never made: [FAILURE-MODEL.md](FAILURE-MODEL.md).
- **The planning scaffolding** — the suggested build order, the goal
  statement — is gone. It was advice to a past self, and what survived of it
  is in [the README](../README.md).

The file was called PLAN.md while it still was one. Everything in it is
done, so it is named for what it now contains: the phases.

---

# Final Architecture

```text
Clients
    │
    ▼
TCP Server
    │
    ▼
Event Loop
    │
    ├── Read Events
    ├── Write Events
    └── Timers
            │
            ▼
      HTTP Parser
            │
            ▼
      HTTP Request
            │
            ▼
          Router
            │
            ▼
       Middleware
            │
            ▼
      Request Handler
            │
            ▼
      HTTP Response
            │
            ▼
      HTTP Encoder
            │
            ▼
       Write Buffer
            │
            ▼
         Client
```

---

# Phase 0 — Project Setup  ✅

## Goal

Create a minimal and clean project foundation.

## Tasks

Create:

```text
php-mini-http-server/
│
├── bin/
├── src/
├── tests/
├── examples/
├── benchmarks/
├── docs/
│
├── README.md
├── PLAN.md          # this file, since renamed to docs/PHASES.md
├── composer.json
├── phpunit.xml
└── phpstan.neon
```

Configure:

* PSR-4 autoloading;
* PHPUnit;
* PHPStan;
* coding style if needed.

## Result

The project can:

```text
composer install

↓

Run tests

↓

Run static analysis
```

## Tests

- [tests/ProjectSetupTest.php](../tests/ProjectSetupTest.php) —
  `testProjectLoads` is the whole phase in one assertion: PSR-4 autoloading
  resolves a class out of `src/`, which is only true when
  `composer install` has run and the mapping is right.
- The other half of this phase is not a test but the toolchain the rest of
  them run under: [phpunit.xml](../phpunit.xml),
  [phpstan.neon](../phpstan.neon) (level 7 over `src`, `bin` and `tests`)
  and [.github/workflows/ci.yml](../.github/workflows/ci.yml), which runs
  `composer test` and `composer analyse` on every push.

---

# Phase 1 — Basic TCP Server  ✅

## Goal

Understand how an HTTP server starts at the TCP level.

Create:

```text
Server Socket

↓

bind()

↓

listen()

↓

accept()
```

Architecture:

```text
Client
   │
   ▼
TCP Connection
   │
   ▼
Server Socket
```

## Tasks

Implement:

```text
Server
ServerConfig
ServerState
```

The server should:

1. create a socket;
2. bind to host and port;
3. listen for connections;
4. accept clients;
5. close cleanly.

## Result

The server can accept a TCP connection.

## Tests

- [tests/Server/ServerTest.php](../tests/Server/ServerTest.php) —
  `testConnectionsPastTheCeilingAreRefusedAtOnce` holds the connection
  limit: past it a client is accepted and closed immediately rather than
  left queued behind a server that will never reach it. The limit exists
  because `select()` cannot wait on a descriptor numbered at or above
  FD_SETSIZE — see
  [DECISIONS.md](DECISIONS.md#a-failed-wait-is-not-a-ready-list).
- `testStartsRunningAndBindsToArbitraryPort` walks socket → bind → listen and
  reads the OS-assigned port back; `testAcceptsPendingClientConnection` is
  accept() answering a real `stream_socket_client`;
  `testStopClosesSocketAndFlipsState` is the clean close.
- Two failure modes get their own tests, because a server that dies quietly
  is worse than one that refuses to start: `testStartThrowsWhenPortIsAlreadyTaken`
  and `testAcceptThrowsWhenServerIsNotStarted`.
- `testAcceptReturnsNullWhenNothingIsPending` is the non-blocking half of
  the promise — accept() returning instead of parking the process is what
  makes Phase 3 possible at all.

---

# Phase 2 — Connection Model  ✅

## Goal

Represent every client connection explicitly.

Create:

```text
Connection
```

A Connection should contain:

```text
Socket

Read Buffer

Write Buffer

State

Metadata
```

Suggested lifecycle:

```text
NEW
 │
 ▼
CONNECTED
 │
 ▼
READING
 │
 ▼
PROCESSING
 │
 ▼
WRITING
 │
 ├──────────────┐
 │              │
 ▼              ▼
READING       CLOSED
```

## Result

The server can manage multiple connection objects.

## Tests

- [tests/Connection/ConnectionTest.php](../tests/Connection/ConnectionTest.php) —
  `testLifecycleTransitionsInOrder` walks NEW → CONNECTED → READING →
  PROCESSING → WRITING → READING → CLOSED, and `testIllegalTransitionThrows`
  proves the states are enforced rather than decorative.
  `testReadBufferAccumulatesAcrossPartialReads` and
  `testWriteBufferAccumulates` cover the two buffers the connection owns;
  `testCloseFreesTheSocket` covers the resource it owns.
- [tests/Server/ServerTest.php](../tests/Server/ServerTest.php) —
  `testTracksConnectionsUntilClosed` is the Definition of Done: the server
  manages several Connection objects at once and forgets them on close.

---

# Phase 3 — Event Loop  ✅

## Goal

Move from blocking I/O to an event-driven architecture.

Conceptually:

```text
while (running) {

    events = waitForEvents();

    foreach (events as event) {

        handle(event);
    }
}
```

The Event Loop should support:

```text
Read Events

Write Events

Timers
```

Initial implementation:

```text
SelectLoop
```

using:

```php
stream_select();
```

## Result

One PHP process can manage multiple client connections.

## Tests

- [tests/EventLoop/SelectLoopTest.php](../tests/EventLoop/SelectLoopTest.php) —
  `testMultipleConnectionsAreHandledByOneLoop` is the Definition of Done:
  one process, one loop, several clients served in the same run.
  `testReadableHandlerFiresWhenDataArrives` and `testWritableHandlerFires`
  cover the two stream event kinds, `testOneShotTimerFiresOnce` and
  `testPeriodicTimerFiresRepeatedlyThenStops` the third,
  `testCancelledTimerNeverFires` and `testStopHaltsTheLoop` the two ways
  work is taken back out of the loop.
- Two later tests guard what a wait can also report, both of them bugs
  before they were tests:
  `testASignalDuringTheWaitDoesNotMakeIdleStreamsLookReady` — a failed
  `select()` leaves its arrays untouched, which reads exactly like "every
  stream is ready" — and
  `testStreamClosedByAnEarlierHandlerIsNotDispatched`, for a stream that a
  handler closed after `select()` reported it. See
  [DECISIONS.md](DECISIONS.md#a-failed-wait-is-not-a-ready-list).

---

# Phase 4 — Read Buffers  ✅

## Goal

Correctly handle partial TCP reads.

TCP:

```text
One HTTP Request
```

does not guarantee:

```text
One read()
```

Example:

```text
GET /hello HTTP/1.1

Host: localhost
```

can arrive as:

```text
GET /hel
```

and later:

```text
lo HTTP/1.1...
```

Therefore:

```text
Socket

↓

Read Data

↓

Append To Buffer

↓

Complete HTTP Request?

├── No → Wait
│
└── Yes → Parse
```

## Tasks

Add:

```text
ReadBuffer
```

to every Connection.

## Result

Partial requests work correctly.

## Tests

- [tests/EventLoop/PartialReadIntegrationTest.php](../tests/EventLoop/PartialReadIntegrationTest.php) —
  `testSplitRequestIsBufferedUntilComplete` is the plan's own example, over
  a real socket: `GET /hel` arrives, the parser sees nothing yet, the rest
  arrives, the parser sees one request. `testTwoRequestsInOneReadAreKeptInTheBuffer`
  is the opposite skew, and the foundation Phase 15 builds on.
- [tests/Connection/ReadBufferTest.php](../tests/Connection/ReadBufferTest.php) —
  the buffer on its own: chunks accumulate, empty chunks change nothing,
  and `consume()` drops exactly the bytes somebody else has claimed.

---

# Phase 5 — HTTP Request Parser  ✅

## Goal

Parse raw HTTP bytes.

Example input:

```http
GET /hello HTTP/1.1
Host: localhost
User-Agent: Browser

```

The parser should produce:

```text
HttpRequest

├── Method
├── URI
├── HTTP Version
├── Headers
└── Body
```

Suggested classes:

```text
HttpRequest
HttpMethod
HttpVersion
Headers
HttpParser
```

## Initial Scope

Support:

```text
GET

POST
```

Then later:

```text
PUT

DELETE

PATCH
```

## Result

Raw TCP data becomes an HTTP Request object.

## Tests

- [tests/Http/Protocol/HttpParserTest.php](../tests/Http/Protocol/HttpParserTest.php) —
  `testParsesGetRequest` and `testParsesPostWithContentLengthBody` are the
  Definition of Done for the initial scope. `testReturnsNullWhileHeadersAreIncomplete`
  and `testReturnsNullWhileBodyIsIncomplete` are the other half of a
  streaming parser: knowing when *not* to answer.
- Everything the parser must refuse has a test, because a parser that
  guesses is a security bug: `testRejectsUnknownMethod`,
  `testRejectsUnsupportedVersion`, `testRejectsShortRequestLine`,
  `testRejectsHeaderLineWithoutColon`, `testRejectsMalformedContentLength`,
  `testRejectsConflictingContentLength`, `testRejectsChunkedTransferEncoding`
  and the header/body size limits.
- [tests/Http/Protocol/HttpParserFuzzTest.php](../tests/Http/Protocol/HttpParserFuzzTest.php) —
  the same contract as a table, fifty-odd rows of it, each declaring which
  of the parser's three answers the input must get: wait, refuse, or one
  request. Writing it found five cases the parser was normalising instead
  of refusing, described in
  [DECISIONS.md](DECISIONS.md#refusing-beats-normalising).
- [tests/Http/Request/HttpRequestTest.php](../tests/Http/Request/HttpRequestTest.php) —
  what the parsed object then offers: path without query string,
  case-insensitive header lookup.

---

# Phase 6 — HTTP Response  ✅

## Goal

Create a proper HTTP response model.

Example:

```text
HTTP/1.1 200 OK

Content-Type: text/plain

Content-Length: 5


Hello
```

Create:

```text
HttpResponse

├── Status
├── Headers
└── Body
```

Add:

```text
ResponseFactory
```

Examples:

```php
Response::text('Hello');

Response::json([
    'status' => 'ok',
]);
```

## Result

The application can create HTTP responses.

## Tests

- [tests/Http/Response/ResponseFactoryTest.php](../tests/Http/Response/ResponseFactoryTest.php) —
  `testTextSetsDefaultsAndLength` and `testJsonEncodesData` are the two
  factories the plan asks for, each proving the plumbing a handler no
  longer has to think about: version, Content-Type, Content-Length.
  `testEmptyWithoutContentLength` marks the deliberate exception — an empty
  response leaves framing to the encoder, which knows which statuses may
  carry a length at all.

---

# Phase 7 — HTTP Response Encoder  ✅

## Goal

Convert HttpResponse into raw HTTP bytes.

Flow:

```text
HttpResponse

↓

Status Line

↓

Headers

↓

Body

↓

TCP Bytes
```

Example:

```text
HTTP/1.1 200 OK

Content-Type: text/plain

Content-Length: 5

Hello
```

## Result

Responses can be sent through a TCP connection.

## Tests

- [tests/Http/Protocol/ResponseEncoderTest.php](../tests/Http/Protocol/ResponseEncoderTest.php) —
  `testEncodesStatusLineHeadersAndBody` is the plan's example byte for byte,
  and `testEncodedResponseParsesBackIntoExpectedShape` closes the loop
  against a real client's reading.
- Framing has its own group, because on a kept-alive connection a missing
  length hangs the client: `testAddsContentLengthWhenMissing`,
  `testEmptyResponseFramesZeroContentLengthForKeepAlive`,
  `testNoContentStatusOmitsContentLength`, and
  `testHandSetContentLengthIsRespectedWhateverItsCasing`, which is there
  because the check used to be case-sensitive and emitted the header twice.
- `testRejectsHeaderValueWithCrLf` is the response-splitting guard.

---

# Phase 8 — Write Buffers  ✅

## Goal

Correctly handle partial writes.

A socket write may write:

```text
Only Part Of Response
```

Therefore:

```text
Response

↓

Write Attempt

↓

Everything Written?

├── Yes → Continue
│
└── No → Save Remaining Data
            │
            ▼
        Write Buffer
            │
            ▼
      Wait For Writable Event
```

Each Connection should have:

```text
WriteBuffer
```

## Result

Large responses work correctly.

## Tests

- [tests/EventLoop/PartialWriteIntegrationTest.php](../tests/EventLoop/PartialWriteIntegrationTest.php) —
  `testLargeResponseIsFullyDeliveredDespitePartialWrites` is the Definition
  of Done: a response far larger than the socket's send buffer arrives
  whole, across as many writable events as it takes.
- [tests/Connection/WriteBufferTest.php](../tests/Connection/WriteBufferTest.php) —
  `testPartialFlushKeepsRemainderForNextAttempt` is the rule the buffer
  exists for, `testFlushOnEmptyBufferReturnsZero` distinguishes "nothing to
  send" from "would block", and `testFlushOnDeadSocketThrows` distinguishes
  both from a socket that has actually failed.

---

# Phase 9 — Router  ✅

## Goal

Route requests to handlers.

Example:

```text
GET /

↓

HomeHandler
```

```text
GET /users

↓

UsersHandler
```

Architecture:

```text
HttpRequest

↓

Router

↓

Route Match

↓

Handler
```

Initial API:

```php
$router->get('/hello', $handler);

$router->post('/users', $handler);
```

## Result

Different URLs execute different handlers.

## Tests

- [tests/Router/RouterTest.php](../tests/Router/RouterTest.php) —
  `testDifferentUrlsExecuteDifferentHandlers` is the Definition of Done and
  `testMethodIsPartOfTheRoute` is the half of it people forget: a route is
  a (method, path) pair, not a path.
- `testUnknownPathThrowsRouteNotFound` and
  `testKnownPathWithDifferentMethodThrowsMethodNotAllowedWithAllowHeader`
  are the two ways a lookup fails, kept apart here so Phase 13 can answer
  404 and 405 correctly.
- `testHeadFallsBackToGetRoute` — HEAD is served from the GET table, since
  it is defined as GET without a body.

---

# Phase 10 — Route Parameters  ✅

## Goal

Support dynamic routes.

Example:

```text
GET /users/42
```

Route:

```text
/users/{id}
```

Result:

```text
id = 42
```

Architecture:

```text
Request

↓

Route Matcher

↓

Extract Parameters

↓

Handler
```

## Result

The Router supports dynamic routes.

## Tests

- [tests/Router/RouterTest.php](../tests/Router/RouterTest.php) —
  `testPatternRouteExtractsParameters` is the plan's `/users/{id}` → `42`,
  and `testMultipleParametersInOneRoute` the general case.
- The rules that stop a pattern matching too much:
  `testPatternDoesNotCrossSegmentBoundaries` (one `{…}` is one segment),
  `testExactRouteWinsOverPattern` (`/users/me` beats `/users/{id}`),
  `testQueryStringDoesNotAffectMatching`.
- `testDuplicateParameterNamesAreRejectedAtRegistration` fails at
  registration rather than at request time — a route that could never work
  should not wait for traffic to say so.

---

# Phase 11 — Middleware  ✅

## Goal

Understand middleware pipelines.

Example:

```text
Request

↓

Logger

↓

Authentication

↓

Router

↓

Handler

↓

Response
```

Interface:

```php
MiddlewareInterface
```

Conceptually:

```php
function process(
    HttpRequest $request,
    RequestHandler $next,
): HttpResponse;
```

Examples:

```text
Logging Middleware

Timing Middleware

Authentication Middleware

Error Handling Middleware
```

## Result

Requests pass through a middleware pipeline.

## Tests

- [tests/Http/Middleware/MiddlewarePipelineTest.php](../tests/Http/Middleware/MiddlewarePipelineTest.php) —
  `testOnionOrderInAndOut` is the Definition of Done: the first middleware
  registered sees the request first and the response last.
  `testMiddlewareCanShortCircuitBeforeTheHandler` and
  `testMiddlewareCanTransformTheResponse` are the two powers that ordering
  buys, and `testEmptyPipelineDelegatesStraightToTheFinalHandler` keeps the
  degenerate case honest.
- [tests/Http/Middleware/LoggingMiddlewareTest.php](../tests/Http/Middleware/LoggingMiddlewareTest.php) —
  one of the plan's example middlewares, wired to the Logger seam.

---

# Phase 12 — Request Handler  ✅

## Goal

Define a simple application interface.

```php
interface RequestHandler
{
    public function handle(
        HttpRequest $request
    ): HttpResponse;
}
```

Example:

```php
final class HelloHandler implements RequestHandler
{
    public function handle(
        HttpRequest $request
    ): HttpResponse {
        return Response::text('Hello');
    }
}
```

## Result

Application code is separated from the server runtime.

## Tests

- [tests/Http/Handler/HelloHandlerTest.php](../tests/Http/Handler/HelloHandlerTest.php) —
  `testHandlerAnswersWithPlainTextHello` is the plan's example class, and
  `testHandlerDoesNotDependOnRequestDetails` is the point of the phase: the
  handler is called with an HttpRequest and returns an HttpResponse, with
  no socket, loop or buffer anywhere in reach.

---

# Phase 13 — Error Handling  ✅

## Goal

Handle failures without crashing the server.

Example:

```text
Handler throws Exception

↓

Error Middleware

↓

HTTP 500
```

Support:

```text
400 Bad Request

404 Not Found

405 Method Not Allowed

500 Internal Server Error
```

## Result

One failed request does not stop the server.

## Tests

- [tests/Http/Middleware/ErrorHandlerMiddlewareTest.php](../tests/Http/Middleware/ErrorHandlerMiddlewareTest.php) —
  one test per status the plan lists: `testMapsMalformedRequestTo400`,
  `testMapsRouteNotFoundTo404`, `testMapsMethodNotAllowedTo405WithAllowHeader`,
  `testMapsBodyTooLargeTo413`, `testMapsHeaderTooLargeTo431`,
  `testMapsUnknownExceptionTo500`. `testPassesSuccessfulResponsesThrough`
  keeps it from being a filter on everything.
- [tests/EventLoop/ServerRoundTripTest.php](../tests/EventLoop/ServerRoundTripTest.php) —
  the same statuses over a real socket: `testUnknownPathReturns404`,
  `testWrongMethodReturns405WithAllowHeader`,
  `testChunkedRequestIsRefusedWith501AndTheConnectionClosed`,
  `testConflictingContentLengthReturns400`.
  `testHandlerExceptionBecomes500AndTheConnectionKeepsServing` is the
  Definition of Done at the level it is claimed: a handler blows up, the
  client gets a 500, and the very next request on the same connection is
  served as if nothing had happened.
- The Definition of Done is not "an error becomes a response" but "the
  server survives it", so three tests attack the loop itself:
  [tests/EventLoop/ConnectionHandlerTest.php](../tests/EventLoop/ConnectionHandlerTest.php) —
  `testMalformedRequestAnswers400ThenClosesTheConnection`,
  `testClientThatVanishesMidWriteClosesOnlyItsOwnConnection` (a client that
  disappears mid-response used to kill the process) and
  `testHandlerThatSmugglesCrLfIntoAHeaderGets500NotADeadServer` (so did a
  response the encoder refused to write).

---

# Phase 14 — Keep-Alive  ✅

## Goal

Reuse TCP connections.

Without Keep-Alive:

```text
Connection

↓

Request

↓

Response

↓

Close
```

With Keep-Alive:

```text
Connection

↓

Request

↓

Response

↓

Request

↓

Response

↓

Request

↓

Response
```

Connection lifecycle:

```text
CONNECTED

↓

READ

↓

PROCESS

↓

WRITE

↓

READ AGAIN
```

## Result

Multiple requests can use one connection.

## Tests

- [tests/EventLoop/ServerRoundTripTest.php](../tests/EventLoop/ServerRoundTripTest.php) —
  `testKeepAliveServesSequentialRequestsOnOneConnection` is the Definition
  of Done: two requests, two responses, one socket.
  `testConnectionCloseIsHonoured` is the other branch of the lifecycle
  diagram, and `testConnectionReportsProcessingWhileTheHandlerRuns` shows
  the connection really passing through PROCESSING on each lap rather than
  sitting in one state forever.
- [tests/Http/Request/HttpRequestTest.php](../tests/Http/Request/HttpRequestTest.php) —
  who decides: `testHttp11KeepsAliveByDefault`,
  `testHttp11ClosesOnExplicitConnectionClose`, `testHttp10ClosesByDefault`,
  `testHttp10KeepsAliveOnExplicitConnectionKeepAlive`.

---

# Phase 15 — HTTP Pipelining  ✅

## Goal

Understand multiple requests in one read buffer.

Example:

```text
Request 1

Request 2

Request 3
```

The server may receive:

```text
All At Once
```

Therefore:

```text
Read Buffer

↓

Parse Request 1

↓

Remaining Data?

├── Yes → Parse Next
│
└── No → Wait
```

## Result

The parser correctly handles multiple buffered requests.

## Tests

- [tests/Http/Protocol/HttpParserTest.php](../tests/Http/Protocol/HttpParserTest.php) —
  `testPipelinedRequestsAreParsedOneByOneFromOneBuffer` is the parser half,
  and `testConsumedBytesExcludesBytesOfTheNextRequest` is what makes it
  possible: the parser reports how much it used, never more.
- [tests/EventLoop/ServerRoundTripTest.php](../tests/EventLoop/ServerRoundTripTest.php) —
  `testPipelinedRequestsGetOrderedResponses` is the Definition of Done over
  a real socket, ordering included: HTTP/1.1 pipelining requires responses
  in request order.
- [tests/EventLoop/ConnectionHandlerTest.php](../tests/EventLoop/ConnectionHandlerTest.php) —
  `testBackpressurePausesReadsThenResumesKeepingTheBatchIntact` is where
  pipelining and Phase 18 meet: requests already buffered when reads pause
  must still be answered, since no new network event will come to wake
  them.

---

# Phase 16 — Timers  ✅

## Goal

Add scheduled work to the Event Loop.

Possible timers:

```text
Connection Timeout

Idle Timeout

Periodic Cleanup
```

Architecture:

```text
Event Loop

├── Read Events
├── Write Events
└── Timers
```

## Result

The server can detect inactive connections.

## Tests

- [tests/EventLoop/SelectLoopTest.php](../tests/EventLoop/SelectLoopTest.php) —
  timers as part of the same wait as the streams:
  `testOneShotTimerFiresOnce`, `testPeriodicTimerFiresRepeatedlyThenStops`,
  `testCancelledTimerNeverFires`.
- [tests/EventLoop/TimerTest.php](../tests/EventLoop/TimerTest.php) —
  the scheduling rule on its own.
  `testPeriodicRescheduleStaysAnchoredToTheOriginalSchedule` is the one
  worth reading: a periodic timer moves to the next slot of its original
  schedule, so a slow loop makes it late once rather than drifting further
  behind on every tick.

---

# Phase 17 — Connection Timeout  ✅

## Goal

Close abandoned clients.

Example:

```text
Client Connects

↓

Sends Nothing

↓

Waits Forever
```

The server should:

```text
Track Last Activity

↓

Timeout Reached?

↓

Close Connection
```

## Result

Dead connections do not remain forever.

## Tests

- [tests/Server/ServerTest.php](../tests/Server/ServerTest.php) —
  `testCloseIdleConnectionsReapsQuietClients` is the plan's scenario
  exactly: a client connects, sends nothing, and is reclaimed.
  `testActiveConnectionsSurviveTheIdleSweep` is the constraint that makes
  it useful rather than merely destructive.
- `testCloseSlowHeaderReadsReapsMidHeaderConnections` and
  `testCloseSlowHeaderReadsLeavesHealthyConnectionsAlone` cover the second
  clock — Slowloris. A client that dribbles one byte at a time keeps the
  idle sweep happy forever, so the header block gets a deadline of its own.
- [tests/EventLoop/RoundTripTimeoutTest.php](../tests/EventLoop/RoundTripTimeoutTest.php) —
  both timeouts against a running server:
  `testSlowHeaderIsReapedByHeaderTimeout` and
  `testRequestBodyThatNeverFinishesIsReapedByHeaderTimeout`.

---

# Phase 18 — Backpressure  ✅

## Goal

Understand slow client problems.

Scenario:

```text
Server

↓

Produces Response

↓

Client Reads Slowly

↓

Write Buffer Grows
```

Potential solution:

```text
Write Buffer Too Large

↓

Pause Reading

↓

Wait For Buffer To Drain

↓

Resume Reading
```

## Result

The server protects itself from slow clients.

## Tests

- [tests/EventLoop/ConnectionHandlerTest.php](../tests/EventLoop/ConnectionHandlerTest.php) —
  `testBackpressurePausesReadsThenResumesKeepingTheBatchIntact` is the
  whole cycle in one test, with the ceiling turned down to 128 bytes: the
  buffer crosses it, reads stop, the buffer drains, reads resume — and the
  requests that were already buffered are still answered.
- [tests/Connection/ConnectionTest.php](../tests/Connection/ConnectionTest.php) —
  `testWriteBufferReportsBackpressureSignal` is the measurement the
  decision is made on.

---

# Phase 19 — Graceful Shutdown  ✅

## Goal

Stop the server safely.

Lifecycle:

```text
RUNNING
    │
    │ SIGTERM
    ▼
DRAINING
    │
    │ Stop Accepting New Connections
    ▼
FINISHING
    │
    │ Existing Connections Closed
    ▼
STOPPED
```

During draining:

```text
❌ Accept New Connections

✅ Finish Active Requests

✅ Flush Responses

✅ Close Connections
```

## Result

The server supports graceful shutdown.

## Tests

- [tests/Server/ServerTest.php](../tests/Server/ServerTest.php) —
  the state machine: `testDrainStopsAcceptingAndFlipsToDraining` (the
  listening socket closes, established connections do not),
  `testIsDrainingReflectsGracefulShutdown`,
  `testFinishClosesRemainingConnectionsAndStops`, and
  `testCloseRestingConnectionsReapsIdleKeepAliveConnections`, which is what
  keeps shutdown from waiting out the idle timeout.
- [tests/EventLoop/ServerRoundTripTest.php](../tests/EventLoop/ServerRoundTripTest.php) —
  what a client sees. `testDrainingServerRefusesNewRequestsOnExistingConnection`
  is the Definition of Done: no new request starts, and the client is told
  why with 503 + close instead of a silent hang up.
  `testPartialRequestInFlightSurvivesDrainAndIsRefused`,
  `testPipelinedRequestsAlreadyInBufferAreRefusedAtDrain` and
  `testPipelinedRequestsAfterDrainAreRefusedInOneBurst` cover the awkward
  moments — bytes that were already on the wire when the signal arrived.

---

# Phase 20 — Metrics  ✅

## Goal

Observe the server.

Track:

```text
Active Connections

Total Requests

Requests Per Second

Bytes Read

Bytes Written

Average Request Duration
```

Optional endpoint:

```text
GET /metrics
```

## Result

Basic observability.

## Tests

- [tests/Metrics/ServerMetricsTest.php](../tests/Metrics/ServerMetricsTest.php) —
  the counters themselves: `testStartsWithZeroCounters`,
  `testRecordsRequestsAndDuration`, `testRecordsBytes`,
  `testRequestsPerSecondUsesUptime`.
- [tests/EventLoop/ConnectionHandlerTest.php](../tests/EventLoop/ConnectionHandlerTest.php) —
  `testMetricsCountRequestsAndBytesOfARealExchange` is the claim that
  matters: the numbers `GET /metrics` prints come from a running server
  actually reporting into them, not from a collector nobody calls.
- [tests/Metrics/LoopMetricsTest.php](../tests/Metrics/LoopMetricsTest.php) —
  the loop's own numbers, added later than this phase but belonging to it.
  `testASlowHandlerShowsUpAsLoopLag` is the one worth reading: a handler
  that blocks for 50ms is 50ms during which no other connection is looked
  at, and `loop_max_lag_ms` is where that becomes visible.
  `testAnIdleLoopReportsItsWaitingAsIdle` is the other side of the split.
- The endpoint itself lives in [bin/server.php](../bin/server.php); `make
  run-server` then `make run-client ARGS=/metrics` prints it.

---

# Phase 21 — Benchmarks  ✅

## Goal

Measure server behavior.

Tools:

```text
wrk

ab

k6
```

Experiments:

```text
1 Connection

10 Connections

100 Connections

1000 Connections
```

Measure:

```text
Requests Per Second

Latency

p50

p95

p99

Memory
```

## Result

The project demonstrates the relationship between:

```text
Event Loop

Connections

Latency

Throughput
```

## Tests

This phase has no unit test, and should not have one: what it measures is
wall-clock behaviour of a whole process under load, which is exactly what a
test suite is built to hold constant.

It is verified by running it. [bin/bench.php](../bin/bench.php) forks a real
server and drives 1, 10, 100 and 1000 keep-alive connections through it,
reporting requests per second, mean latency, p50/p95/p99 and driver memory:

```console
make bench                 # all four levels, 50 requests each
make bench ARGS="100 200"  # one level: 100 connections, 200 requests each
```

Three more scripts under [benchmarks/](../benchmarks/) answer the load
questions concurrency does not: what pipelining is worth, what a connection
costs to set up, and what it costs to hold — that last one by asking the
server its own memory over HTTP, since no other process can read it. They
all measure the same forked server, defined once in
[benchmarks/bootstrap.php](../benchmarks/bootstrap.php), deliberately not
the demo server whose per-request logging would be most of what the numbers
described.

[benchmarks/README.md](../benchmarks/README.md) documents all four and the
external tools (wrk, ab, k6) the plan names. The numbers can be
cross-checked from inside the server through `GET /metrics` while a run is
in flight.

---
