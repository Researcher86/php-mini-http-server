<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Router;

use Closure;
use PhpMiniHttpServer\Http\Handler\RequestHandler;
use PhpMiniHttpServer\Http\Protocol\HttpMethod;
use PhpMiniHttpServer\Http\Request\HttpRequest;
use PhpMiniHttpServer\Http\Response\HttpResponse;

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
final class Router implements RequestHandler
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

    public function patch(string $path, Closure $handler): void
    {
        $this->add(HttpMethod::PATCH, $path, $handler);
    }

    public function options(string $path, Closure $handler): void
    {
        $this->add(HttpMethod::OPTIONS, $path, $handler);
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
     * @throws RouteNotFoundException   when no route matches the request
     * @throws MethodNotAllowedException when the path exists but the method does not
     */
    public function dispatch(HttpRequest $request): HttpResponse
    {
        $method = $request->method->value;

        // HEAD is GET without a response body: it answers from the GET
        // routes, and the layer that builds the wire bytes omits the body.
        if ($request->method === HttpMethod::HEAD) {
            $method = HttpMethod::GET->value;
        }

        $path = $request->path();

        $handler = $this->exact[$method][$path] ?? null;

        if ($handler !== null) {
            return $handler($request, []);
        }

        $matched = $this->matchPattern($method, $path);

        if ($matched !== null) {
            [$route, $params] = $matched;

            return ($route->handler)($request, $params);
        }

        // No route for this method + path. If the path is served under some
        // other method the right answer is 405, not 404.
        $allowed = $this->allowedMethodsFor($path);

        if ($allowed !== []) {
            throw new MethodNotAllowedException($allowed);
        }

        throw new RouteNotFoundException(sprintf(
            'No route for %s %s',
            $request->method->value,
            $path,
        ));
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
     * Router as a RequestHandler: dispatch is the terminal link of any
     * middleware pipeline that wants the router underneath.
     */
    public function handle(HttpRequest $request): HttpResponse
    {
        return $this->dispatch($request);
    }

    /**
     * Whether some route serves $path under $method, exact or pattern.
     */
    private function serves(string $method, string $path): bool
    {
        return isset($this->exact[$method][$path]) || $this->matchPattern($method, $path) !== null;
    }

    /**
     * First matching pattern in registration order. Returns both the route
     * and the extracted parameters in one call, so dispatch() never has to
     * run the regex a second time.
     *
     * @return array{0: Route, 1: array<string, string>}|null
     */
    private function matchPattern(string $method, string $path): ?array
    {
        foreach ($this->patterns[$method] ?? [] as $route) {
            $matches = [];
            preg_match($route->regex, $path, $matches);

            if ($matches === []) {
                continue;
            }

            return [
                $route,
                array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY),
            ];
        }

        return null;
    }

    /**
     * The methods that serve $path under some route (any pattern or exact
     * route), so a 405 can name them in its Allow header. HEAD is implied
     * wherever GET is allowed.
     *
     * @return list<string>
     */
    private function allowedMethodsFor(string $path): array
    {
        $allowed = [];

        foreach (HttpMethod::cases() as $method) {
            if ($method === HttpMethod::HEAD) {
                continue; // HEAD is never registered; it is added below, with GET
            }

            if (!$this->serves($method->value, $path)) {
                continue;
            }

            $allowed[] = $method->value;

            if ($method === HttpMethod::GET) {
                $allowed[] = HttpMethod::HEAD->value;
            }
        }

        return $allowed;
    }
}
