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
 * Routes are registered as (method, path) pairs — Phase 9 is exact paths
 * only, Phase 10 adds parameterized segments. Dispatch is a plain lookup,
 * then an invoke: the router does not parse bodies, apply middleware or
 * catch handler errors; each of those is a later phase.
 */
final class Router
{
    /** @var array<string, array<string, Closure(HttpRequest): HttpResponse>> method → path → handler */
    private array $routes = [];

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
        $this->routes[$method->value][$path] = $handler;
    }

    /**
     * @throws RouteNotFoundException when no route matches the request
     */
    public function dispatch(HttpRequest $request): HttpResponse
    {
        $handler = $this->routes[$request->method->value][$request->path()] ?? null;

        if ($handler === null) {
            throw new RouteNotFoundException(sprintf(
                'No route for %s %s',
                $request->method->value,
                $request->path(),
            ));
        }

        return $handler($request);
    }

    public function count(): int
    {
        $total = 0;

        foreach ($this->routes as $byMethod) {
            $total += count($byMethod);
        }

        return $total;
    }
}