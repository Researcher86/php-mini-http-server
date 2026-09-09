# PHP Mini HTTP Server — Implementation Plan

> A step-by-step plan for building an educational event-driven HTTP server in PHP.

The goal of this project is not to build another production-ready HTTP server.

The goal is to understand how an HTTP server works internally by building a small implementation from scratch.

The project should remain:

* small enough to understand;
* easy to run locally;
* easy to modify;
* realistic enough to demonstrate real server engineering problems.

The implementation should be developed incrementally.

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
├── PLAN.md
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

---

# Phase 6 — HTTP Response

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

---

# Phase 7 — HTTP Response Encoder

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

---

# Phase 8 — Write Buffers

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

---

# Phase 9 — Router

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

---

# Phase 10 — Route Parameters

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

---

# Phase 11 — Middleware

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

---

# Phase 12 — Request Handler

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

---

# Phase 13 — Error Handling

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

---

# Phase 14 — Keep-Alive

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

---

# Phase 15 — HTTP Pipelining

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

---

# Phase 16 — Timers

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

---

# Phase 17 — Connection Timeout

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

---

# Phase 18 — Backpressure

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

---

# Phase 19 — Graceful Shutdown

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

---

# Phase 20 — Metrics

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

---

# Phase 21 — Benchmarks

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

---

# Suggested Implementation Order

```text
Phase 0
    ↓
Project Setup
    ↓
Phase 1
    ↓
TCP Server
    ↓
Phase 2
    ↓
Connections
    ↓
Phase 3
    ↓
Event Loop
    ↓
Phase 4
    ↓
Read Buffers
    ↓
Phase 5
    ↓
HTTP Parser
    ↓
Phase 6
    ↓
HTTP Response
    ↓
Phase 7
    ↓
Response Encoder
    ↓
Phase 8
    ↓
Write Buffers
    ↓
Phase 9
    ↓
Router
    ↓
Phase 10
    ↓
Route Parameters
    ↓
Phase 11
    ↓
Middleware
    ↓
Phase 12
    ↓
Handlers
    ↓
Phase 13
    ↓
Error Handling
    ↓
Phase 14
    ↓
Keep-Alive
    ↓
Phase 15
    ↓
HTTP Pipelining
    ↓
Phase 16
    ↓
Timers
    ↓
Phase 17
    ↓
Connection Timeout
    ↓
Phase 18
    ↓
Backpressure
    ↓
Phase 19
    ↓
Graceful Shutdown
    ↓
Phase 20
    ↓
Metrics
    ↓
Phase 21
    ↓
Benchmarks
```

---

# Final Principle

Do not optimize for:

> Feature completeness.

Optimize for:

> Understanding.

Every component should answer:

```text
What problem does this solve?

Why does this component exist?

What happens if we remove it?

What failure scenario does it prevent?
```

The final project should be an:

> **Executable mental model of an HTTP server.**
