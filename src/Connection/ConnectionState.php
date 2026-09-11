<?php

declare(strict_types=1);

namespace App\Connection;

/**
 * Connection lifecycle.
 *
 *     NEW
 *      │ accepted
 *      ▼
 *     CONNECTED
 *      │ first read
 *      ▼
 *     READING
 *      │ request buffered
 *      ▼
 *     PROCESSING
 *      │ response ready
 *      ▼
 *     WRITING
 *      ├──────────────────┐
 *      │ keep-alive       │ close
 *      ▼                  ▼
 *     READING           CLOSED
 *
 * States are a debugging aid first, a contract second: any code that
 * observes a connection can tell exactly where in the lifecycle it is.
 */
enum ConnectionState: string
{
    case NEW = 'new';
    case CONNECTED = 'connected';
    case READING = 'reading';
    case PROCESSING = 'processing';
    case WRITING = 'writing';
    case CLOSED = 'closed';
}
