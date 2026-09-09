<?php

declare(strict_types=1);

namespace App\Router;

use Closure;
use App\Http\Protocol\HttpMethod;
use App\Http\Request\HttpRequest;
use App\Http\Response\HttpResponse;

/**
 * Maps an HttpRequest to the handler that answers it.
 *
 * Routes are (method, path) pairs; a path may be exact ("/users") or a
 * pattern with {name} parameters ("/users/{id}"). Exact routes win over
 * patterns, so "/users/me" is matched literally even when "/users/{id}"
 * is also registered. The handler receives the request and the extracted
 * parameters, so routing stays a pure lookup: no body parsing, no
 * middleware, no error handling — each of those is a later phase.
 */
final class Router
{
    /** @var array<string, array<string, Closure(HttpRequest, array<string, string>): HttpResponse>> */
    private array $exact = [];

    /** @var array<string, list<Route>> method → ordered pattern routes */
    private array $patterns = [];

    public function get(string $path, Closure $handler): void
    {
        $this->add(HttpMethod::GET, $path, $handler);
    }

    public function post(string $path, Closure $handler): void
    {
        $this->add(HttpMethod::POST, $path, $handler);
    }

    public function put(string $path, Closure $handler): void
    {
        $this->add(HttpMethod::PUT, $path, $handler);
    }

    public function delete(string $path, Closure $handler): void
    {
        $this->add(HttpMethod::DELETE, $path, $handler);
    }

    public function add(HttpMethod $method, string $path, Closure $handler): void
    {
        if (str_contains($path, '{')) {
            $this->patterns[$method->value][] = new Route($path, $handler);
        } else {
            $this->exact[$method->value][$path] = $handler;
        }
    }

    /**
     * @throws RouteNotFoundException when no route matches the request
     */
    public function dispatch(HttpRequest $request): HttpResponse
    {
        $method = $request->method->value;
        $path = $request->path();

        $handler = $this->exact[$method][$path] ?? null;

        if ($handler !== null) {
            return $handler($request, []);
        }

        $route = $this->matchPattern($method, $path);

        if ($route === null) {
            throw new RouteNotFoundException(sprintf(
                'No route for %s %s',
                $request->method->value,
                $path,
            ));
        }

        $params = [];
        preg_match($route->regex, $path, $params);
        $params = array_filter($params, 'is_string', ARRAY_FILTER_USE_KEY);

        return ($route->handler)($request, $params);
    }

    public function count(): int
    {
        $total = 0;

        foreach ($this->exact as $byMethod) {
            $total += count($byMethod);
        }

        foreach ($this->patterns as $byMethod) {
            $total += count($byMethod);
        }

        return $total;
    }

    /**
     * First matching pattern in registration order.
     */
    private function matchPattern(string $method, string $path): ?Route
    {
        foreach ($this->patterns[$method] ?? [] as $route) {
            if (preg_match($route->regex, $path) === 1) {
                return $route;
            }
        }

        return null;
    }
}