<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Server;

/**
 * Server lifecycle.
 *
 * The full transition chain becomes real in Phase 19 (graceful shutdown);
 * Phase 1 only touches STOPPED and RUNNING.
 *
 *     RUNNING
 *        │  SIGTERM
 *        ▼
 *     DRAINING      → stop accepting new connections
 *        │
 *        ▼
 *     FINISHING     → drain active connections
 *        │
 *        ▼
 *     STOPPED
 */
enum ServerState: string
{
    case RUNNING = 'running';
    case DRAINING = 'draining';
    case FINISHING = 'finishing';
    case STOPPED = 'stopped';
}
