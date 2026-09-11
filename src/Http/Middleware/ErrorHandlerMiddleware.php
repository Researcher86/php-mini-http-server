<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Handler\RequestHandler;
use App\Http\Protocol\RequestException;
use App\Http\Request\HttpRequest;
use App\Http\Response\HttpResponse;
use App\Http\Response\HttpStatusCode;
use App\Http\Response\ResponseFactory;
use App\Router\MethodNotAllowedException;
use App\Router\RouteNotFoundException;

/**
 * Turns failures into HTTP error responses instead of crashing the process.
 *
 * One failed request must not stop the server. The middleware maps the
 * known failure modes to their status codes and swallows anything else as
 * a generic 500, so an exception thrown deep inside a handler still gets a
 * well-formed response and the connection can move on.
 *
 *     400 Bad Request         malformed HTTP on the wire
 *     404 Not Found           no route for the request
 *     405 Method Not Allowed  path exists, method does not
 *     413 Payload Too Large   declared/delivered body over the limit
 *     431 Header Fields Too Large  header block over the limit
 *     501 Not Implemented     a transfer coding the parser cannot decode
 *     500 Internal Server Error  anything else
 */
final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function process(HttpRequest $request, RequestHandler $next): HttpResponse
    {
        try {
            return $next->handle($request);
        } catch (RouteNotFoundException) {
            return $this->error(HttpStatusCode::NOT_FOUND);
        } catch (MethodNotAllowedException $e) {
            $response = $this->error(HttpStatusCode::METHOD_NOT_ALLOWED);
            $response->headers->set('Allow', implode(', ', $e->allowed));

            return $response;
        } catch (RequestException $e) {
            // Every refusal the protocol layer raises knows its own status.
            return $this->error($e->status);
        } catch (\Throwable) {
            return $this->error(HttpStatusCode::INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * The body is the status' own reason phrase, so "404" and "Not Found"
     * cannot drift apart.
     */
    private function error(HttpStatusCode $status): HttpResponse
    {
        return ResponseFactory::text($status->reasonPhrase() . PHP_EOL, $status);
    }
}