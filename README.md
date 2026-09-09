# PHP Mini HTTP Server

> An educational event-driven HTTP server written in PHP.

`php-mini-http-server` is a small educational project for exploring how an HTTP server works internally.

The goal is not to replace:

* Nginx;
* Apache;
* Caddy;
* RoadRunner;
* Swoole;
* FrankenPHP.

The goal is to build a simplified server that exposes the fundamental ideas behind:

* TCP servers;
* event loops;
* HTTP parsing;
* client connections;
* non-blocking I/O;
* routing;
* middleware;
* request handling;
* response buffering;
* keep-alive;
* graceful shutdown.

This repository is designed as an:

> **Executable mental model of an HTTP server.**

---

# Why?

From the application side, HTTP often looks simple:

```php
$router->get('/hello', function () {
    return 'Hello';
});
```

But internally, a server must solve many problems:

```text
Accept Connections

↓

Read TCP Data

↓

Buffer Partial Requests

↓

Parse HTTP

↓

Create Request

↓

Route Request

↓

Execute Middleware

↓

Execute Handler

↓

Create Response

↓

Encode HTTP

↓

Handle Partial Writes

↓

Keep Connection Alive
```

This project explores what happens underneath.

---

# Core Idea

The server architecture:

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

The central idea:

> **One event loop can manage many HTTP connections.**

---

# Project Structure

```text
php-mini-http-server/
│
├── bin/
│   └── server.php
│
├── src/
│
│   ├── Server/
│   │
│   ├── EventLoop/
│   │
│   ├── Connection/
│   │
│   ├── Http/
│   │   ├── Request/
│   │   ├── Response/
│   │   ├── Headers/
│   │   └── Protocol/
│   │
│   ├── Routing/
│   │
│   ├── Middleware/
│   │
│   ├── Handler/
│   │
│   ├── Timeout/
│   │
│   └── Metrics/
│
├── examples/
├── benchmarks/
├── tests/
├── docs/
│
├── README.md
├── PLAN.md
├── composer.json
└── phpunit.xml
```

---

# Event-Driven Architecture

Instead of creating:

```text
One Process

Per Connection
```

the server uses:

```text
One Process

↓

One Event Loop

↓

Many Connections
```

Conceptually:

```php
while ($running) {
    $events = $eventLoop->wait();

    foreach ($events as $event) {
        $event->handle();
    }
}
```

The Event Loop reacts to:

```text
New Connection

Client Data

Socket Writable

Timer
```

---

# TCP Server

Everything starts with a TCP server.

```text
Client A ───┐
Client B ───┼────► TCP Server
Client C ───┘
```

The server:

```text
Listen

↓

Accept

↓

Create Connection

↓

Register In Event Loop
```

---

# Connection Lifecycle

Each connection has a lifecycle.

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

With Keep-Alive:

```text
READ

↓

PROCESS

↓

WRITE

↓

READ AGAIN
```

---

# HTTP Request

Raw TCP bytes become an HTTP Request.

```text
Raw TCP Data

↓

HTTP Parser

↓

HttpRequest
```

Example:

```http
GET /hello HTTP/1.1
Host: localhost
```

The server extracts:

```text
Method

URI

HTTP Version

Headers

Body
```

---

# Partial Requests

TCP does not guarantee:

```text
One Request

=

One read()
```

A request may arrive in pieces:

```text
GET /hel
```

Later:

```text
lo HTTP/1.1
```

Therefore:

```text
Read

↓

Append To Buffer

↓

Complete Request?

├── No → Wait For More Data
│
└── Yes → Parse
```

Each connection has its own:

```text
Read Buffer
```

---

# Router

The Router maps requests to handlers.

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

Conceptually:

```php
$router->get('/hello', $handler);

$router->post('/users', $handler);
```

---

# Route Parameters

Dynamic routes are supported conceptually.

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

Flow:

```text
Request

↓

Route Match

↓

Extract Parameters

↓

Handler
```

---

# Middleware

Requests can pass through middleware.

```text
Request
    │
    ▼
Logging Middleware
    │
    ▼
Authentication Middleware
    │
    ▼
Router
    │
    ▼
Handler
    │
    ▼
Response
```

Middleware can:

* inspect requests;
* modify requests;
* stop execution;
* modify responses;
* handle errors.

