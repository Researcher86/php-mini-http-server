<?php

declare(strict_types=1);

use PhpMiniHttpServer\EventLoop\SelectLoop;
use PhpMiniHttpServer\Http\Middleware\ErrorHandlerMiddleware;
use PhpMiniHttpServer\Http\Middleware\MiddlewarePipeline;
use PhpMiniHttpServer\Http\Protocol\HttpParser;
use PhpMiniHttpServer\Http\Protocol\ResponseEncoder;
use PhpMiniHttpServer\Http\Request\HttpRequest;
use PhpMiniHttpServer\Http\Response\HttpResponse;
use PhpMiniHttpServer\Http\Response\ResponseFactory;
use PhpMiniHttpServer\Metrics\ServerMetrics;
use PhpMiniHttpServer\Router\Router;
use PhpMiniHttpServer\Server\ConnectionHandler;
use PhpMiniHttpServer\Server\Server;
use PhpMiniHttpServer\Server\ServerConfig;
use PhpMiniHttpServer\Server\ServerStartException;
use PhpMiniHttpServer\Support\StderrLogger;

require __DIR__ . '/../vendor/autoload.php';

/**
 * One run, both ends: a forked server on an OS-assigned port answers a few
 * real requests from this process, then is asked to shut down.
 *
 * The port file scheme exists because the port is only known after bind(),
 * and the parent cannot read the child's memory. The child writes the port
 * to a per-run temp file; the parent waits for the file and probes the
 * port until the listener genuinely accepts, then makes its requests.
 */

// Computed before forking: getmypid() is the parent's pid here, and it is
// the same path the parent below waits on. A separate per-run file (not a
// fixed path) means a stuck server from an earlier run can never fool this
// run into talking to the wrong process — that server's file has its pid
// and this one's has ours.
$portFile = sys_get_temp_dir() . '/php-mini-http-server-demo-' . getmypid() . '.port';

$serverPid = pcntl_fork();

if ($serverPid === -1) {
    fwrite(STDERR, "fork failed\n");
    exit(1);
}

if ($serverPid === 0) {
    // ── child: the demo server, same pipeline as bin/server.php ──────────
    try {
        $server = new Server(new ServerConfig(host: '127.0.0.1', port: 0));
        $server->start();
    } catch (ServerStartException $e) {
        fwrite(STDERR, 'server: ' . $e->getMessage() . "\n");
        exit(1);
    }

    file_put_contents($portFile, (string) $server->getPort());

    $parser = new HttpParser();
    $encoder = new ResponseEncoder();
    $router = new Router();
    $metrics = new ServerMetrics();
    $logger = new StderrLogger();
    $loop = new SelectLoop();

    $router->get('/hello', static fn (): HttpResponse => ResponseFactory::text('Hello, world!' . PHP_EOL));
    $router->get('/users/{id}', static fn (HttpRequest $r, array $params): HttpResponse => ResponseFactory::json([
        'id' => $params['id'],
    ]));

    $application = new MiddlewarePipeline($router);
    $application->add(new ErrorHandlerMiddleware());

    // SIGTERM (sent by the parent below) starts a graceful drain; a second
    // signal would force the end, but one clean shutdown is all this demo
    // needs — after drain the loop has nothing left to wait for and returns.
    pcntl_async_signals(true);

    $draining = false;

    $onSignal = static function () use (&$draining, $loop, $server, $logger): void {
        $draining = true;
        $logger->log('shutdown draining: finishing active requests');
        $loop->removeReadable($server->socket());
        $server->drain();
    };

    pcntl_signal(SIGTERM, $onSignal);

    $loop->onReadable($server->socket(), static function ($stream) use ($loop, $server, $parser, $encoder, $application, $metrics, $logger): void {
        $connection = $server->accept();

        if ($connection === null) {
            return;
        }

        (new ConnectionHandler(
            loop: $loop,
            server: $server,
            connection: $connection,
            parser: $parser,
            application: $application,
            encoder: $encoder,
            metrics: $metrics,
            logger: $logger,
        ))->start();
    });

    printf("Serving on tcp://127.0.0.1:%d (%s)\n", $server->getPort(), $server->state()->value);

    $loop->run();
    $server->stop();

    @unlink($portFile);
    exit(0);
}

/**
 * Block until the server is accepting connections, instead of guessing at
 * a sleep() long enough to cover startup. Also watches the child: a server
 * that died on startup is reported as that, not as a connection timeout.
 */
$awaitServer = static function (string $portFile, int $serverPid, float $timeoutSeconds = 10.0): int {
    $deadline = microtime(true) + $timeoutSeconds;

    while (microtime(true) < $deadline) {
        if (is_file($portFile)) {
            $port = (int) file_get_contents($portFile);

            // Confirm the listener really accepted before calling the server
            // ready; the port file is written right after bind().
            $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.2);

            if ($probe !== false) {
                fclose($probe);

                return $port;
            }
        }

        if (pcntl_waitpid($serverPid, $status, WNOHANG) === $serverPid) {
            fwrite(STDERR, sprintf("server exited during startup (status %d)\n", pcntl_wexitstatus($status)));
            exit(1);
        }

        usleep(50_000);
    }

    fwrite(STDERR, "server did not start within {$timeoutSeconds}s\n");
    posix_kill($serverPid, SIGKILL);
    pcntl_waitpid($serverPid, $status);

    exit(1);
};

// finally, not a plain sequence: a request that throws (a broken connection,
// a dead server) would otherwise skip the shutdown below and orphan the
// server — which is exactly what makes the NEXT run's awaitServer time out.
try {
    $port = $awaitServer($portFile, $serverPid);

    // A kept-alive request and a Connection: close request, plus a 404, to
    // show all three response kinds. Each is printed verbatim from the wire.
    foreach (['/hello', '/users/42', '/nope'] as $path) {
        $stream = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 5.0);

        if ($stream === false) {
            fwrite(STDERR, sprintf("Cannot connect to server: %s (%d)\n", $errstr, $errno));
            exit(1);
        }

        fwrite($stream, sprintf(
            "GET %s HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n",
            $path,
        ));

        $response = '';

        while (!feof($stream)) {
            $response .= (string) fread($stream, 8192);
        }

        fclose($stream);

        $headEnd = strpos($response, "\r\n\r\n");
        $head = $headEnd === false ? $response : substr($response, 0, $headEnd);
        $body = $headEnd === false ? '' : substr($response, $headEnd + 4);

        printf("\nGET %s\n%s\n\n%s", $path, $head, $body);
    }
} finally {
    posix_kill($serverPid, SIGTERM);
    pcntl_waitpid($serverPid, $status);
    @unlink($portFile);
}
