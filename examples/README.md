# Examples

Five scripts, one question each, answered against a running server rather
than described. They talk raw HTTP/1.1 over a socket — no client class in
between — so what you read in them is what goes on the wire.

Start a server first:

```console
make run-server            # in another terminal
```

Then:

```console
make example EXAMPLE=multiple-clients
make example EXAMPLE=partial-request
make example EXAMPLE=keep-alive
make example EXAMPLE=slow-client
make example EXAMPLE=graceful-shutdown
```

| Script | The question it answers |
| --- | --- |
| `multiple-clients.php` | How many clients can one process with one loop hold open at once? Fifty connections are opened, kept open, and all served. |
| `partial-request.php` | What does the server do with half a request? A request is dribbled out one byte at a time; the server says nothing until the last byte. |
| `keep-alive.php` | Is the second request on a connection actually cheaper? Twenty requests on one connection, timed against twenty connections of their own. |
| `slow-client.php` | Can a client that never reads its responses hurt anyone else? It pipelines until the write buffer passes its ceiling; a healthy client is timed throughout and never notices. |
| `graceful-shutdown.php` | What does SIGTERM do to a client that is already connected? It runs its own server so it can signal it, then asks for one more thing and is told 503 rather than left in silence. |

`graceful-shutdown.php` needs no running server of its own — it forks one.

`bootstrap.php` is shared by all of them: connect, send, and read exactly
one response. That last part is worth reading, because reading *exactly*
one response is the same problem the server solves on its own side of the
socket, and getting it wrong is the classic way to corrupt the next
response on a kept-alive connection.

## The demo scripts in `bin/`

Different purpose: these run the server and a plain client rather than
demonstrating one behaviour.

| Script | What it is | Run it with |
| --- | --- | --- |
| `bin/server.php` | The full server: routes, middleware, timers, signals, `/metrics` | `make run-server` |
| `bin/client.php` | One raw HTTP/1.1 request against a running server | `make run-client ARGS=/hello` |
| `bin/client_and_server.php` | Both ends in one run, on an OS-assigned port | `make run-example` |
