# Benchmarks

Four load questions, one script each. They all measure the same server —
[`bootstrap.php`](bootstrap.php) forks it — which is deliberately *not*
`make run-server`: the demo server logs every request to stderr and stamps
a timing header on it, so measuring it would mostly measure the logger.
Same event loop, same parser, same `ConnectionHandler`, none of the demo's
instrumentation.

Nothing needs to be running first; each script brings its own server up on
an OS-assigned port and shuts it down after.

```console
make bench                                       # concurrency
docker compose exec php php benchmarks/pipelining.php
docker compose exec php php benchmarks/connection-reuse.php
docker compose exec php php benchmarks/memory.php
```

## Concurrency — `bin/bench.php`

How throughput and latency move as simultaneous connections grow. Forks
keep-alive client workers at 1, 10, 100 and 1000 connections and reports
requests/sec, mean latency and p50/p95/p99.

```console
make bench                 # all four levels, 50 requests each
make bench ARGS="100 200"  # one level: 100 connections, 200 requests each
```

The shape to look for: throughput climbs with concurrency until the single
loop saturates, then flattens, while the median latency barely moves and
the tail stretches. There is no per-connection thread or process, so
nothing is being context-switched — what grows is the queue in front of one
loop.

## Pipelining — `benchmarks/pipelining.php`

What removing the round trip is worth. Sends the same total number of
requests in batches of 1, 10, 100 and 1000 without waiting in between, and
reports throughput per batch size. Where the curve flattens is where the
client stops paying for round trips and starts paying for the server's own
per-request cost.

## Connection reuse — `benchmarks/connection-reuse.php`

What a connection costs to set up. The same requests, once over one
kept-alive connection and once with `Connection: close`, with latency
percentiles for both. The difference is accept, socket setup and teardown —
and it shows up most in the tail, because that is the part that
occasionally waits.

## Memory — `benchmarks/memory.php`

What a connection costs to hold. Opens connections in steps up to 500,
leaves them all open, gives each one a request to prove it is live, and
asks the server after every step how much memory it is using — over HTTP,
since the server is a separate process and nothing else can read its
footprint.

This is the number the whole architecture exists for. A thread or process
per connection costs megabytes each; here a connection is one object, two
buffers and two entries in the loop's watch lists.

## Cross-checking from inside

While `make run-server` is up, `GET /metrics` reports what that server
thinks is happening: active connections, total requests, bytes in and out,
and the loop's own busy/idle split with its worst lag. A benchmark's
numbers and the server's own account of them should agree.

## External tools

The event-loop architecture is worth measuring with real HTTP load
generators too. Point any of these at `make run-server`:

```console
wrk -t4 -c100 -d10s http://127.0.0.1:8080/hello
ab -n 5000 -c 100 http://127.0.0.1:8080/hello
k6 run --vus 100 --duration 10s load.js
```

Their numbers will be lower than `make bench`'s, and the reason is the
point made at the top: `make run-server` is logging every request.
