# Architecture

> Internal architecture of `php-mini-http-server`.

How the pieces fit together, in the order a request meets them. Two
companion documents answer different questions: [PHASES.md](PHASES.md) is
how this was built, one capability at a time, with the tests that hold each
step; [DECISIONS.md](DECISIONS.md) is why it turned out this way — what was
rejected, and which bugs changed a design.

---

# Overview

`php-mini-http-server` is an event-driven HTTP server.

The architecture separates:

```text
Network

↓

Event Management

↓

HTTP Protocol

↓

Application Layer
```

The complete request flow:

```text
Client
   │
   ▼
TCP Server
   │
   ▼
Connection
   │
   ▼
Event Loop
   │
   ▼
Read Buffer
   │
   ▼
HTTP Parser
   │
   ▼
HttpRequest
   │
   ▼
Router
   │
   ▼
Middleware Pipeline
   │
   ▼
Request Handler
   │
   ▼
HttpResponse
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

# Network Layer

The Network Layer manages:

```text
Server Socket

Client Sockets

Connections
```

Responsibilities:

```text
Listen

Accept

Read

Write

Close
```

The Network Layer does not know about:

```text
Routes

Middleware

Application Handlers
```

It only manages connections and bytes.

---

# Event Loop

The Event Loop coordinates the server.

```text
Event Loop
    │
    ├── Read Events
    │
    ├── Write Events
    │
    └── Timers
```

Conceptually:

```php
while ($running) {

    $events = waitForEvents();

    foreach ($events as $event) {

        handle($event);
    }
}
```

The Event Loop should remain responsive.

Important rule:

> **Never perform long blocking operations inside the Event Loop.**

---

# Connection

Each client is represented by a Connection.

```text
Connection

├── Socket
├── Read Buffer
├── Write Buffer
├── State
└── Last Activity
```

The Connection owns transport-level state.

It does not contain application logic.

---

# Read Buffer

TCP is a byte stream.

It does not preserve HTTP request boundaries.

Therefore:

```text
read()

≠

one complete HTTP request
```

The server accumulates bytes:

```text
Socket

↓

Read

↓

Read Buffer

↓

Complete Request?

├── No → Wait
│
└── Yes → Parse
```

---

# HTTP Parser

The HTTP Parser transforms:

```text
Raw Bytes
```

into:

```text
HttpRequest
```

The request contains:

```text
Method

URI

Version

Headers

Body
```

The Parser is independent from:

```text
Sockets

Event Loop

Router
```

This allows it to be tested independently.

---

# Router

The Router maps:

```text
Method

+

Path
```

to:

```text
Request Handler
```

Example:

```text
GET /users/42

↓

Route:

GET /users/{id}

↓

Handler

+

Parameters:

id = 42
```

---

# Middleware Pipeline

Middleware wraps application execution.

```text
Request

↓

Middleware A

↓

Middleware B

↓

Handler

↓

Middleware B

↓

Middleware A

↓

Response
```

This allows cross-cutting concerns to remain separate.

Examples:

```text
Logging

Authentication

Timing

Error Handling
```

---

# Request Handler

The Request Handler contains application logic.

```text
HttpRequest

↓

Request Handler

↓

HttpResponse
```

The Handler does not know about:

```text
TCP

Sockets

Event Loop

Buffers
```

This separation allows application logic to remain simple.

---

# HTTP Response

A response contains:

```text
Status

Headers

Body
```

Example:

```text
200

Content-Type: text/plain

Hello
```

---

# HTTP Encoder

The Encoder converts:

```text
HttpResponse
```

into:

```text
HTTP Bytes
```

Example:

```text
HTTP/1.1 200 OK

Content-Type: text/plain

Content-Length: 5

Hello
```

---

# Write Buffer

Writing to a socket may be partial.

Therefore:

```text
Response Bytes

↓

Write Attempt

↓

Everything Written?

├── Yes
│
└── No
      │
      ▼
Write Buffer
      │
      ▼
Wait For Writable Event
```

---

# Connection Lifecycle

```text
NEW
     │   connect()
     ▼
CONNECTED
     │   startReading()
     ▼
READING
     │   startProcessing()
     ▼
PROCESSING
     │   startWriting()
     ▼
WRITING
     │   ├───────────────┐
     │   backToReading() │   close()
     ▼                  ▼
READING               CLOSED
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

# Graceful Shutdown

Server lifecycle:

```text
RUNNING
    │
    │ Shutdown Signal
    ▼
DRAINING
    │
    │ Stop Accepting Connections
    ▼
FINISHING
    │
    │ Flush Responses
    ▼
STOPPED
```

During `DRAINING`:

```text
❌ New Connections

❌ New Requests On Existing Connections   → 503 + close

✅ Requests Already In Flight

✅ Pending Responses
```

A connection that is between requests when draining starts has nothing left
to finish, so it is closed at once rather than waiting out its idle timeout.

---

# Backpressure

A slow client can consume server memory.

```text
Server

↓

Produces Data

↓

Client Reads Slowly

↓

Write Buffer Grows
```

The server can apply flow control:

```text
Write Buffer Too Large

↓

Pause Reads

↓

Buffer Drains

↓

Resume Reads
```

---

# Error Containment

One process serves every client, so an uncaught exception is not one failed
request — it is the end of the server. Failures are therefore caught at three
different distances from the application:

```text
Handler throws
      │
      ▼
Error Middleware ──────────────→ 400 / 404 / 405 / 413 / 431 / 500 / 501
      │
      │  (the pipeline has returned; these are past its reach)
      ▼
Response cannot be encoded ────→ 500, connection survives
      │
      ▼
Socket write fails ────────────→ this connection closes, the loop goes on
```

The middleware protects the application. The runtime protects itself, because
encoding and writing happen after the pipeline is finished — on a later loop
pass, in the write handler's case, where there is nobody left to catch for it.

---

# Design Principles

## Separation Of Layers

```text
Network

↓

Protocol

↓

Application
```

Each layer should have a single responsibility.

---

## Explicit State

Important lifecycle states should be explicit:

```text
ServerState

ConnectionState
```

Avoid hidden lifecycle behavior.

---

## Non-Blocking Event Loop

The Event Loop should:

```text
Handle Event

↓

Return Control
```

Avoid:

```text
Long Blocking Work
```

---

## Small Components

Prefer:

```text
HttpParser

Router

MiddlewarePipeline

RequestHandler
```

over:

```text
GodServer
```

---

# Final Mental Model

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

> **The network layer moves bytes.**

> **The protocol layer understands HTTP.**

> **The application layer produces responses.**
