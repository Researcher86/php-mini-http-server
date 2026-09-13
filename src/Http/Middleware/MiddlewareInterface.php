<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Http\Middleware;

use PhpMiniHttpServer\Http\Handler\RequestHandler;
use PhpMiniHttpServer\Http\Request\HttpRequest;
use PhpMiniHttpServer\Http\Response\HttpResponse;

/**
 * One link in a middleware pipeline.
 *
 * process() may do work before and/or after delegating to $next: log the
 * request, time the call, reject unauthenticated requests, or transform
 * the response on its way back out.
 */
interface MiddlewareInterface
{
    public function process(HttpRequest $request, RequestHandler $next): HttpResponse;
}
