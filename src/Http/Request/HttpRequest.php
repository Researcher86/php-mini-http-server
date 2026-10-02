<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Http\Request;

use PhpMiniHttpServer\Http\Headers\Headers;
use PhpMiniHttpServer\Http\Protocol\HttpMethod;
use PhpMiniHttpServer\Http\Protocol\HttpVersion;

/**
 * A parsed HTTP request.
 *
 * Immutable value object: everything a router, a middleware stack and a
 * handler need to know about one request, with no live wire state.
 */
final readonly class HttpRequest
{
    public function __construct(
        public HttpMethod $method,
        public string $target,
        public HttpVersion $version,
        public Headers $headers,
        public string $body,
    ) {
    }

    /**
     * The path portion of the target, without the query string.
     * GET /users/42?page=2 → /users/42
     */
    public function path(): string
    {
        return explode('?', $this->target, 2)[0];
    }

    /**
     * @return array<int|string, mixed> query string parsed with parse_str()
     */
    public function query(): array
    {
        parse_str(explode('?', $this->target, 2)[1] ?? '', $query);

        return $query;
    }

    public function header(string $name): ?string
    {
        return $this->headers->get($name);
    }

    /**
     * Whether the connection may be reused for the next request.
     *
     * HTTP/1.1 keeps connections alive unless the client explicitly asks
     * for close; HTTP/1.0 closes unless the client explicitly asks for
     * keep-alive. This is the "READ AGAIN" branch of the connection
     * lifecycle.
     */
    public function wantsKeepAlive(): bool
    {
        $connectionTokens = array_map(
            static fn (string $token): string => strtolower(trim($token)),
            explode(',', $this->header('Connection') ?? ''),
        );

        if ($this->version === HttpVersion::HTTP_1_0) {
            return in_array('keep-alive', $connectionTokens, true);
        }

        return !in_array('close', $connectionTokens, true);
    }
}
