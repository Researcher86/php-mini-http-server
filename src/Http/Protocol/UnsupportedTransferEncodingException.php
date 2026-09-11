<?php

declare(strict_types=1);

namespace App\Http\Protocol;

use RuntimeException;

/**
 * The request applies a transfer coding this server cannot decode.
 *
 * Only "identity" (no coding at all) is understood, so a body is framed by
 * Content-Length and nothing else. Guessing at any other coding would mean
 * guessing where the request ends — and the bytes we skipped would then be
 * parsed as the next pipelined request, which is request smuggling. The
 * honest answer is 501 Not Implemented.
 */
final class UnsupportedTransferEncodingException extends RuntimeException
{
}
