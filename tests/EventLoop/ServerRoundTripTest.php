<?php

declare(strict_types=1);

namespace App\Tests\EventLoop;

use App\EventLoop\SelectLoop;
use App\Http\Headers\Headers;
use App\Http\Middleware\ErrorHandlerMiddleware;
use App\Http\Middleware\MiddlewarePipeline;
use App\Http\Protocol\HttpParser;
use App\Http\Protocol\HttpVersion;
use App\Http\Protocol\ResponseEncoder;
use App\Http\Request\HttpRequest;
use App\Http\Response\HttpResponse;
use App\Http\Response\HttpStatusCode;
use App\Http\Response\ResponseFactory;
use App\Metrics\ServerMetrics;
use App\Router\Router;
use App\Server\ConnectionHandler;
use App\Server\Server;
use App\Server\ServerConfig;
use App\Support\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end: a real listening Server driven by the SelectLoop answers real
 * TCP clients, exercising the whole stack — parser, router, middleware,
 * encoder, keep-alive and pipelining — over actual sockets.
 *
 * Both sides live in the same process: the loop multiplexes the server's
 * sockets and a scripted non-blocking client, so there is no forking to
 * leak PHPUnit state and the tests stay deterministic.
 */
final class ServerRoundTripTest extends TestCase
{
    private Server $server;

    private Router $router;

    private MiddlewarePipeline $pipeline;

