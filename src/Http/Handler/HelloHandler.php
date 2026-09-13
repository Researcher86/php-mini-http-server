<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Http\Handler;

use PhpMiniHttpServer\Http\Request\HttpRequest;
use PhpMiniHttpServer\Http\Response\HttpResponse;
use PhpMiniHttpServer\Http\Response\ResponseFactory;

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
