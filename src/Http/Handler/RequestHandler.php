<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Http\Handler;

use PhpMiniHttpServer\Http\Request\HttpRequest;
use PhpMiniHttpServer\Http\Response\HttpResponse;

/**
 * Something that turns one request into one response.
 *
 * The router, the application entry point and each middleware "tail" all
 * implement this contract, which is what lets a middleware call $next and
 * get a response back without knowing whether the next link is another
 * middleware or the terminal handler.
 */
interface RequestHandler
{
    public function handle(HttpRequest $request): HttpResponse;
}
