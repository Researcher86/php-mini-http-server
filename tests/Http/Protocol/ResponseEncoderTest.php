<?php

declare(strict_types=1);

namespace App\Tests\Http\Protocol;

use App\Http\Headers\Headers;
use App\Http\Protocol\HttpVersion;
use App\Http\Protocol\ResponseEncoder;
use App\Http\Response\HttpResponse;
use App\Http\Response\HttpStatusCode;
use App\Http\Response\ResponseFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ResponseEncoderTest extends TestCase
{
    private ResponseEncoder $encoder;

    protected function setUp(): void
    {
        $this->encoder = new ResponseEncoder();
    }

    public function testRejectsHeaderValueWithCrLf(): void
    {
        $headers = new Headers();
        $headers->set('X-Injected', "value\r\nX-Evil: 1");

        $response = new HttpResponse(
            version: HttpVersion::HTTP_1_1,
            status: HttpStatusCode::OK,
            headers: $headers,
            body: 'x',
        );

        $this->expectException(RuntimeException::class);
        $this->encoder->encode($response);
    }

    public function testEncodesStatusLineHeadersAndBody(): void
    {
        $raw = $this->encoder->encode(ResponseFactory::text('Hello'));

        $expected = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "Content-Length: 5\r\n"
            . "\r\n"
            . 'Hello';

        $this->assertSame($expected, $raw);
    }

    public function testUsesGivenStatusCodeAndReason(): void
    {
        $raw = $this->encoder->encode(ResponseFactory::json(['e' => true], 404));

        $this->assertStringStartsWith("HTTP/1.1 404 Not Found\r\n", $raw);
    }

    public function testAddsContentLengthWhenMissing(): void
    {
        $headers = new Headers();
        $headers->set('X-Custom', 'yes');

        $response = new HttpResponse(
            version: HttpVersion::HTTP_1_1,
            status: HttpStatusCode::OK,
            headers: $headers,
            body: 'abc',
        );

        $raw = $this->encoder->encode($response);

        $this->assertStringContainsString("Content-Length: 3\r\n", $raw);
        $this->assertSame('abc', substr($raw, strlen($raw) - 3));
    }

    public function testEmptyResponseFramesZeroContentLengthForKeepAlive(): void
    {
        $raw = $this->encoder->encode(ResponseFactory::empty());

        $this->assertSame("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n", $raw);
    }

    public function testNoContentStatusOmitsContentLength(): void
    {
        $raw = $this->encoder->encode(ResponseFactory::empty(HttpStatusCode::NO_CONTENT));

        $this->assertSame("HTTP/1.1 204 No Content\r\n\r\n", $raw);
    }

    public function testNotModifiedStatusOmitsContentLength(): void
    {
        $raw = $this->encoder->encode(ResponseFactory::empty(HttpStatusCode::NOT_MODIFIED));

        $this->assertSame("HTTP/1.1 304 Not Modified\r\n\r\n", $raw);
    }

    public function testHandSetContentLengthIsRespectedWhateverItsCasing(): void
    {
        // Header names are case-insensitive on the wire, so a handler that
        // writes "content-length" has already framed the body. Adding our
        // own canonical copy would put two Content-Length lines in one
        // response — the framing error RFC 7230 tells recipients to reject.
        $headers = new Headers();
        $headers->set('content-length', '5');

        $encoded = (new ResponseEncoder())->encode(
            new HttpResponse(HttpVersion::HTTP_1_1, HttpStatusCode::OK, $headers, 'Hello'),
        );

        $this->assertSame(1, substr_count(strtolower($encoded), 'content-length:'));
        $this->assertStringContainsString("content-length: 5\r\n", $encoded);
    }

    public function testHandSetContentLengthIsRespected(): void
    {
        $headers = new Headers();
        $headers->set('Content-Length', '0');

        $response = new HttpResponse(
            version: HttpVersion::HTTP_1_1,
            status: HttpStatusCode::OK,
            headers: $headers,
            body: '',
        );

        $raw = $this->encoder->encode($response);

        $this->assertSame("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n", $raw);
    }

    public function testEncodedResponseParsesBackIntoExpectedShape(): void
    {
        $raw = $this->encoder->encode(ResponseFactory::text('Hello'));

        $this->assertStringStartsWith("HTTP/1.1 200 OK\r\n", $raw);
        $this->assertStringContainsString("Content-Type: text/plain; charset=utf-8\r\n", $raw);
        $this->assertStringContainsString("Content-Length: 5\r\n", $raw);
        $this->assertStringEndsWith("\r\n\r\nHello", $raw);
    }
}
