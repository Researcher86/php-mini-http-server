<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Http\Middleware;

use PhpMiniHttpServer\Http\Handler\RequestHandler;
use PhpMiniHttpServer\Http\Request\HttpRequest;
use PhpMiniHttpServer\Http\Response\HttpResponse;

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

    private ?RequestHandler $chain = null;

    public function __construct(
        private RequestHandler $finalHandler,
    ) {
    }

    public function add(MiddlewareInterface $middleware): void
    {
        $this->middleware[] = $middleware;
        $this->chain = null; // the composed chain must be rebuilt
    }

    /**
     * The chain is composed once and reused: middleware lists are static in
     * practice, and rebuilding closures per request is pure hot-path waste.
     */
    public function handle(HttpRequest $request): HttpResponse
    {
        return $this->buildChain()->handle($request);
    }

    public function count(): int
    {
        return count($this->middleware);
    }

    private function buildChain(): RequestHandler
    {
        if ($this->chain !== null) {
            return $this->chain;
        }

        $next = $this->finalHandler;

        foreach (array_reverse($this->middleware) as $middleware) {
            $next = new DelegateRequestHandler(
                static function (HttpRequest $inner) use ($middleware, $next): HttpResponse {
                    return $middleware->process($inner, $next);
                },
            );
        }

        return $this->chain = $next;
    }
}
