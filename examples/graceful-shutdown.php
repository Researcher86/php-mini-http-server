#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One question: what does SIGTERM actually do to the clients that are
 * already connected?
 *
 * This example runs its own server rather than talking to `make
 * run-server`, because the interesting part is what the server does on the
 * way out, and it needs to be signalled at a moment this script chooses.
 *
 * The sequence: one client connects and completes a request, so it is
 * sitting on a kept-alive connection between requests. Then SIGTERM
 * arrives. Then the client asks for one more thing.
 *
 * What it must NOT get is silence. A drained server refuses the new request
 * with 503 and Connection: close, so the client is told what happened
 * instead of watching a socket die.
 */

require __DIR__ . '/bootstrap.php';

use PhpMiniHttpServer\EventLoop\SelectLoop;
use PhpMiniHttpServer\Http\Middleware\ErrorHandlerMiddleware;
use PhpMiniHttpServer\Http\Middleware\MiddlewarePipeline;
use PhpMiniHttpServer\Http\Protocol\HttpParser;
use PhpMiniHttpServer\Http\Protocol\ResponseEncoder;
use PhpMiniHttpServer\Http\Response\HttpResponse;
use PhpMiniHttpServer\Http\Response\ResponseFactory;
use PhpMiniHttpServer\Metrics\ServerMetrics;
use PhpMiniHttpServer\Router\Router;
use PhpMiniHttpServer\Server\ConnectionHandler;
use PhpMiniHttpServer\Server\Server;
use PhpMiniHttpServer\Server\ServerConfig;
use PhpMiniHttpServer\Support\StderrLogger;

$portFile = sys_get_temp_dir() . '/php-mini-http-server-shutdown-' . getmypid() . '.port';
$serverPid = pcntl_fork();

if ($serverPid === -1) {
    fwrite(STDERR, "fork failed\n");

    exit(1);
}

if ($serverPid === 0) {
    $server = new Server(new ServerConfig(host: '127.0.0.1', port: 0));
    $server->start();
    file_put_contents($portFile, (string) $server->getPort());

    $router = new Router();
    $router->get('/hello', static fn (): HttpResponse => ResponseFactory::text('Hello' . PHP_EOL));

    $application = new MiddlewarePipeline($router);
    $application->add(new ErrorHandlerMiddleware());

    $loop = new SelectLoop();
    $logger = new StderrLogger();
    $draining = false;

    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$draining, $server, $loop, $logger): void {
        $draining = true;
        $logger->log('SIGTERM → DRAINING: listening socket closed, established connections kept');
        $loop->removeReadable($server->socket());
        $server->drain();
    });

    // Once drained and empty, there is nothing left to finish.
    $loop->every(0.2, static function () use (&$draining, $server, $loop, $logger): void {
        if ($draining && $server->connectionCount() === 0) {
            $logger->log('every connection finished → FINISHING → STOPPED');
            $server->finish();
            $loop->stop();
        }
    });

    $loop->onReadable($server->socket(), static function () use ($loop, $server, $application, $logger): void {
        $connection = $server->accept();

        if ($connection === null) {
            return;
        }

        (new ConnectionHandler(
            loop: $loop,
            server: $server,
            connection: $connection,
            parser: new HttpParser(),
            application: $application,
            encoder: new ResponseEncoder(),
            metrics: new ServerMetrics(),
            logger: $logger,
        ))->start();
    });

    $loop->run();
    $server->stop();
    @unlink($portFile);

    exit(0);
}

// ── the client side ─────────────────────────────────────────────────────
for ($i = 0; $i < 100 && !is_file($portFile); $i++) {
    usleep(20_000);
}

$port = (int) file_get_contents($portFile);
$client = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 5.0);

if ($client === false) {
    fwrite(STDERR, "the forked server never came up\n");
    posix_kill($serverPid, SIGKILL);

    exit(1);
}

$request = "GET /hello HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n";

fwrite($client, $request);
$before = exampleReadResponse($client);
printf("Before the signal: %d, and the connection stays open:\n%s\n\n", $before['status'], $before['head']);

posix_kill($serverPid, SIGTERM);
usleep(200_000);

fwrite($client, $request);
$after = exampleReadResponse($client);

printf("\nAfter the signal, on the SAME connection: %d\n", $after['status']);
printf("%s\n\n%s\n", $after['head'], $after['body']);

fclose($client);
pcntl_waitpid($serverPid, $status);
@unlink($portFile);

echo "The server exited on its own once the last connection was done.\n";
