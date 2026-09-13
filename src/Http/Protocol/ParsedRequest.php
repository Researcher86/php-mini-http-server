<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Http\Protocol;

use PhpMiniHttpServer\Http\Request\HttpRequest;

/**
 * The outcome of a parse attempt that succeeded.
 *
 * consumedBytes tells the caller how many of the raw bytes were turned
 * into this request, so the remainder of a pipelined buffer stays intact
 * for the next parse (Phase 15).
 */
final readonly class ParsedRequest
{
    public function __construct(
        public HttpRequest $request,
        public int $consumedBytes,
    ) {
    }
}
