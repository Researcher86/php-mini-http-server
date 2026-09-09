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
final class HttpParser
{
    private const string HEADER_TERMINATOR = "\r\n\r\n";

    public function __construct(
        private readonly int $maxHeaderBytes = 8192,
        private readonly int $maxBodyBytes = 1_048_576,
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

    private function contentLength(Headers $headers): int
    {
        $value = $headers->get('content-length');

        if ($value === null) {
            return 0;
        }

        if (!ctype_digit($value)) {
            throw new MalformedRequestException(sprintf(
                'Malformed Content-Length header: %s',
                $value,
            ));
        }

        return (int) $value;
    }
}