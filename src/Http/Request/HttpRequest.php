<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Http\Headers\Headers;
use App\Http\Protocol\HttpMethod;
use App\Http\Protocol\HttpVersion;

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

    public function uri(): string
    {
        return $this->target;
    }

    /**
     * The path portion of the target, without the query string.
     * GET /users/42?page=2 → /users/42
     */
    public function path(): string
    {
        $question = strpos($this->target, '?');

        if ($question === false) {
            return $this->target;
        }

        return substr($this->target, 0, $question);
    }

    /**
     * @return array<string, string> query string parsed with parse_str()
     */
    public function query(): array
    {
        $question = strpos($this->target, '?');

        if ($question === false) {
            return [];
        }

        $query = [];
        parse_str(substr($this->target, $question + 1), $query);

        return $query;
    }

    public function header(string $name): ?string
    {
        return $this->headers->get($name);
    }
}