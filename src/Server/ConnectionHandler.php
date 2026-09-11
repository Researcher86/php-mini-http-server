<?php

declare(strict_types=1);

namespace App\Server;

use App\Connection\Connection;
use App\Connection\WriteBufferException;
use App\EventLoop\SelectLoop;
use App\Http\Handler\RequestHandler;
use App\Http\Protocol\HttpMethod;
use App\Http\Protocol\HttpParser;
use App\Http\Protocol\RequestException;
use App\Http\Protocol\ResponseEncoder;
use App\Http\Response\HttpResponse;
use App\Http\Response\HttpStatusCode;
use App\Http\Response\ResponseFactory;
use App\Metrics\ServerMetrics;
use App\Support\Logger;
use Closure;

/**
 * Drives a single accepted connection through its whole life.
 *
 * This is the per-connection state machine that bin/server.php used to
 * spell out inline. As one object it can be shared by every entry point
 * that needs "read bytes, parse requests, route them, write responses":
 *
 *     READ  →  PROCESSING (parse, route, handle)  →  WRITING (flush)
 *       ▲                                              │
 *       └────────────── keep-alive ───────────────────┴→ close
 *
 * The demo server, the fork-based demo script and the integration tests
 * all hand this handler the same pieces (loop, server, parser, pipeline,
 * encoder) instead of each re-implementing the byte-level dance.
 */
