<?php

declare(strict_types=1);

namespace App\Http\Response;

use App\Http\Headers\Headers;
use App\Http\Protocol\HttpVersion;

/**
 * An HTTP response ready to be encoded into wire bytes.
 *
 * Immutable value object: status line parts (version, code, reason),
 * headers and body, nothing more. Building response objects with the
 * right defaults for common payloads is ResponseFactory's job.
 */
final readonly class HttpResponse
{
    public function __construct(
        public HttpVersion $version,
        public HttpStatusCode $status,
        public Headers $headers,
        public string $body,
        /** The GET representation length when this is a bodyless HEAD response. */
        public ?int $representedContentLength = null,
    ) {
    }

    public function statusCode(): int
    {
        return $this->status->value;
    }

    public function reasonPhrase(): string
    {
        return $this->status->reasonPhrase();
    }

    public function contentLength(): int
    {
        return strlen($this->body);
    }

    /**
     * The length used for HTTP body framing. For HEAD this describes the
     * representation that would have been sent for GET, not the empty wire
     * body that HEAD itself carries.
     */
    public function framedContentLength(): int
    {
        return $this->representedContentLength ?? $this->contentLength();
    }

    public function header(string $name): ?string
    {
        return $this->headers->get($name);
    }
}
