<?php

declare(strict_types=1);

namespace App\Tests\EventLoop;

use App\EventLoop\SelectLoop;
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
        $this->assertIsArray($pair);

        return $pair;
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