    protected function setUp(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: 0));
        $this->server->start();

        $this->router = new Router();
        $this->router->get('/hello', static fn (): HttpResponse => ResponseFactory::text('Hello'));
        $this->router->get('/empty', static fn (): HttpResponse => ResponseFactory::empty());
        $this->router->get('/hand', static fn (): HttpResponse => new HttpResponse(
            HttpVersion::HTTP_1_1,
            HttpStatusCode::OK,
            new Headers(),
            'raw',
        ));
        $this->router->get('/users/{id}', static fn (HttpRequest $r, array $params): HttpResponse => ResponseFactory::json([
            'id' => $params['id'],
        ]));

        $this->pipeline = new MiddlewarePipeline($this->router);
        $this->pipeline->add(new ErrorHandlerMiddleware());
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    public function testKeepAliveServesSequentialRequestsOnOneConnection(): void
    {
        $responses = $this->exchange([
            ['write' => "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n"],
            ['read' => true],
            ['write' => "GET /users/7 HTTP/1.1\r\nHost: t\r\n\r\n"],
            ['read' => true],
        ]);

        $this->assertCount(2, $responses);
        $this->assertSame(200, $responses[0]['status']);
        $this->assertSame('Hello', $responses[0]['body']);
        $this->assertSame(200, $responses[1]['status']);
        $this->assertSame('{"id":"7"}', $responses[1]['body']);
    }

    public function testPipelinedRequestsGetOrderedResponses(): void
    {
        $responses = $this->exchange([
            ['write' => "GET /hello HTTP/1.1\r\nHost: t\r\n\r\nGET /users/1 HTTP/1.1\r\nHost: t\r\n\r\n"],
            ['read' => true],
            ['read' => true],
        ]);

        $this->assertCount(2, $responses);
        $this->assertSame(200, $responses[0]['status']);
        $this->assertSame('Hello', $responses[0]['body']);
        $this->assertSame('{"id":"1"}', $responses[1]['body']);
    }

    public function testUnknownPathReturns404(): void
    {
        $responses = $this->exchange([
            ['write' => "GET /nope HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n"],
            ['read' => true],
        ]);

        $this->assertSame(404, $responses[0]['status']);
        $this->assertSame("Not Found\n", $responses[0]['body']);
    }

    public function testWrongMethodReturns405WithAllowHeader(): void
    {
        $responses = $this->exchange([
            ['write' => "DELETE /hello HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n"],
            ['read' => true],
        ]);

        $this->assertSame(405, $responses[0]['status']);
        $this->assertSame('GET, HEAD', $responses[0]['headers']['allow'] ?? null);
    }

    public function testHeadReturnsNoBodyButKeepsContentLength(): void
    {
        $responses = $this->exchange([
            ['write' => "HEAD /hello HTTP/1.1\r\nHost: t\r\n\r\n"],
            ['read' => 'no_body'],
        ]);

        $this->assertSame(200, $responses[0]['status']);
        $this->assertSame('', $responses[0]['body']);
        $this->assertSame('5', $responses[0]['headers']['content-length'] ?? null);
    }

    public function testHeadOnHandBuiltResponseFramesContentLengthBeforeDroppingBody(): void
    {
        // /hand builds its response by hand without ever touching
        // ResponseFactory, so no Content-Length header exists yet. HEAD must
        // still report the length the GET would have sent.
        $responses = $this->exchange([
            ['write' => "HEAD /hand HTTP/1.1\r\nHost: t\r\n\r\n"],
            ['read' => 'no_body'],
        ]);

        $this->assertSame(200, $responses[0]['status']);
        $this->assertSame('', $responses[0]['body']);
        $this->assertSame('3', $responses[0]['headers']['content-length'] ?? null);
    }

    public function testEmptyResponseCarriesContentLengthZeroOnKeepAlive(): void
    {
        // An empty 200 must still frame itself (Content-Length: 0) or a
        // keep-alive client would wait for a body that never arrives.
        $responses = $this->exchange([
            ['write' => "GET /empty HTTP/1.1\r\nHost: t\r\n\r\n"],
            ['read' => true],
        ]);

        $this->assertSame(200, $responses[0]['status']);
        $this->assertSame('', $responses[0]['body']);
        $this->assertSame('0', $responses[0]['headers']['content-length'] ?? null);
    }

    public function testConnectionCloseIsHonoured(): void
    {
        $responses = $this->exchange([
            ['write' => "GET /hello HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n"],
            ['read' => true],
        ]);

        $this->assertSame('close', $responses[0]['headers']['connection'] ?? null);

        // The server must tear the socket down after the response.
        for ($i = 0; $i < 100 && $this->server->connectionCount() > 0; $i++) {
            usleep(1000);
        }

        $this->assertSame(0, $this->server->connectionCount());
    }

    public function testDrainingServerRefusesNewRequestsOnExistingConnection(): void
    {
        $responses = $this->exchange([
            ['write' => "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n"],
            ['read' => true],
            ['drain' => true],
            ['write' => "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n"],
            ['read' => true],
        ]);

        // The request in flight when the drain started is served normally.
        $this->assertCount(2, $responses);
        $this->assertSame(200, $responses[0]['status']);

        // But the next keep-alive request must be refused, told why, and the
        // connection torn down right after the refusal flushes.
        $this->assertSame(503, $responses[1]['status']);
        $this->assertSame("Service Unavailable\n", $responses[1]['body']);
        $this->assertSame('close', $responses[1]['headers']['connection'] ?? null);

        for ($i = 0; $i < 100 && $this->server->connectionCount() > 0; $i++) {
            usleep(1000);
        }

        $this->assertSame(0, $this->server->connectionCount());
    }

    /**
     * Run a scripted exchange against the real server: alternating writes of
     * raw bytes and reads of exactly one response each, all on one client
     * socket, multiplexed by the same loop that runs the server.
     *
     * A read step is ['read' => true] when the response carries a body sized
     * by Content-Length, or ['read' => 'no_body'] for HEAD-style responses.
     * A ['drain' => true] step triggers a graceful shutdown mid-exchange.
     *
     * @param list<array{write?: string, read?: bool|string, drain?: bool}> $script
     *
     * @return list<array{status: int, headers: array<string, string>, body: string}>
     */
    private function exchange(array $script): array
    {
        $loop = new SelectLoop();
        $parser = new HttpParser();
        $encoder = new ResponseEncoder();
        $server = $this->server;
        $pipeline = $this->pipeline;

        $loop->onReadable($server->socket(), static function ($stream) use ($loop, $server, $parser, $encoder, $pipeline): void {
            $connection = $server->accept();

            if ($connection === null) {
                return;
            }

            (new ConnectionHandler(
                loop: $loop,
                server: $server,
                connection: $connection,
                parser: $parser,
                application: $pipeline,
                encoder: $encoder,
                metrics: new ServerMetrics(),
                logger: new NullLogger(),
            ))->start();
        });

        $client = stream_socket_client("tcp://127.0.0.1:{$server->getPort()}");
        $this->assertIsResource($client);
        stream_set_blocking($client, false);

        /** @var array{script: list<array{write?: string, read?: bool|string, drain?: bool}>, step: int, toWrite: string, readBuf: string, responses: list<array{status: int, headers: array<string, string>, body: string}>} $state */
        $state = [
            'script' => $script,
            'step' => 0,
            'toWrite' => '',
            'readBuf' => '',
            'responses' => [],
        ];

        $advance = null;

        $advance = static function (mixed $stream) use ($loop, &$state, $server): void {
            while (true) {
                if ($state['step'] >= count($state['script'])) {
                    $loop->removeWritable($stream);
                    $loop->removeReadable($stream);
                    $loop->stop();
                    return;
                }

                $step = $state['script'][$state['step']];

                if (isset($step['drain'])) {
                    $server->drain();
                    $state['step']++;
                    continue;
                }

                if (isset($step['write'])) {
                    if ($state['toWrite'] === '') {
                        $state['toWrite'] = $step['write'];
                        $state['step']++;
                    }

                    $written = fwrite($stream, $state['toWrite']);

                    if ($written !== false && $written > 0) {
                        $state['toWrite'] = substr($state['toWrite'], $written);
                    }

                    if ($state['toWrite'] !== '') {
                        return; // wait for the next writable event
                    }

                    continue; // finished writing this step — move on
                }

                $parsed = self::parseResponse($state['readBuf'], ($step['read'] ?? true) !== 'no_body');

                if ($parsed === null) {
                    return; // wait for more bytes
                }

                $state['responses'][] = $parsed;
                $state['step']++;
            }
        };

        $loop->onWritable($client, static function ($stream) use ($advance): void {
            $advance($stream);
        });

        $loop->onReadable($client, static function ($stream) use (&$state, $advance): void {
            $chunk = fread($stream, 8192);

            if ($chunk !== false && $chunk !== '') {
                $state['readBuf'] .= $chunk;
            }

            $advance($stream);
        });

        // Safety net: a stalled exchange must not hang the suite.
        $loop->addTimer(5.0, static fn () => $loop->stop());

        $loop->run();

        fclose($client);

        return $state['responses'];
    }

    /**
     * Extract one complete response from the head of $buffer, consuming it
     * so the next pipelined response can be parsed next.
     *
     * @param string $buffer
     *
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    private static function parseResponse(string &$buffer, bool $expectBody): ?array
    {
        $split = strpos($buffer, "\r\n\r\n");

        if ($split === false) {
            return null;
        }

        $headLines = explode("\r\n", substr($buffer, 0, $split));
        $status = (int) explode(' ', $headLines[0])[1];

        $headers = [];

        foreach (array_slice($headLines, 1) as $line) {
            $colon = strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }

        $length = $expectBody ? (int) ($headers['content-length'] ?? 0) : 0;

        if (strlen($buffer) < $split + 4 + $length) {
            return null;
        }

        $body = substr($buffer, $split + 4, $length);
        $buffer = substr($buffer, $split + 4 + $length);

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}