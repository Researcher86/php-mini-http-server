<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Http\Middleware;

use PhpMiniHttpServer\Http\Handler\RequestHandler;
use PhpMiniHttpServer\Http\Request\HttpRequest;
use PhpMiniHttpServer\Http\Response\HttpResponse;
use PhpMiniHttpServer\Support\Logger;

/**
 * Records every request as it enters and leaves the pipeline.
 *
 * The Logger seam keeps the middleware testable (NullLogger / a capturing
 * fake in tests) and swappable for PSR-3 later without touching the server.
 */
final class LoggingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Logger $logger,
    ) {
    }

    public function process(HttpRequest $request, RequestHandler $next): HttpResponse
    {
        $this->logger->log(sprintf(
            '%s %s → start',
            $request->method->value,
            $request->target,
        ));

        $response = $next->handle($request);

        $this->logger->log(sprintf(
            '%s %s → %d',
            $request->method->value,
            $request->target,
            $response->statusCode(),
        ));

        return $response;
    }
}
