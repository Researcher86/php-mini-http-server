<?php

declare(strict_types=1);

namespace App\Tests;

use App\Server\Server;
use PHPUnit\Framework\TestCase;

final class ProjectSetupTest extends TestCase
{
    public function testProjectLoads(): void
    {
        $this->assertTrue(class_exists(Server::class));
    }
}