Examples:

```text
Logging

Timing

Authentication

Error Handling
```

---

# Request Handler

Application code is isolated behind a simple interface.

Conceptually:

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

---

# HTTP Response

A Handler produces:

```text
HttpResponse

├── Status
├── Headers
└── Body
```

Example:

```text
HTTP/1.1 200 OK

Content-Type: text/plain

Content-Length: 5

Hello
```

---

# Write Buffers

TCP writes can also be partial.

```text
Response

↓

Write Attempt

↓

Everything Written?

├── Yes → Continue
│
└── No → Store Remaining Bytes
            │
            ▼
        Write Buffer
```

The Event Loop waits for:

```text
Socket Writable
```

and continues writing.

---

# Keep-Alive

Without Keep-Alive:

```text
Connect

↓

Request

↓

Response

↓

Close
```

With Keep-Alive:

```text
Connect

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

The same TCP connection can process multiple requests.

---

# HTTP Pipelining

Multiple requests may already exist in the Read Buffer.

```text
Request 1

Request 2

Request 3
```

The server must:

```text
Parse Request 1

↓

Process

↓

Check Remaining Buffer

↓

Parse Request 2

↓

Continue
```

---

# Timers

The Event Loop also manages timers.

Possible uses:

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

---

# Connection Timeout

Clients can connect and remain idle forever.

```text
Client

↓

Connect

↓

Do Nothing
```

The server tracks:

```text
Last Activity
```

Then:

```text
Timeout Reached?

↓

Close Connection
```

---

# Backpressure

Slow clients can create memory problems.

```text
Server

↓

Produces Response

↓

Client Reads Slowly

↓

Write Buffer Grows
```

Eventually:

```text
Memory 💥
```

The server can apply backpressure:

```text
Write Buffer Too Large

↓

Pause Reading

↓

Wait For Buffer To Drain

↓

Resume
```

---

# Graceful Shutdown

The server supports graceful shutdown.

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
    ▼
STOPPED
```

During draining:

```text
❌ Accept New Connections

✅ Finish Active Requests

✅ Flush Pending Responses

✅ Close Connections
```

---

# Failure Scenarios

One of the main goals of this project is experimentation.

## Partial Request

```text
Half Request

↓

Wait

↓

Remaining Data

↓

Parse
```

## Slow Client

```text
Slow Reading

↓

Write Buffer Grows

↓

Backpressure
```

## Handler Failure

```text
Exception

↓

Error Middleware

↓

HTTP 500
```

## Idle Client

```text
Connected

↓

Inactive

↓

Timeout

↓

Closed
```

## Server Shutdown

```text
SIGTERM

↓

DRAINING

↓

Finish Requests

↓

STOPPED
```

---

# Experiments

This repository is designed to be executed and modified.

## Multiple Clients

Start multiple clients:

```text
Client A

Client B

Client C
```

Observe:

```text
One Process

↓

One Event Loop

↓

Many Connections
```

---

## Partial Requests

Send an HTTP request in multiple pieces.

Observe:

```text
Read Buffer

↓

Incomplete Request

↓

Wait

↓

Complete Request

↓

Parse
```

---

## Keep-Alive

Send multiple HTTP requests through one TCP connection.

Observe:

```text
Request

↓

Response

↓

Connection Remains Open
```

---

## Slow Client

Create a client that reads responses slowly.

Observe:

```text
Write Buffer

↓

Growth

↓

Backpressure
```

---

## Graceful Shutdown

Start long-running requests.

Send:

```text
SIGTERM
```

Observe:

```text
RUNNING

↓

DRAINING

↓

FINISHING

↓

STOPPED
```

---

# Roadmap

The project is implemented incrementally.

## Phase 1 — TCP Server  ✅

```text
Socket

Listen

Accept
```

## Phase 2 — Connections  ✅

```text
Connection Lifecycle

Read Buffer

Write Buffer
```

## Phase 3 — Event Loop  ✅

```text
Read Events

Write Events

Timers
```

## Phase 4 — HTTP Parsing  ✅

```text
Request Line

Headers

Body
```

## Phase 5 — HTTP Responses

```text
Status

Headers

Body

Encoding
```

## Phase 6 — Routing