final readonly class ConnectionHandler
{
    /**
     * Phase 18: once a connection's queued responses exceed this many bytes
     * we stop reading from that client until the write buffer drains back
     * down — a slow client must not make the server buffer unbounded work.
     */
    public const int MAX_BUFFERED_RESPONSE_BYTES = 65536;

    public function __construct(
        private SelectLoop $loop,
        private Server $server,
        private Connection $connection,
        private HttpParser $parser,
        private RequestHandler $application,
        private ResponseEncoder $encoder,
        private ServerMetrics $metrics,
        private Logger $logger,
        private int $maxBufferedResponseBytes = self::MAX_BUFFERED_RESPONSE_BYTES,
    ) {
    }

    /**
     * Arm the connection: enter READING and let the loop watch its socket.
     */
    public function start(): void
    {
        $this->connection->startReading();
        $this->loop->onReadable($this->connection->socket(), $this->handleReadable(...));
    }

    private function handleReadable(mixed $stream): void
    {
        $data = fread($stream, 8192);

        if ($data === false || $data === '') { // EOF → the client is gone
            $this->close();

            return;
        }

        $this->connection->appendRead($data);
        $this->metrics->recordBytesRead(strlen($data));

        $this->serviceRequests($stream);
    }

    private function serviceRequests(mixed $stream): void
    {
        $closeAfterDrain = false;
        $queued = false;
        $paused = false;

        // Phase 15: one read may carry several pipelined requests. Keep
        // parsing while the buffer holds complete requests, queueing their
        // responses in order; stop only when a request says "close", there
        // is nothing complete left, or the write buffer hits the ceiling.
        while (true) {
            try {
                $parsed = $this->parser->parse((string) $this->connection->readBuffer());
            } catch (RequestException $e) {
                // Parsing failed, so where this request ends is unknown and
                // every byte after it is suspect. Answer with the status the
                // exception carries, then close.
                $this->queueError($e);
                $closeAfterDrain = true;
                $queued = true;
                break;
            }

            if ($parsed === null) {
                // No complete request yet — the header clock starts (or
                // keeps running) so the Slowloris sweep can reap a client
                // that never finishes its header block.
                $this->connection->noteWaitingForHeaders();
                break; // wait for more bytes
            }

            $this->connection->doneWaitingForHeaders();
            $this->connection->readBuffer()->consume($parsed->consumedBytes);
            $this->connection->startProcessing();

            $request = $parsed->request;

            // Phase 19 graceful shutdown: once the server is DRAINING no NEW
            // request may start. In-flight work is finished and flushed
            // above (the loop reaches this point only between requests);
            // anything that still arrives — the next keep-alive request from
            // a connection that was mid-exchange when shutdown began — is
            // refused with 503 + close so the client learns why, then the
            // connection ends once the response flushes.
            if ($this->server->isDraining()) {
                $response = ResponseFactory::text('Service Unavailable' . PHP_EOL, HttpStatusCode::SERVICE_UNAVAILABLE);
                $response->headers->set('Connection', 'close');

                $this->connection->queueWrite($this->encoder->encode($response));
                $queued = true;
                $closeAfterDrain = true;
                break;
            }

            $startedAt = microtime(true);

            $keepAlive = $request->wantsKeepAlive();
            $response = $this->application->handle($request);

            // HEAD is GET without a body. Work from the would-be body while
            // it is still here so Content-Length matches what the GET would
            // have sent (ResponseFactory sets it already; the upgrade covers
            // hand-built responses), then drop the bytes themselves. Only
            // body-framing statuses get the header: a 204 must never see
            // Content-Length, not even 0.
            if ($request->method === HttpMethod::HEAD && $response->status->framesBody()) {
                if (!$response->headers->has('Content-Length')) {
                    $response->headers->set('Content-Length', (string) $response->contentLength());
                }

                $response = new HttpResponse(
                    $response->version,
                    $response->status,
                    $response->headers,
                    '',
                );
            }

            $response->headers->set('Connection', $keepAlive ? 'keep-alive' : 'close');

            $this->metrics->recordRequest(microtime(true) - $startedAt);

            $this->connection->queueWrite($this->encodeOrFail($response, $keepAlive));
            $queued = true;

            if (!$keepAlive) {
                $closeAfterDrain = true;
                break;
            }

            // Phase 18 backpressure: past the ceiling we stop pulling more
            // requests off the socket until the buffer drains, then resume.
            if ($this->connection->hasBufferedMoreThan($this->maxBufferedResponseBytes)) {
                $paused = true;
                break;
            }
        }

        if (!$queued) {
            return; // nothing complete yet — wait for more bytes
        }

        $this->connection->startWriting();

        if ($paused) {
            $this->pauseReadsUntilDrained($stream);

            return;
        }

        // Phase 14+15: once the queued responses are fully written the
        // connection either goes back to READING (keep-alive) or closes.
        $this->drain($closeAfterDrain
            ? $this->close(...)
            : $this->connection->backToReading(...));
    }

    /**
     * Encode a response, falling back to a bare 500 when it cannot be put on
     * the wire at all.
     *
     * The pipeline's error handler catches whatever a handler throws, but it
     * cannot catch this: encoding happens here, after the pipeline has already
     * returned. A handler that smuggles CR/LF into a header value makes the
     * encoder refuse — rightly, since those bytes would end the header block
     * early and let the rest be read as a second, attacker-chosen response —
     * and that refusal must cost the request, not the server.
     */
    private function encodeOrFail(HttpResponse $response, bool $keepAlive): string
    {
        try {
            return $this->encoder->encode($response);
        } catch (\Throwable $e) {
            $this->logger->log(sprintf(
                '#%d response is not encodable: %s',
                $this->connection->id,
                $e->getMessage(),
            ));

            $fallback = ResponseFactory::text(
                'Internal Server Error' . PHP_EOL,
                HttpStatusCode::INTERNAL_SERVER_ERROR,
            );
            $fallback->headers->set('Connection', $keepAlive ? 'keep-alive' : 'close');

            return $this->encoder->encode($fallback);
        }
    }

    /**
     * Queue an error response and log it, then let the connection handshake
     * close once the response is flushed — after 400/413/431/501 the request
     * stream is already broken and there is no safe way to reuse it.
     */
    private function queueError(RequestException $e): void
    {
        $reason = $e->status->reasonPhrase();

        $this->logger->log(sprintf('#%d %s: %s', $this->connection->id, $reason, $e->getMessage()));

        $this->connection->queueWrite($this->encoder->encode(
            ResponseFactory::text($reason . PHP_EOL, $e->status),
        ));
    }

    private function pauseReadsUntilDrained(mixed $stream): void
    {
        $this->logger->log(sprintf(
            '#%d write buffer at %d bytes — pausing reads',
            $this->connection->id,
            $this->connection->writeBufferLength(),
        ));

        $this->loop->removeReadable($stream);

        $this->drain(function () use ($stream): void {
            $this->connection->backToReading();
            $this->loop->onReadable($stream, $this->handleReadable(...));
            $this->logger->log(sprintf('#%d write buffer drained — resuming reads', $this->connection->id));

            // Requests already sitting in the buffer when the pause hit
            // would never be woken by new network bytes — serve them now.
            if (!$this->connection->readBuffer()->isEmpty()) {
                $this->serviceRequests($stream);
            }
        });
    }

    /**
     * Watch the connection's socket for writable events, flushing whatever
     * the buffer holds; once the buffer is empty run $onDrained.
     *
     * Everything flows through the buffer, so a socket that accepts only
     * part of a large response is handled the same way as a socket that
     * takes it all at once.
     *
     * @param Closure(): void|null $onDrained
     */
    private function drain(?Closure $onDrained = null): void
    {
        $stream = $this->connection->socket();

        $this->loop->onWritable($stream, function ($s) use ($onDrained): void {
            try {
                $this->metrics->recordBytesWritten($this->connection->flushWrite($s));
            } catch (WriteBufferException $e) {
                // The client vanished while we were still writing — a killed
                // browser tab, a dropped mobile connection. There is nobody
                // left to answer, and retrying cannot help, so this one
                // connection is torn down. Letting the exception escape would
                // take the event loop, and with it every other client, down
                // with it: exactly the failure Phase 13 exists to prevent.
                $this->logger->log(sprintf(
                    '#%d closed: write failed (%s)',
                    $this->connection->id,
                    $e->getMessage(),
                ));

                $this->close();

                return;
            }

            if (!$this->connection->writeBuffer()->isEmpty()) {
                return; // the socket took part of it — wait for the next writable event
            }

            $this->loop->removeWritable($s);

            if ($onDrained !== null) {
                $onDrained();
            }
        });
    }

    /**
     * Tear this connection down: stop watching its socket for anything, then
     * close it. Both watchers go — a connection dropped mid-write still has
     * a writable watcher armed, and the loop would keep firing it at a
     * socket nobody owns any more.
     */
    private function close(): void
    {
        $socket = $this->connection->socket();

        $this->loop->removeReadable($socket);
        $this->loop->removeWritable($socket);
        $this->server->close($this->connection);
    }
}