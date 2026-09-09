<?php

declare(strict_types=1);

namespace App\Tests\Http\Protocol;

use App\Http\Protocol\BodyTooLargeException;
use App\Http\Protocol\HeaderTooLargeException;
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

    public function testPipelinedRequestsAreParsedOneByOneFromOneBuffer(): void
    {
        $requests = [
            "GET /one HTTP/1.1\r\n\r\n",
            "POST /two HTTP/1.1\r\nContent-Length: 3\r\n\r\nabc",
            "GET /three HTTP/1.1\r\n\r\n",
        ];

        $buffer = implode('', $requests);
        $paths = [];
        $bodies = [];
        $consumed = 0;

        while ($buffer !== '') {
            $parsed = $this->parser->parse($buffer);

            $this->assertNotNull($parsed, 'expected a complete request in the buffer');

            $paths[] = $parsed->request->path();
            $bodies[] = $parsed->request->body;
            $consumed += $parsed->consumedBytes;
            $buffer = substr($buffer, $parsed->consumedBytes);
        }

        $this->assertSame(['/one', '/two', '/three'], $paths);
        $this->assertSame(['', 'abc', ''], $bodies);
        $this->assertSame(strlen(implode('', $requests)), $consumed);
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

    public function testThrowsWhenIncompleteHeadersExceedTheLimit(): void
    {
        $small = new HttpParser(maxHeaderBytes: 32);
        $filler = 'X-Pad: ' . str_repeat('a', 64);

        $this->expectException(HeaderTooLargeException::class);
        $small->parse("GET / HTTP/1.1\r\n$filler");
    }

    public function testThrowsWhenCompleteHeadersExceedTheLimit(): void
    {
        $small = new HttpParser(maxHeaderBytes: 32);
        $filler = 'X-Pad: ' . str_repeat('a', 64);

        $this->expectException(HeaderTooLargeException::class);
        $small->parse("GET / HTTP/1.1\r\n$filler\r\n\r\n");
    }

    public function testThrowsWhenDeclaredBodyExceedsTheLimit(): void
    {
        $small = new HttpParser(maxBodyBytes: 10);

        $this->expectException(BodyTooLargeException::class);
        $small->parse("POST / HTTP/1.1\r\nContent-Length: 100\r\n\r\nbody");
    }

    public function testBodyAtTheLimitIsAccepted(): void
    {
        $tiny = new HttpParser(maxBodyBytes: 4);

        $parsed = $tiny->parse("POST / HTTP/1.1\r\nContent-Length: 4\r\n\r\ndata");

        $this->assertNotNull($parsed);
        $this->assertSame('data', $parsed->request->body);
    }

    public function testHeaderBlockAtTheLimitIsAccepted(): void
    {
        $tiny = new HttpParser(maxHeaderBytes: 32);

        $this->assertNotNull($tiny->parse("GET / HTTP/1.1\r\n\r\n"));
    }
}