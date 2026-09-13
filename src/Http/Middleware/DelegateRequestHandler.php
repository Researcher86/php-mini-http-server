<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Http\Middleware;

use Closure;
use PhpMiniHttpServer\Http\Handler\RequestHandler;
use PhpMiniHttpServer\Http\Request\HttpRequest;
use PhpMiniHttpServer\Http\Response\HttpResponse;

/**
 * Adapts a closure into a RequestHandler.
 *
 * The pipeline is built with chained closures; this thin adapter lets each
 * closure present itself to the surrounding middleware as a proper
 * RequestHandler ($next) instead of leaking callables into the interface.
 */
final readonly class DelegateRequestHandler implements RequestHandler
{
    /**
     * @param Closure(HttpRequest): HttpResponse $handler
     */
    public function __construct(
        private Closure $handler,
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        return ($this->handler)($request);
    }
}
