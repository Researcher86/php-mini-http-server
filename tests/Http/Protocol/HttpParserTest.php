<?php

declare(strict_types=1);

namespace App\Tests\Http\Protocol;

use App\Http\Protocol\HttpMethod;
use App\Http\Protocol\HttpParser;
use App\Http\Protocol\HttpVersion;
use App\Http\Protocol\MalformedRequestException;
use PHPUnit\Framework\TestCase;

final class HttpParserTest extends TestCase
{
    private HttpParser $parser;

    protected function setUp(): void
    {
        $this->parser = new HttpParser();
    }

    public function testParsesGetRequest(): void
    {
        $raw = "GET /hello HTTP/1.1\r\nHost: localhost\r\nUser-Agent: Browser\r\n\r\n";

        $parsed = $this->parser->parse($raw);

        $this->assertNotNull($parsed);
        $this->assertSame(HttpMethod::GET, $parsed->request->method);
        $this->assertSame('/hello', $parsed->request->target);
        $this->assertSame('/hello', $parsed->request->path());
        $this->assertSame(HttpVersion::HTTP_1_1, $parsed->request->version);
        $this->assertSame('localhost', $parsed->request->header('Host'));
        $this->assertSame('Browser', $parsed->request->header('User-Agent'));
        $this->assertSame('', $parsed->request->body);
        $this->assertSame(strlen($raw), $parsed->consumedBytes);
    }

    public function testReturnsNullWhileHeadersAreIncomplete(): void
    {
        $this->assertNull($this->parser->parse("GET / HTTP/1.1\r\nHost: local"));
        $this->assertNull($this->parser->parse("GET / htt"));
    }

    public function testParsesPostWithContentLengthBody(): void
    {
        $raw = "POST /users HTTP/1.1\r\nContent-Type: application/json\r\nContent-Length: 14\r\n\r\n{\"name\":\"Ann\"}";

        $parsed = $this->parser->parse($raw);

        $this->assertNotNull($parsed);
        $this->assertSame(HttpMethod::POST, $parsed->request->method);
        $this->assertSame('{"name":"Ann"}', $parsed->request->body);
        $this->assertSame(strlen($raw), $parsed->consumedBytes);
    }

    public function testReturnsNullWhileBodyIsIncomplete(): void
    {
        $raw = "POST /users HTTP/1.1\r\nContent-Length: 15\r\n\r\n{\"name\":\"A";

        $this->assertNull($this->parser->parse($raw));
    }

    public function testConsumedBytesExcludesBytesOfTheNextRequest(): void
    {
        $first = "GET /a HTTP/1.1\r\n\r\n";
        $second = "GET /b HTTP/1.1\r\n\r\n";

        $parsed = $this->parser->parse($first . $second);

        $this->assertNotNull($parsed);
        $this->assertSame('/a', $parsed->request->path());
        $this->assertSame(strlen($first), $parsed->consumedBytes);
    }

    public function testParsesQueryStringIntoPathAndQuery(): void
    {
        $raw = "GET /users?page=2&filter=active HTTP/1.1\r\n\r\n";

        $parsed = $this->parser->parse($raw);

        $this->assertNotNull($parsed);
        $this->assertSame('/users', $parsed->request->path());
        $this->assertSame(['page' => '2', 'filter' => 'active'], $parsed->request->query());
    }

    public function testHeaderNamesAreCaseInsensitiveAndDuplicatesAreCommaJoined(): void
    {
        $raw = "GET / HTTP/1.1\r\nX-Custom: one\r\nX-Custom: two\r\n\r\n";

        $parsed = $this->parser->parse($raw);

        $this->assertNotNull($parsed);
        $this->assertSame('one, two', $parsed->request->header('x-custom'));
        $this->assertSame('one, two', $parsed->request->header('X-Custom'));
    }

    public function testRejectsUnknownMethod(): void
    {
        $this->expectException(MalformedRequestException::class);
        $this->parser->parse("CONNECT / HTTP/1.1\r\n\r\n");
    }

    public function testRejectsUnsupportedVersion(): void
    {
        $this->expectException(MalformedRequestException::class);
        $this->parser->parse("GET / HTTP/3.0\r\n\r\n");
    }

    public function testRejectsShortRequestLine(): void
    {
        $this->expectException(MalformedRequestException::class);
        $this->parser->parse("GET /\r\n\r\n");
    }

    public function testRejectsMalformedContentLength(): void
    {
        $this->expectException(MalformedRequestException::class);
        $this->parser->parse("POST / HTTP/1.1\r\nContent-Length: abc\r\n\r\nbody");
    }

    public function testRejectsHeaderLineWithoutColon(): void
    {
        $this->expectException(MalformedRequestException::class);
        $this->parser->parse("GET / HTTP/1.1\r\nHost missing\r\n\r\n");
    }
}