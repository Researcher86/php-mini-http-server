<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Tests\EventLoop;

use PhpMiniHttpServer\EventLoop\SelectLoop;
use PHPUnit\Framework\TestCase;

final class SelectLoopTest extends TestCase
{
    private SelectLoop $loop;

    protected function setUp(): void
    {
        $this->loop = new SelectLoop();
    }

    public function testReadableHandlerFiresWhenDataArrives(): void
    {
        [$server, $client] = $this->socketPair();

        $received = null;
        $loop = $this->loop;

        $this->loop->onReadable($server, function ($stream) use (&$received, $loop): void {
            $received = fread($stream, 8192);
            $loop->stop();
        });

        fwrite($client, 'ping');
        $this->loop->run();

        $this->assertSame('ping', $received);

        fclose($server);
        fclose($client);
    }

    public function testMultipleConnectionsAreHandledByOneLoop(): void
    {
        [$serverA, $clientA, $serverB, $clientB] = $this->twoPairs();

        $received = [];
        /** @var array<string, string|false> $received */
        $loop = $this->loop;

        $this->loop->onReadable($serverA, function ($stream) use (&$received, $loop): void {
            $received['a'] = fread($stream, 8192);

            if (isset($received['b'])) {
                $loop->stop();
            }
        });
        $this->loop->onReadable($serverB, function ($stream) use (&$received, $loop): void {
            $received['b'] = fread($stream, 8192);

            if (isset($received['a'])) {
                $loop->stop();
            }
        });

        fwrite($clientA, 'from A');
        fwrite($clientB, 'from B');
        $this->loop->run();

        $this->assertSame('from A', $received['a']);
        $this->assertSame('from B', $received['b']);

        foreach ([$serverA, $clientA, $serverB, $clientB] as $stream) {
            fclose($stream);
        }
    }

    public function testStreamClosedByAnEarlierHandlerIsNotDispatched(): void
    {
        [$serverA, $clientA, $serverB, $clientB] = $this->twoPairs();

        $loop = $this->loop;
        $fired = [];

        // Both sockets go into the same select() pass. A's handler closes
        // B — the shape any sweep, broadcast or shutdown handler takes when
        // it closes a connection it does not itself own.
        $this->loop->onReadable($serverA, static function ($stream) use (&$fired, $serverB): void {
            $fired[] = 'a';
            fread($stream, 8192);
            fclose($serverB);
        });
        $this->loop->onReadable($serverB, static function ($stream) use (&$fired): void {
            $fired[] = 'b';
            fread($stream, 8192); // a TypeError on a closed stream, and it ends the loop
        });

        fwrite($clientA, 'a');
        fwrite($clientB, 'b');

        $this->loop->addTimer(0.1, static fn () => $loop->stop());
        $this->loop->run();

        $this->assertSame(['a'], $fired);
        $this->assertSame(1, $this->loop->watchedReadableCount());

        fclose($serverA);
        fclose($clientA);
        fclose($clientB);
    }

    public function testASignalDuringTheWaitDoesNotMakeIdleStreamsLookReady(): void
    {
        [$server, $client] = $this->socketPair();

        $loop = $this->loop;
        $dispatched = 0;

        // An idle connection: nothing will ever arrive on it during this
        // test, so its handler must never run.
        $this->loop->onReadable($server, static function () use (&$dispatched): void {
            $dispatched++;
        });

        pcntl_async_signals(true);
        pcntl_signal(SIGUSR1, static fn () => null);

        // The signal has to land while the loop is blocked in select(),
        // which is what a SIGTERM asking for a graceful shutdown does. A
        // child is the only way to deliver it from outside that wait.
        $parent = posix_getpid();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);

        if ($pid === 0) {
            usleep(50_000);
            posix_kill($parent, SIGUSR1);
            // SIGKILL rather than exit(): the child inherited PHPUnit, and
            // a clean exit would run its shutdown handlers and report a
            // second set of results.
            posix_kill(posix_getpid(), SIGKILL);
        }

        $this->loop->addTimer(0.3, static fn () => $loop->stop());
        $this->loop->run();

        pcntl_waitpid($pid, $status);
        pcntl_signal(SIGUSR1, SIG_DFL);

        // stream_select() reports an interruption by returning false and
        // leaving the arrays it was given untouched — which reads exactly
        // like "every watched stream is ready". Acting on that hands each
        // idle connection a read that returns nothing, and a read that
        // returns nothing is how a handler recognises a closed peer.
        $this->assertSame(0, $dispatched);

        fclose($server);
        fclose($client);
    }

    public function testWritableHandlerFires(): void
    {
        [$server, $client] = $this->socketPair();

        $fired = false;
        $loop = $this->loop;

        $this->loop->onWritable($server, function () use (&$fired, $loop): void {
            $fired = true;
            $loop->stop();
        });

        $this->loop->run();

        $this->assertTrue($fired);

        fclose($server);
        fclose($client);
    }

    public function testOneShotTimerFiresOnce(): void
    {
        $fired = 0;
        $loop = $this->loop;

        $this->loop->addTimer(0.01, function () use (&$fired): void {
            $fired++;
        });

        $this->loop->addTimer(0.05, function () use ($loop): void {
            $loop->stop();
        });

        $this->loop->run();

        $this->assertSame(1, $fired);
    }

    public function testPeriodicTimerFiresRepeatedlyThenStops(): void
    {
        $fired = 0;
        $loop = $this->loop;

        $this->loop->every(0.005, function () use (&$fired, $loop): void {
            $fired++;

            if ($fired >= 3) {
                $loop->stop();
            }
        });

        $this->loop->run();

        $this->assertSame(3, $fired);
    }

    public function testCancelledTimerNeverFires(): void
    {
        $fired = 0;
        $loop = $this->loop;

        $id = $this->loop->addTimer(0.005, function () use (&$fired): void {
            $fired++;
        });

        $this->loop->cancelTimer($id);

        $this->loop->addTimer(0.02, function () use ($loop): void {
            $loop->stop();
        });

        $this->loop->run();

        $this->assertSame(0, $fired);
    }

    public function testStopHaltsTheLoop(): void
    {
        $called = false;
        $loop = $this->loop;

        $this->loop->addTimer(0.01, function () use (&$called, $loop): void {
            $called = true;
            $loop->stop();
        });

        $this->loop->run();

        $this->assertTrue($called);
        $this->assertFalse($this->loop->isRunning());
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($pair === false) {
            $this->fail('cannot create socket pair');
        }

        return [$pair[0], $pair[1]];
    }

    /**
     * Two independent socket pairs for the concurrency test.
     *
     * @return array{0: resource, 1: resource, 2: resource, 3: resource}
     */
    private function twoPairs(): array
    {
        $a = $this->socketPair();
        $b = $this->socketPair();

        return [...$a, ...$b];
    }
}
