# PHP Mini HTTP Server

**[🧪 PHP Systems Lab](https://github.com/Researcher86/php-systems-lab)** · Level 6 of 8 · ← [`php-mini-cache`](https://github.com/Researcher86/php-mini-cache) · [`php-mini-database`](https://github.com/Researcher86/php-mini-database) →

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
│   ├── server.php
│   ├── client.php
│   ├── client_and_server.php
│   └── bench.php
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
│   │   ├── Protocol/
│   │   ├── Middleware/
│   │   └── Handler/
│   │
│   ├── Router/
│   │
│   ├── Metrics/
│   │
│   └── Support/
│
├── examples/               runnable experiments, one question each
├── benchmarks/            load questions, one script each
├── tests/
│
├── docs/
│   ├── ARCHITECTURE.md     how the pieces fit together
│   ├── PHASES.md           how it was built, phase by phase
│   ├── DECISIONS.md        why it is the way it is
│   └── FAILURE-MODEL.md    what it does not promise
│
├── README.md
├── Makefile
├── Dockerfile
├── composer.json
├── phpunit.xml
└── phpstan.neon
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

Response body framing is split between two layers:

```text
HttpStatusCode::framesBody()
    └── does this status carry body framing at all? (204 and 304 never do;
        this server emits no Content-Length for either)

ConnectionHandler (method semantics)
    └── HEAD: keep the would-be GET's Content-Length, then drop the body
        bytes — but only for statuses that frame a body
```

Status-level rules live on `HttpStatusCode`, method-level rules at the
request/response boundary. Any newly added bodyless status belongs in
`framesBody()` alongside 204 and 304.

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

> **Note:** graceful shutdown is *cooperative* with respect to handlers. The
> event loop is single-threaded and handlers run synchronously, so a request
> that hangs inside its handler (say, a 500ms blocking call) keeps the loop
> — and therefore the drain — busy until it returns. Drain waits for it; it
> does not preempt it. Keep handlers short and non-blocking and the drain
> stays fast.

---

# Failure Scenarios

One of the main goals of this project is experimentation. Each of these is
something the server is built to survive, and each has a test holding it
to that. What it does **not** survive — and what it never promised in the
first place — is [docs/FAILURE-MODEL.md](docs/FAILURE-MODEL.md).

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

Watch it happen: `make example EXAMPLE=slow-client`.

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

## Client Disappears Mid-Response

```text
Large Response

↓

Client Closes Without Reading

↓

Kernel Answers With RST

↓

Write Fails

↓

Close That Connection Only
```

The interesting word is *only*. A write error is raised on a later loop pass,
outside any middleware, so nothing in the pipeline can catch it — and an
uncaught one ends the loop and every other client with it.

## Unframable Request

```text
Transfer-Encoding: chunked

or

Two Disagreeing Content-Lengths

↓

Where Does This Request End?

↓

Unknowable

↓

501 / 400  +  Close
```

Refusing is the safe answer, not the lazy one: guessing a boundary leaves
the rest of the bytes in the read buffer, where pipelining would serve them
as a second request nobody sent.

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

This repository is designed to be executed and modified. Each of the five
below is a script under [examples/](examples/) — start a server with `make
run-server`, then run one:

```console
make example EXAMPLE=multiple-clients
make example EXAMPLE=partial-request
make example EXAMPLE=keep-alive
make example EXAMPLE=slow-client
make example EXAMPLE=graceful-shutdown
```

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

# How It Was Built

Twenty-two phases, from an empty directory to a benchmarked server, each one
adding a single capability and each one finished:

```text
Project Setup → TCP Server → Connections → Event Loop → Read Buffers
      → HTTP Parser → Response → Encoder → Write Buffers
      → Router → Route Parameters → Middleware → Handlers → Errors
      → Keep-Alive → Pipelining → Timers → Connection Timeout
      → Backpressure → Graceful Shutdown → Metrics → Benchmarks
```

Every phase is recorded in **[docs/PHASES.md](docs/PHASES.md)** with what it
had to achieve and the tests that hold it to that — named down to the
individual test method where one test answers for one line of the plan.

The decisions underneath — what was rejected, which failures were only found
by running the thing, and what is deliberately missing — are in
**[docs/DECISIONS.md](docs/DECISIONS.md)**. How the finished pieces fit
together is **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)**, and the limits
they add up to are **[docs/FAILURE-MODEL.md](docs/FAILURE-MODEL.md)**.

---

# PHP Systems Lab

This project is part of [**PHP Systems Lab**](https://github.com/Researcher86/php-systems-lab) — a collection of small
educational PHP projects that rebuild the mechanisms behind backend
infrastructure in order to understand them. The recommended order:

| Level | Project | Focus |
| ----- | ------- | ----- |
| 1 | 🧠 [`php-memory-lab`](https://github.com/Researcher86/php-memory-lab) | memory, RSS, fork, copy-on-write, `mmap`, shared memory, FFI |
| 2 | ⚡ [`php-concurrency`](https://github.com/Researcher86/php-concurrency) | processes, IPC, concurrency patterns, event loops, Fibers (course in Russian) |
| 3 | ⚙️ [`php-worker-pool`](https://github.com/Researcher86/php-worker-pool) | persistent master/worker pool, supervision, graceful shutdown |
| 4 | 📬 [`php-job-queue`](https://github.com/Researcher86/php-job-queue) | reliable background jobs: delivery leases, ACK, retries, DLQ |
| 5 | 💾 [`php-mini-cache`](https://github.com/Researcher86/php-mini-cache) | event-driven in-memory server: RESP, pipelining, TTL, Pub/Sub |
| **6** | 🌐 **`php-mini-http-server`** (this project) | **event-driven HTTP server: parsing, routing, middleware, keep-alive** |
| 7 | 🗄️ [`php-mini-database`](https://github.com/Researcher86/php-mini-database) | relational engine: pages, B-trees, SQL, transactions, WAL, recovery |
| 8 | 🏗️ [`php-systems-platform`](https://github.com/Researcher86/php-systems-platform) | integration of the components into one backend platform |

These are teaching projects, not libraries: a mechanism travels between them
by being read in one and reimplemented in the next. Levels 1–7 do not depend
on each other as packages. Only [`php-systems-platform`](https://github.com/Researcher86/php-systems-platform) requires the
five components (worker pool, job queue, cache, HTTP server, database)
through Composer and runs them together as one system.

## How this project relates

**[`php-memory-lab`](https://github.com/Researcher86/php-memory-lab)** — a keep-alive server holds a read buffer and a
write buffer per connection, and `php-memory-lab` is where the cost of
holding them is measured. Backpressure is a memory decision before it is a
protocol one.

**[`php-mini-cache`](https://github.com/Researcher86/php-mini-cache)** — the previous level, and the same event-loop
foundation. This project reuses its ideas (not its code) and adds the HTTP
layer on top:

```text
php-mini-cache         TCP server → event loop → client connections → non-blocking I/O
                                                   │
php-mini-http-server                               └→ HTTP protocol → routing → middleware → handlers
```

**[`php-worker-pool`](https://github.com/Researcher86/php-worker-pool)** — the multi-process alternative to one event
loop: where this server interleaves many connections in one process, the pool
spreads requests across persistent workers.

**[`php-systems-platform`](https://github.com/Researcher86/php-systems-platform)** — uses this server as its HTTP front door
(`src/Application/`, on top of `Server`, `SelectLoop`, `HttpParser` and
`ConnectionHandler`).

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

MIT.
