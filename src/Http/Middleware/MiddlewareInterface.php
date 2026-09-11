<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Handler\RequestHandler;
use App\Http\Request\HttpRequest;
use App\Http\Response\HttpResponse;

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
