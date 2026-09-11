# Benchmarks

The project ships one ready benchmark and documents the manual tooling from
Phase 21.

## `bin/bench.php` — the built-in benchmark

Forking its own server and keeping it alive, `bench.php` runs keep-alive
client workers and reports requests per second, mean latency, p50/p95/p99
and driver memory for 1, 10, 100 and 1000 concurrent connections:

```console
make bench                 # 1, 10, 100, 1000 connections, 50 requests each
make bench ARGS="100 200"  # one level: 100 connections, 200 requests each
```

The same numbers are reachable through `/metrics` while `make run-server` is
up — active connections, total requests, bytes in/out and average latency —
so a benchmark run can be cross-checked from inside the server.

## External tools (Phase 21)

The event-loop architecture is best measured with real HTTP load generators.
Point any of these at `make run-server`:

```console
# wrk — keep-alive HTTP, connections + threads
wrk -t4 -c100 -d10s http://127.0.0.1:8080/hello

# ab — ApacheBench, concurrency + total requests
ab -n 5000 -c 100 http://127.0.0.1:8080/hello

# k6 — scripted scenarios (k6 needs a small JS script; one virtual user
# looping over GET /hello is enough to reproduce the numbers)
k6 run --vus 100 --duration 10s load.js
```

Each tool reports requests/sec and latency percentiles (p50/p95/p99); watch
`/metrics` for how much of it the process actually buffers vs. hands to the
kernel. The interesting curve to reproduce is: throughput rises with
concurrency until the single-threaded loop saturates, then flattens — and
latency grows only slightly because there is no per-connection thread or
process overhead.