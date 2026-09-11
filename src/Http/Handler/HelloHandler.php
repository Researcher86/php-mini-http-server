<?php

declare(strict_types=1);

namespace App\Http\Handler;

use App\Http\Request\HttpRequest;
use App\Http\Response\HttpResponse;
use App\Http\Response\ResponseFactory;

/**
 * The smallest possible application handler: a class that owns one route.
 *
 * Phase 12's point is the boundary itself — handlers live in application
 * code and know nothing about sockets, event loops or write buffers. The
 * runtime calls handle() with an HttpRequest and gets an HttpResponse back;
 * everything in between is the server's business.
 */
final class HelloHandler implements RequestHandler
{
    public function handle(HttpRequest $request): HttpResponse
    {
        return ResponseFactory::text('Hello');
    }
}
