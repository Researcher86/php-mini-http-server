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
 * The sequence: one client connects and completes a request on a kept-alive
 * connection, then starts a second one — half of it is on the wire. Then
 * SIGTERM arrives. Then the client sends the rest.
 *
 * What it must NOT get is silence. A drained server refuses the new request
 * with 503 and Connection: close, so the client is told what happened
 * instead of watching a socket die. (A client sitting idle between requests
 * is a different case: it has nothing to finish, so draining simply closes
 * its connection at once.)
 */

require __DIR__ . '/bootstrap.php';

use PhpMiniHttpServer\EventLoop\SelectLoop;
use PhpMiniHttpServer\Http\Middleware\ErrorHandlerMiddleware;
use PhpMiniHttpServer\Http\Middleware\MiddlewarePipeline;
use PhpMiniHttpServer\Http\Response\HttpResponse;
use PhpMiniHttpServer\Http\Response\ResponseFactory;
use PhpMiniHttpServer\Router\Router;
use PhpMiniHttpServer\Server\ConnectionHandler;
use PhpMiniHttpServer\Server\GracefulShutdown;
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

    // SIGTERM → DRAINING: the listening socket closes, established
    // connections are kept, and once the last one is done the server goes
    // FINISHING → STOPPED on its own.
    new GracefulShutdown($loop, $server, $logger)->listenForSignals(SIGTERM);

    ConnectionHandler::acceptOn($loop, $server, $application, logger: $logger);

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

// The next request is in flight when the signal lands: its first line is
// sent, the rest is not.
$split = strlen("GET /hello HTTP/1.1\r\n");
fwrite($client, substr($request, 0, $split));
usleep(100_000);

posix_kill($serverPid, SIGTERM);
usleep(200_000);

fwrite($client, substr($request, $split));
$after = exampleReadResponse($client);

printf("\nAfter the signal, on the SAME connection: %d\n", $after['status']);
printf("%s\n\n%s\n", $after['head'], $after['body']);

fclose($client);
pcntl_waitpid($serverPid, $status);
@unlink($portFile);

echo "The server exited on its own once the last connection was done.\n";
