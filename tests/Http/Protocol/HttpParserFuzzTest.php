<?php

declare(strict_types=1);

namespace App\Tests\Http\Protocol;

use App\Http\Protocol\HttpParser;
use App\Http\Protocol\RequestException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A table-driven pass over the HTTP/1.1 edge cases a hand-written parser is
 * most likely to get wrong: truncations, malformed request lines, header
 * grammar, framing headers that disagree with themselves, and input that is
 * not HTTP at all.
 *
 * Each row declares what the stream is and which of the parser's three
 * answers it must get. That split is the whole contract:
 *
 *     null                 incomplete — say nothing, wait for more bytes
 *     RequestException     refuse, loudly, with the status that says why
 *     ParsedRequest        one complete request, and exactly one
 *
 * The middle answer is the one worth defending. Anything *present* must
 * either be a valid request or be refused; a parser that quietly normalises
 * questionable input into something valid-looking is how this server and
 * whatever sits in front of it end up disagreeing about the request they
 * both just handled — which is request smuggling, not a style problem.
 */
final class HttpParserFuzzTest extends TestCase
{
    #[DataProvider('needsMoreBytesProvider')]
    public function testIncompleteInputAsksForMoreBytes(string $raw): void
    {
        $this->assertNull((new HttpParser())->parse($raw));
    }

    #[DataProvider('refusedProvider')]
    public function testMalformedInputIsRefused(string $raw): void
    {
        $this->expectException(RequestException::class);

        (new HttpParser())->parse($raw);
    }

