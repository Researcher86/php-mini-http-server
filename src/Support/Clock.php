<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Support;

/**
 * "What time is it", behind an interface.
 *
 * Everything that decides a deadline asks this: is the connection idle
 * past its timeout, has the header block been arriving too long, how long
 * has the server been up. Those decisions are the server's policy, and a
 * test should be able to drive them without waiting out real seconds.
 *
 * Measuring how long something *took* is a different question and is not
 * asked here — elapsed time is taken with hrtime(), which is monotonic and
 * so cannot run backwards when the wall clock is corrected. The rule the
 * codebase follows:
 *
 *     Clock     when is it now?      deadlines, uptime      injectable
 *     hrtime()  how long did it take? durations, loop lag   not injectable
 *
 * Seconds as a float, matching microtime(true), which is what the value
 * meant everywhere before this interface existed.
 */
interface Clock
{
    public function now(): float;
}
