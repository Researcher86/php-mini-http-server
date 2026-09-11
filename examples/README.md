# Examples

The runnable examples live in `bin/` — they pair naturally with the composer
and Makefile targets and run in the same Docker container as everything else:

| Script                  | What it demonstrates                                  | Run it with            |
| ----------------------- | ----------------------------------------------------- | ---------------------- |
| `bin/server.php`        | The full server: real routes, logger, timers, signals | `make run-server`      |
| `bin/client.php`        | One raw HTTP/1.1 request against a running server     | `make run-client`      |
| `bin/client_and_server.php` | Server and client in one run, on an OS-assigned port | `make run-example` |

Start `make run-server` in one terminal, then try:

```console
make run-client ARGS=/hello
make run-client ARGS=/users/42
make run-client ARGS=/nope        # the 404 handler answers in plain HTTP
```

`make run-example` needs no server of its own: it forks one, talks to it, and
shuts it down.