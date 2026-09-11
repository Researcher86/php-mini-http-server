<?php

declare(strict_types=1);

namespace App\Tests\Http\Protocol;

use App\Http\Protocol\BodyTooLargeException;
use App\Http\Protocol\HeaderTooLargeException;
use App\Http\Protocol\HttpMethod;
use App\Http\Protocol\HttpParser;
use App\Http\Protocol\HttpVersion;
use App\Http\Protocol\MalformedRequestException;
use App\Http\Protocol\UnsupportedTransferEncodingException;
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
        $raw = "POST /users HTTP/1.1\r\nHost: t\r\nContent-Type: application/json\r\nContent-Length: 14\r\n\r\n{\"name\":\"Ann\"}";

        $parsed = $this->parser->parse($raw);

        $this->assertNotNull($parsed);
        $this->assertSame(HttpMethod::POST, $parsed->request->method);
        $this->assertSame('{"name":"Ann"}', $parsed->request->body);
        $this->assertSame(strlen($raw), $parsed->consumedBytes);
    }

    public function testReturnsNullWhileBodyIsIncomplete(): void
    {
        $raw = "POST /users HTTP/1.1\r\nHost: t\r\nContent-Length: 15\r\n\r\n{\"name\":\"A";

        $this->assertNull($this->parser->parse($raw));
    }

    public function testConsumedBytesExcludesBytesOfTheNextRequest(): void
    {
        $first = "GET /a HTTP/1.1\r\nHost: t\r\n\r\n";
        $second = "GET /b HTTP/1.1\r\nHost: t\r\n\r\n";

        $parsed = $this->parser->parse($first . $second);

        $this->assertNotNull($parsed);
        $this->assertSame('/a', $parsed->request->path());
        $this->assertSame(strlen($first), $parsed->consumedBytes);
    }

    public function testPipelinedRequestsAreParsedOneByOneFromOneBuffer(): void
    {
        $requests = [
            "GET /one HTTP/1.1\r\nHost: t\r\n\r\n",
            "POST /two HTTP/1.1\r\nHost: t\r\nContent-Length: 3\r\n\r\nabc",
            "GET /three HTTP/1.1\r\nHost: t\r\n\r\n",
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
        $raw = "GET /users?page=2&filter=active HTTP/1.1\r\nHost: t\r\n\r\n";

        $parsed = $this->parser->parse($raw);

        $this->assertNotNull($parsed);
        $this->assertSame('/users', $parsed->request->path());
        $this->assertSame(['page' => '2', 'filter' => 'active'], $parsed->request->query());
    }

    public function testHeaderNamesAreCaseInsensitiveAndDuplicatesAreCommaJoined(): void
    {
        $raw = "GET / HTTP/1.1\r\nHost: t\r\nX-Custom: one\r\nX-Custom: two\r\n\r\n";

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

    public function testAcceptsRepeatedIdenticalContentLength(): void
    {
        $raw = "POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 3\r\nContent-Length: 3\r\n\r\nabc";

        $parsed = $this->parser->parse($raw);

        $this->assertNotNull($parsed);
        $this->assertSame('abc', $parsed->request->body);
    }

    public function testRejectsConflictingContentLength(): void
    {
        $this->expectException(MalformedRequestException::class);
        $this->parser->parse("POST / HTTP/1.1\r\nContent-Length: 3\r\nContent-Length: 5\r\n\r\nabc");
    }

    public function testRejectsChunkedTransferEncoding(): void
    {
        // Without chunked decoding the framing of this request is unknowable:
        // the parser would read a zero-length body and then mistake the chunks
        // for the next pipelined request. 501 is the honest answer.
        $this->expectException(UnsupportedTransferEncodingException::class);

        $this->parser->parse(
            "POST /users HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nHELLO\r\n0\r\n\r\n",
        );
    }

    public function testRejectsTransferEncodingEvenAlongsideContentLength(): void
    {
        // The dangerous combination: a proxy that speaks chunked and a server
        // that reads Content-Length disagree about where the request ends.
        $this->expectException(UnsupportedTransferEncodingException::class);

        $this->parser->parse(
            "POST /users HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: chunked\r\nContent-Length: 5\r\n\r\nHELLO",
        );
    }

    public function testAcceptsTransferEncodingIdentity(): void
    {
        // "identity" means "no transfer coding applied" — nothing to decode,
        // so Content-Length still frames the body.
        $parsed = $this->parser->parse(
            "POST /users HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: identity\r\nContent-Length: 5\r\n\r\nHELLO",
        );

        $this->assertNotNull($parsed);
        $this->assertSame('HELLO', $parsed->request->body);
    }

    public function testRejectsHeaderLineWithoutColon(): void
    {
        $this->expectException(MalformedRequestException::class);
        $this->parser->parse("GET / HTTP/1.1\r\nHost missing\r\n\r\n");
    }

    public function testRejectsHttp11RequestWithoutHost(): void
    {
        // RFC 7230 5.4. Host is what tells a server which site a request is
        // for; without it an HTTP/1.1 request is ambiguous by construction,
        // which is why the spec makes this a MUST rather than a nicety.
        $this->expectException(MalformedRequestException::class);

        $this->parser->parse("GET / HTTP/1.1\r\nAccept: */*\r\n\r\n");
    }

    public function testRejectsHttp11RequestWithAnEmptyHost(): void
    {
        $this->expectException(MalformedRequestException::class);

        $this->parser->parse("GET / HTTP/1.1\r\nHost:\r\n\r\n");
    }

    public function testRejectsTwoHostHeaders(): void
    {
        // The one with teeth: a front-end that routes on the first Host and
        // a server that routes on the second send one request to two
        // different places.
        $this->expectException(MalformedRequestException::class);

        $this->parser->parse("GET / HTTP/1.1\r\nHost: good\r\nHost: evil\r\n\r\n");
    }

    public function testHttp10NeedsNoHost(): void
    {
        // HTTP/1.0 predates virtual hosting, so the same request is fine
        // one version down.
        $parsed = $this->parser->parse("GET / HTTP/1.0\r\nAccept: */*\r\n\r\n");

        $this->assertNotNull($parsed);
        $this->assertNull($parsed->request->header('Host'));
    }

    public function testRejectsWhitespaceBetweenHeaderNameAndColon(): void
    {
        // RFC 7230 3.2.4 makes this a MUST reject, and the reason is
        // desync: a proxy in front that rejects or forwards "Host " as an
        // unknown header while this server trims it into "Host" is two
        // machines disagreeing about the request they just handled.
        $this->expectException(MalformedRequestException::class);

        $this->parser->parse("GET / HTTP/1.1\r\nHost : evil\r\n\r\n");
    }

    public function testRejectsHeaderLineStartingWithWhitespace(): void
    {
        // Obsolete line folding. A continuation that happens to contain a
        // colon would otherwise be read as a header of its own.
        $this->expectException(MalformedRequestException::class);

        $this->parser->parse("GET / HTTP/1.1\r\nHost: x\r\n Injected: yes\r\n\r\n");
    }

    public function testRejectsControlCharactersInHeaderValues(): void
    {
        // A lone CR or a NUL inside a value is not a field value at all,
        // and passing it through leaves it for some later component to
        // interpret as a line ending or a string terminator.
        foreach (["a\rb", "a\nb", "a\0b", "a\x7Fb"] as $value) {
            try {
                $this->parser->parse("GET / HTTP/1.1\r\nHost: {$value}\r\n\r\n");
                $this->fail(sprintf('Expected %s to be rejected.', bin2hex($value)));
            } catch (MalformedRequestException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAcceptsTabsAndHighBytesInHeaderValues(): void
    {
        // The flip side: horizontal tab is legal inside a field value, and
        // so are bytes above 0x7F (obs-text) — rejecting those would break
        // ordinary requests carrying non-ASCII.
        $parsed = $this->parser->parse("GET / HTTP/1.1\r\nHost: x\r\nX-Note: a\tb\xC3\xA9\r\n\r\n");

        $this->assertNotNull($parsed);
        $this->assertSame("a\tb\xC3\xA9", $parsed->request->header('X-Note'));
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
        $small->parse("POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 100\r\n\r\nbody");
    }

    public function testBodyAtTheLimitIsAccepted(): void
    {
        $tiny = new HttpParser(maxBodyBytes: 4);

        $parsed = $tiny->parse("POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 4\r\n\r\ndata");

        $this->assertNotNull($parsed);
        $this->assertSame('data', $parsed->request->body);
    }

    public function testHeaderBlockAtTheLimitIsAccepted(): void
    {
        // The header block below is exactly 32 bytes, so it sits on the
        // limit rather than merely under it — off-by-one is the whole risk
        // a limit check carries, and this is the side that must pass.
        $head = "GET / HTTP/1.1\r\nHost: t\r\nX-A: bc";

        $tiny = new HttpParser(maxHeaderBytes: strlen($head));

        $this->assertNotNull($tiny->parse($head . "\r\n\r\n"));
        $this->assertNull($tiny->parse($head));
    }
}