    #[DataProvider('validProvider')]
    public function testValidInputStillParses(string $raw): void
    {
        $this->assertNotNull((new HttpParser())->parse($raw));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function needsMoreBytesProvider(): iterable
    {
        yield 'empty input' => [''];
        yield 'one byte of a method' => ['G'];
        yield 'method only' => ['GET'];
        yield 'method and target' => ['GET /'];
        yield 'request line without CRLF' => ['GET / HTTP/1.1'];
        yield 'request line only' => ["GET / HTTP/1.1\r\n"];
        yield 'headers not terminated' => ["GET / HTTP/1.1\r\nHost: t\r\n"];
        yield 'terminator cut in half' => ["GET / HTTP/1.1\r\nHost: t\r\n\r"];
        yield 'declared body has not started' => ["POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 5\r\n\r\n"];
        yield 'declared body truncated' => ["POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 5\r\n\r\nabc"];
        yield 'declared body one byte short' => ["POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 5\r\n\r\nabcd"];

        // Bare LF is not a line ending in HTTP, so this never terminates —
        // it waits until the header limit refuses it, or the Slowloris
        // sweep reaps the connection. Both are better than guessing.
        yield 'bare LF line endings' => ["GET / HTTP/1.1\nHost: t\n\n"];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedProvider(): iterable
    {
        // ── the request line ────────────────────────────────────────────
        yield 'empty request line' => ["\r\n\r\n"];
        yield 'one token' => ["GET\r\n\r\n"];
        yield 'two tokens' => ["GET /\r\n\r\n"];
        yield 'four tokens' => ["GET / HTTP/1.1 extra\r\nHost: t\r\n\r\n"];
        yield 'unknown method' => ["BREW / HTTP/1.1\r\nHost: t\r\n\r\n"];
        yield 'lowercase method' => ["get / HTTP/1.1\r\nHost: t\r\n\r\n"];
        yield 'not HTTP at all' => ["\x16\x03\x01\x02\x00\r\n\r\n"];
        yield 'wrong protocol name' => ["GET / FTP/1.1\r\nHost: t\r\n\r\n"];
        yield 'version below 1.0' => ["GET / HTTP/0.9\r\nHost: t\r\n\r\n"];
        yield 'version above 1.1' => ["GET / HTTP/2.0\r\nHost: t\r\n\r\n"];
        yield 'version that does not exist' => ["GET / HTTP/1.2\r\nHost: t\r\n\r\n"];

        // ── header grammar ──────────────────────────────────────────────
        yield 'header line without a colon' => ["GET / HTTP/1.1\r\nHost: t\r\nNonsense\r\n\r\n"];
        yield 'header line starting with a colon' => ["GET / HTTP/1.1\r\nHost: t\r\n: orphan\r\n\r\n"];
        yield 'space before the colon' => ["GET / HTTP/1.1\r\nHost : t\r\n\r\n"];
        yield 'tab before the colon' => ["GET / HTTP/1.1\r\nHost\t: t\r\n\r\n"];
        yield 'folded continuation line' => ["GET / HTTP/1.1\r\nHost: t\r\n  folded: yes\r\n\r\n"];
        yield 'header name with a space inside' => ["GET / HTTP/1.1\r\nHost: t\r\nX Note: v\r\n\r\n"];
        yield 'CR inside a header value' => ["GET / HTTP/1.1\r\nHost: t\r\nX-Note: a\rb\r\n\r\n"];
        yield 'NUL inside a header value' => ["GET / HTTP/1.1\r\nHost: t\r\nX-Note: a\0b\r\n\r\n"];
        yield 'DEL inside a header value' => ["GET / HTTP/1.1\r\nHost: t\r\nX-Note: a\x7Fb\r\n\r\n"];

        // ── Host (RFC 7230 5.4) ─────────────────────────────────────────
        yield 'HTTP/1.1 without a Host' => ["GET / HTTP/1.1\r\nAccept: */*\r\n\r\n"];
        yield 'empty Host' => ["GET / HTTP/1.1\r\nHost:\r\n\r\n"];
        yield 'two Host headers' => ["GET / HTTP/1.1\r\nHost: good\r\nHost: evil\r\n\r\n"];

        // ── framing ─────────────────────────────────────────────────────
        yield 'Content-Length is not a number' => ["POST / HTTP/1.1\r\nHost: t\r\nContent-Length: abc\r\n\r\n"];
        yield 'negative Content-Length' => ["POST / HTTP/1.1\r\nHost: t\r\nContent-Length: -1\r\n\r\n"];
        yield 'signed Content-Length' => ["POST / HTTP/1.1\r\nHost: t\r\nContent-Length: +5\r\n\r\nabcde"];
        yield 'hex Content-Length' => ["POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 0x5\r\n\r\nabcde"];
        yield 'empty Content-Length' => ["POST / HTTP/1.1\r\nHost: t\r\nContent-Length:\r\n\r\n"];
        yield 'disagreeing Content-Lengths' => ["POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 3\r\nContent-Length: 5\r\n\r\nabcde"];
        yield 'Content-Length past the body limit' => ["POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 99999999999999999999\r\n\r\n"];
        yield 'chunked body' => ["POST / HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n"];
        yield 'gzip transfer coding' => ["POST / HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: gzip\r\n\r\n"];
        yield 'chunked alongside Content-Length' => ["POST / HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\nContent-Length: 5\r\n\r\nabcde"];
        yield 'header block past the limit' => ['GET / HTTP/1.1' . "\r\n" . 'X: ' . str_repeat('v', 9000) . "\r\n\r\n"];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validProvider(): iterable
    {
        yield 'the smallest complete request' => ["GET / HTTP/1.1\r\nHost: t\r\n\r\n"];
        yield 'HTTP/1.0 needs no Host' => ["GET / HTTP/1.0\r\n\r\n"];
        yield 'body exactly as declared' => ["POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 5\r\n\r\nabcde"];
        yield 'zero-length body' => ["POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 0\r\n\r\n"];
        yield 'repeated identical Content-Length' => ["POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 3\r\nContent-Length: 3\r\n\r\nabc"];
        yield 'identity transfer coding' => ["POST / HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: identity\r\nContent-Length: 3\r\n\r\nabc"];
        yield 'header names in any casing' => ["GET / HTTP/1.1\r\nhOsT: t\r\nCONTENT-length: 0\r\n\r\n"];
        yield 'empty value on a header that is not Host' => ["GET / HTTP/1.1\r\nHost: t\r\nX-Note:\r\n\r\n"];
        yield 'tab and obs-text in a value' => ["GET / HTTP/1.1\r\nHost: t\r\nX-Note: a\tb\xC3\xA9\r\n\r\n"];
        yield 'query string in the target' => ["GET /users?page=2&q=a+b HTTP/1.1\r\nHost: t\r\n\r\n"];
        yield 'trailing bytes of the next request' => ["GET /a HTTP/1.1\r\nHost: t\r\n\r\nGET /b HTT"];
        yield 'body limit reached exactly' => ["POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 3\r\n\r\nabc"];
    }
}
