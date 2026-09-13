<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Http\Protocol;

use PhpMiniHttpServer\Http\Response\HttpStatusCode;
use RuntimeException;

/**
 * A request the server refuses, carrying the status that says why.
 *
 * Two places have to answer these: ConnectionHandler, where parsing happens
 * before any middleware runs, and ErrorHandlerMiddleware, in case one
 * surfaces from inside a handler. Both used to keep their own
 * exception-to-status table, and two tables for one mapping is one table too
 * many — so the status lives on the exception, next to the explanation of
 * what went wrong.
 */
abstract class RequestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly HttpStatusCode $status,
    ) {
        parent::__construct($message);
    }
}
