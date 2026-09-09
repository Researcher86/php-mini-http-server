<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Handler\RequestHandler;
use App\Http\Protocol\BodyTooLargeException;
use App\Http\Protocol\HeaderTooLargeException;
use App\Http\Protocol\MalformedRequestException;
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
 *     500 Internal Server Error  anything else
 */
final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function process(HttpRequest $request, RequestHandler $next): HttpResponse
    {
        try {
            return $next->handle($request);
        } catch (MalformedRequestException) {
            return $this->error(HttpStatusCode::BAD_REQUEST, 'Bad Request');
        } catch (RouteNotFoundException) {
            return $this->error(HttpStatusCode::NOT_FOUND, 'Not Found');
        } catch (MethodNotAllowedException $e) {
            $response = $this->error(HttpStatusCode::METHOD_NOT_ALLOWED, 'Method Not Allowed');
            $response->headers->set('Allow', $e->allowedMethods());

            return $response;
        } catch (HeaderTooLargeException) {
            return $this->error(HttpStatusCode::HEADER_TOO_LARGE, 'Request Header Fields Too Large');
        } catch (BodyTooLargeException) {
            return $this->error(HttpStatusCode::PAYLOAD_TOO_LARGE, 'Payload Too Large');
        } catch (\Throwable) {
            return $this->error(HttpStatusCode::INTERNAL_SERVER_ERROR, 'Internal Server Error');
        }
    }

    private function error(HttpStatusCode $status, string $message): HttpResponse
    {
        return ResponseFactory::text($message . PHP_EOL, $status);
    }
}