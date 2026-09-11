<?php

declare(strict_types=1);

namespace App\Http\Protocol;

use App\Http\Headers\Headers;
use App\Http\Request\HttpRequest;

/**
 * Turns raw TCP bytes into an HttpRequest.
 *
 * The parser is a pure function of a string and behaves like a state
 * machine with one visible output:
 *
 *     parse($raw) === null                 → incomplete, wait for more bytes
 *     parse($raw) instanceof ParsedRequest → one full request parsed
 *
 * It answers "do I have a complete request?" by looking for the header
 * terminator "\r\n\r\n" and, for body-carrying requests, by comparing the
 * declared Content-Length against the bytes actually available.
 */
final readonly class HttpParser
{
    private const string HEADER_TERMINATOR = "\r\n\r\n";

    public function __construct(
        private int $maxHeaderBytes = 8192,
        private int $maxBodyBytes = 1_048_576,
    ) {
    }

    public function parse(string $raw): ?ParsedRequest
    {
        $headerEnd = strpos($raw, self::HEADER_TERMINATOR);

        if ($headerEnd === false) {
            // No terminator yet. Refuse to buffer an unbounded header block:
            // this is the read-side memory guard against header floods.
            if (strlen($raw) > $this->maxHeaderBytes) {
                throw new HeaderTooLargeException(sprintf(
                    'Header block exceeds %d bytes.',
                    $this->maxHeaderBytes,
                ));
            }

            return null;
        }

        if ($headerEnd > $this->maxHeaderBytes) {
            throw new HeaderTooLargeException(sprintf(
                'Header block exceeds %d bytes.',
                $this->maxHeaderBytes,
            ));
        }

        $headEnd = $headerEnd + strlen(self::HEADER_TERMINATOR);
        $head = substr($raw, 0, $headerEnd);

        $firstLineEnd = strpos($head, "\r\n");
        $requestLine = $firstLineEnd === false ? $head : substr($head, 0, $firstLineEnd);

        [$method, $target, $version] = $this->parseRequestLine($requestLine);

        // A head with no CRLF carries no header lines at all, so the first
        // line is also the last. Otherwise the rest of the head is headers.
        if ($firstLineEnd === false) {
            $headers = new Headers();
        } else {
            $headers = Headers::fromLines(substr($head, $firstLineEnd + 2));
        }

        $this->assertHostIsUsable($headers, $version);
        $this->assertDecodableBody($headers);

        $contentLength = $this->contentLength($headers);

        if ($contentLength > $this->maxBodyBytes) {
            throw new BodyTooLargeException(sprintf(
                'Content-Length %d exceeds the %d-byte body limit.',
                $contentLength,
                $this->maxBodyBytes,
            ));
        }

        $availableBody = strlen($raw) - $headEnd;

        if ($availableBody < $contentLength) {
            return null;
        }

        $body = substr($raw, $headEnd, $contentLength);

        $request = new HttpRequest(
            method: $method,
            target: $target,
            version: $version,
            headers: $headers,
            body: $body,
        );

        return new ParsedRequest($request, $headEnd + $contentLength);
    }

    /**
     * @return array{HttpMethod, string, HttpVersion}
     */
    private function parseRequestLine(string $requestLine): array
    {
        $parts = preg_split('/\s+/', trim($requestLine));

        if ($parts === false || count($parts) !== 3) {
            throw new MalformedRequestException(sprintf('Malformed request line: %s', $requestLine));
        }

        return [
            HttpMethod::fromWire($parts[0]),
            $parts[1],
            HttpVersion::fromWire($parts[2]),
        ];
    }

    /**
     * An HTTP/1.1 request must carry exactly one non-empty Host.
     *
     * RFC 7230 5.4 makes all three of these a MUST, and the one that
     * matters most is the duplicate: a front-end that routes on the first
     * Host and a server that reads the second send a single request to two
     * different places. Host is also what makes a request unambiguous at
     * all once one address serves several sites — HTTP/1.0 predates that,
     * and is left alone.
     *
     * @throws MalformedRequestException
     */
    private function assertHostIsUsable(Headers $headers, HttpVersion $version): void
    {
        if ($version === HttpVersion::HTTP_1_0) {
            return;
        }

        if ($headers->occurrences('host') > 1) {
            throw new MalformedRequestException('Request carries more than one Host header.');
        }

        if (($headers->get('host') ?? '') === '') {
            throw new MalformedRequestException('HTTP/1.1 request without a Host header.');
        }
    }

    /**
     * Refuse any transfer coding other than "identity".
     *
     * Content-Length is the only framing this parser implements. A request
     * that announces another coding (chunked, gzip, …) does not end where
     * Content-Length says it does, so accepting it would leave the encoded
     * payload in the read buffer to be parsed as the next pipelined request.
     *
     * @throws UnsupportedTransferEncodingException
     */
    private function assertDecodableBody(Headers $headers): void
    {
        $encoding = $headers->get('transfer-encoding');

        if ($encoding === null) {
            return;
        }

        foreach (explode(',', $encoding) as $coding) {
            if (strtolower(trim($coding)) !== 'identity') {
                throw new UnsupportedTransferEncodingException(sprintf(
                    'Unsupported Transfer-Encoding: %s',
                    $encoding,
                ));
            }
        }
    }

    private function contentLength(Headers $headers): int
    {
        $value = $headers->get('content-length');

        if ($value === null) {
            return 0;
        }

        // Repeated Content-Length lines are comma-joined by Headers. They
        // only break framing when the values disagree (RFC 7230), so several
        // identical lines are accepted; a single invalid value is the same
        // unrecoverable framing error as two conflicting ones.
        $parts = [];

        foreach (explode(',', $value) as $part) {
            $parts[] = trim($part);
        }

        foreach ($parts as $part) {
            if (!ctype_digit($part)) {
                throw new MalformedRequestException(sprintf(
                    'Malformed Content-Length header: %s',
                    $value,
                ));
            }
        }

        if (count(array_unique($parts)) !== 1) {
            throw new MalformedRequestException(sprintf(
                'Conflicting Content-Length headers: %s',
                $value,
            ));
        }

        return (int) $parts[0];
    }
}
