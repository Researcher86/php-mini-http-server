<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Handler\RequestHandler;
use App\Http\Request\HttpRequest;
use App\Http\Response\HttpResponse;

/**
 * Composes middleware into a single RequestHandler.
 *
 * Middleware run in registration order, innermost last: the first middleware
 * in the list sees the request first and its post-processing runs last, just
 * like the classic onion. The final handler is the terminal link the request
 * falls through to when every middleware has called $next.
 *
 *     Logger → Authentication → Router → Handler
 *
 * Registration order is the order the diagram reads: [Logger, Authentication].
 */
final class MiddlewarePipeline implements RequestHandler
{
    /** @var list<MiddlewareInterface> */
    private array $middleware = [];

    public function __construct(
        private RequestHandler $finalHandler,
    ) {
    }

    public function add(MiddlewareInterface $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    /**
     * Build the chain once per request (cheap: middleware lists are tiny) and
     * hand the request through it.
     */
    public function handle(HttpRequest $request): HttpResponse
    {
        $next = new DelegateRequestHandler($this->finalHandler->handle(...));

        foreach (array_reverse($this->middleware) as $middleware) {
            $next = new DelegateRequestHandler(
                static function (HttpRequest $inner) use ($middleware, $next): HttpResponse {
                    return $middleware->process($inner, $next);
                },
            );
        }

        return $next->handle($request);
    }

    public function count(): int
    {
        return count($this->middleware);
    }
}