<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Tests\Http\Response;

use PhpMiniHttpServer\Http\Protocol\HttpVersion;
use PhpMiniHttpServer\Http\Response\HttpStatusCode;
use PhpMiniHttpServer\Http\Response\ResponseFactory;
use PHPUnit\Framework\TestCase;

final class ResponseFactoryTest extends TestCase
{
    public function testTextSetsDefaultsAndLength(): void
    {
        $response = ResponseFactory::text('Hello');

        $this->assertSame(HttpVersion::HTTP_1_1, $response->version);
        $this->assertSame(200, $response->statusCode());
        $this->assertSame('OK', $response->reasonPhrase());
        $this->assertSame('text/plain; charset=utf-8', $response->header('Content-Type'));
        $this->assertSame('5', $response->header('Content-Length'));
        $this->assertSame('Hello', $response->body);
        $this->assertSame(5, $response->contentLength());
    }

    public function testTextWithCustomStatus(): void
    {
        $response = ResponseFactory::text('nope', 404);

        $this->assertSame(HttpStatusCode::NOT_FOUND, $response->status);
        $this->assertSame('Not Found', $response->reasonPhrase());
    }

    public function testJsonEncodesData(): void
    {
        $response = ResponseFactory::json(['status' => 'ok']);

        $this->assertSame('application/json; charset=utf-8', $response->header('Content-Type'));
        $this->assertSame('{"status":"ok"}', $response->body);
        $this->assertSame((string) strlen($response->body), $response->header('Content-Length'));
    }

    public function testEmptyWithoutContentLength(): void
    {
        $response = ResponseFactory::empty(HttpStatusCode::NO_CONTENT);

        $this->assertSame(204, $response->statusCode());
        $this->assertSame('', $response->body);
        $this->assertNull($response->header('Content-Length'));
    }
}
