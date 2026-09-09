<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use App\Http\Handler\RequestHandler;
use App\Http\Request\HttpRequest;
use App\Http\Response\HttpResponse;

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