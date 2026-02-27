<?php

declare(strict_types=1);

namespace Convoy\Tests\Support;

use Convoy\Service\FiberScopeRegistry;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

use function React\Async\async;
use function React\Async\await;
use function React\Promise\Timer\timeout;

abstract class AsyncTestCase extends TestCase
{
    protected float $timeoutSeconds = 5.0;

    protected function tearDown(): void
    {
        FiberScopeRegistry::reset();
        parent::tearDown();
    }

    protected function runAsync(callable $test): mixed
    {
        $promise = async($test)();

        if ($promise instanceof PromiseInterface) {
            $promise = timeout($promise, $this->timeoutSeconds);
        }

        try {
            return await($promise);
        } finally {
            Loop::run();
        }
    }

    protected function assertElapsedLessThan(float $maxMs, callable $fn): mixed
    {
        $start = hrtime(true);
        $result = $fn();
        $elapsed = (hrtime(true) - $start) / 1e6;

        $this->assertLessThan($maxMs, $elapsed, "Expected execution under {$maxMs}ms, got {$elapsed}ms");

        return $result;
    }

    protected function assertElapsedAtLeast(float $minMs, callable $fn): mixed
    {
        $start = hrtime(true);
        $result = $fn();
        $elapsed = (hrtime(true) - $start) / 1e6;

        $this->assertGreaterThanOrEqual($minMs, $elapsed, "Expected execution at least {$minMs}ms, got {$elapsed}ms");

        return $result;
    }
}