```text
Routes

Methods

Parameters
```

## Phase 7 — Middleware

```text
Pipeline

Logging

Errors
```

## Phase 8 — Keep-Alive

```text
Persistent Connections

Multiple Requests
```

## Phase 9 — Timeouts

```text
Idle Connections

Cleanup
```

## Phase 10 — Backpressure

```text
Slow Clients

Write Buffers

Flow Control
```

## Phase 11 — Graceful Shutdown

```text
RUNNING

↓

DRAINING

↓

STOPPED
```

## Phase 12 — Metrics

```text
Connections

Requests

Latency

Throughput
```

## Phase 13 — Benchmarks

```text
RPS

Latency

Memory

Connections
```

---

# Related Projects

This project is part of a collection of educational PHP backend and systems programming projects.

## [PHP Concurrency](https://github.com/Researcher86/php-concurrency)

A practical collection of experiments exploring:

* processes;
* `pcntl_fork`;
* IPC;
* concurrency patterns;
* event loops;
* Fibers;
* asynchronous I/O.

It provides the fundamental building blocks for the rest of the projects.

## [PHP Worker Pool](https://github.com/Researcher86/php-worker-pool)

An educational implementation of a reusable Worker Pool.

It explores:

* Worker processes;
* Worker lifecycle;
* IPC;
* task execution;
* Worker recycling;
* `DRAINING`;
* graceful shutdown.

## PHP Job Queue

An educational background job processing system.

It explores:

* Jobs;
* queues;
* reservation;
* ACK;
* retries;
* backoff;
* visibility timeouts;
* failed jobs.

Repository:

```text
https://github.com/Researcher86/php-job-queue
```

## [PHP Mini Redis](https://github.com/Researcher86/php-mini-redis)

An educational event-driven in-memory database server.

It explores:

* TCP servers;
* event loops;
* client connections;
* non-blocking I/O;
* protocol parsing;
* TTL;
* Pub/Sub.

`php-mini-http-server` builds directly on many of these concepts.

```text
php-mini-redis
        ↓
TCP Server
        ↓
Event Loop
        ↓
Client Connections
        ↓
Non-Blocking I/O
        ↓
php-mini-http-server
        ↓
HTTP Protocol
        ↓
Routing
        ↓
Middleware
        ↓
Request Handling
```

---

# The Ecosystem

```text
                    php-concurrency
                           │
          ┌────────────────┼────────────────┐
          │                │                │
          ▼                ▼                ▼
   php-worker-pool   php-mini-redis     Experiments
          │                │
          ▼                ▼
   php-job-queue   php-mini-http-server
```

Each project explores a different layer of backend and systems programming.

---

# What This Project Is Not

This project is intentionally not trying to become:

* Nginx;
* Apache;
* Caddy;
* RoadRunner;
* Swoole;
* FrankenPHP.

Production HTTP servers include many additional concerns:

```text
TLS

HTTP/2

HTTP/3

WebSockets

Multiprocessing

Load Balancing

Security

Rate Limiting

Compression

Caching

Observability

Plugins

Hot Reload

Production Configuration
```

Those problems are important.

But they can hide the fundamental architecture.

This project focuses on:

```text
TCP

↓

Event Loop

↓

Connections

↓

HTTP

↓

Routing

↓

Middleware

↓

Handlers

↓

Responses
```

---

# Mental Model

The entire server can be reduced to:

```text
CLIENT
   │
   ▼
TCP CONNECTION
   │
   ▼
EVENT LOOP
   │
   ▼
READ BUFFER
   │
   ▼
HTTP PARSER
   │
   ▼
HTTP REQUEST
   │
   ▼
ROUTER
   │
   ▼
MIDDLEWARE
   │
   ▼
HANDLER
   │
   ▼
HTTP RESPONSE
   │
   ▼
HTTP ENCODER
   │
   ▼
WRITE BUFFER
   │
   ▼
CLIENT
```

---

# Final Principle

The purpose of this project is not to build another production-ready HTTP server.

The purpose is to build:

> **An executable mental model of how an HTTP server works internally.**

The project should remain:

> **Small enough to understand.**

> **Real enough to experiment with.**

> **Simple enough to modify.**

> **Complex enough to demonstrate real server engineering problems.**

## License

MIT
