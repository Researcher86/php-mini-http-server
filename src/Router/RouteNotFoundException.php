<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Router;

use RuntimeException;

/**
 * No handler is registered for the method + path of the request. The error
 * handling phase maps this to HTTP 404 Not Found.
 */
final class RouteNotFoundException extends RuntimeException
{
}
