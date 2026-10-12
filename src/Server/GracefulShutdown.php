<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Server;

use PhpMiniHttpServer\EventLoop\EventLoop;
use PhpMiniHttpServer\Support\Logger;
use PhpMiniHttpServer\Support\NullLogger;
use RuntimeException;

/**
 * Phase 19: RUNNING → DRAINING → FINISHING → STOPPED, driven from the loop.
 *
 * The first shutdown request stops accepting new connections but lets
 * active requests finish and flush. A second one skips the waiting and
 * forces the end; so does the drain deadline (ServerConfig::$drainTimeout)
 * if connections are still open when it passes.
 *
 * Signals only ever *ask*. An async signal handler runs between any two
 * statements of the code it interrupts — halfway through a timer pass, a
 * write, a watcher list being rebuilt — so a handler that closes sockets or
 * edits the loop's watch lists changes them under the feet of whatever was
 * running. Here the handler writes one byte to a self-pipe and nothing
 * else; the loop sees the pipe turn readable and calls requestShutdown() on
 * its own turn, between handlers, like any other event.
 */
final class GracefulShutdown
{
    /**
     * How often a draining server checks whether it is done. Short, because
     * nothing else is happening and the process is waiting to exit.
     */
    public const float CHECK_INTERVAL_SECONDS = 0.1;

    private bool $draining = false;

    private bool $finished = false;

    /** @var list<int> */
    private array $timers = [];

    /** @var resource|null */
    private $signalRead = null;

    public function __construct(
        private readonly EventLoop $loop,
        private readonly Server $server,
        private readonly Logger $logger = new NullLogger(),
        private readonly float $checkInterval = self::CHECK_INTERVAL_SECONDS,
    ) {
    }

    /**
     * Turn $signals (SIGTERM and SIGINT by default) into shutdown requests.
     */
    public function listenForSignals(int ...$signals): void
    {
        $pipe = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($pipe === false) {
            throw new RuntimeException('Could not create the signal self-pipe.');
        }

        [$signalRead, $signalWrite] = $pipe;
        stream_set_blocking($signalRead, false);
        stream_set_blocking($signalWrite, false);
        $this->signalRead = $signalRead;

        // One byte per signal, so two signals that land before the loop
        // wakes up still count as two — the second one forces.
        $this->loop->onReadable($signalRead, function ($stream): void {
            $signals = strlen((string) fread($stream, 64));

            for ($i = 0; $i < $signals && !$this->finished; $i++) {
                $this->requestShutdown();
            }
        });

        // Delivered as they arrive rather than only at
        // pcntl_signal_dispatch() calls: the loop spends most of its life
        // inside stream_select(), which is exactly where a shutdown request
        // has to reach it — the signal interrupts the wait, and the pipe is
        // ready on the next one.
        pcntl_async_signals(true);

        $onSignal = static function () use ($signalWrite): void {
            @fwrite($signalWrite, 'S');
        };

        foreach ($signals === [] ? [SIGTERM, SIGINT] : $signals as $signal) {
            pcntl_signal($signal, $onSignal);
        }
    }

    /**
     * First call: start draining. Second call: force the end. Must run from
     * the loop — a timer, a stream handler — never from a signal handler.
     */
    public function requestShutdown(): void
    {
        if ($this->finished) {
            return;
        }

        if ($this->draining) {
            $this->forceFinish('second signal');

            return;
        }

        $this->draining = true;
        $this->logger->log('shutdown draining: no new connections, finishing active requests');

        if ($this->server->isRunning()) {
            $this->loop->removeReadable($this->server->socket());
        }

        $this->server->drain();

        $drainTimeout = $this->server->config()->drainTimeout;
        $this->timers[] = $this->loop->every($this->checkInterval, $this->checkDrained(...));
        $this->timers[] = $this->loop->addTimer($drainTimeout, fn () => $this->forceFinish(
            sprintf('drain deadline of %.1fs passed', $drainTimeout),
        ));

        // An idle server, or one whose every connection is at rest, is done
        // already.
        $this->checkDrained();
    }

    /**
     * Reap what has come to rest, and finish once nothing is left.
     *
     * A keep-alive connection sitting between requests has nothing left to
     * finish, and drain() guarantees it will never be allowed to start
     * another one — so it is closed now instead of waiting out the idle
     * timeout. Checked on every tick, not just once, because connections
     * keep arriving at rest as their last response (or a 503 refusal)
     * flushes.
     */
    private function checkDrained(): void
    {
        foreach ($this->server->closeRestingConnections() as $connection) {
            $this->logger->log(sprintf('#%d closed: drain (no active request)', $connection->id));
        }

        if ($this->server->connectionCount() === 0) {
            $this->logger->log('shutdown: all connections finished');
            $this->finish();
        }
    }

    private function forceFinish(string $reason): void
    {
        $this->logger->log(sprintf(
            'shutdown forced (%s): closing %d connection(s)',
            $reason,
            $this->server->connectionCount(),
        ));

        $this->finish();
    }

    private function finish(): void
    {
        $this->finished = true;

        foreach ($this->timers as $timerId) {
            $this->loop->cancelTimer($timerId);
        }

        if ($this->signalRead !== null) {
            $this->loop->removeReadable($this->signalRead);
        }

        $this->server->finish();
        $this->loop->stop();
    }
}
