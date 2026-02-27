<?php

declare(strict_types=1);

namespace Convoy\Tests\Integration\Scope;

use Convoy\Application;
use Convoy\Concurrency\CancellationToken;
use Convoy\Concurrency\Settlement;
use Convoy\Concurrency\SettlementBag;
use Convoy\Exception\CancelledException;
use React\Promise\Exception\CompositeException;
use Convoy\Service\FiberScopeRegistry;
use Convoy\Tests\Support\AsyncTestCase;
use Convoy\Tests\Support\Fixtures\CountingService;
use Convoy\Tests\Support\Fixtures\Logger;
use Convoy\Tests\Support\TestServiceBundle;
use PHPUnit\Framework\Attributes\Test;

use React\EventLoop\Loop;
use React\Promise\Deferred;

use function React\Async\await;
use function React\Async\delay;

final class ScopeConcurrencyTest extends AsyncTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CountingService::reset();
    }

    #[Test]
    public function concurrent_executes_in_parallel(): void
    {
        $bundle = TestServiceBundle::create()
            ->singleton(Logger::class);

        $app = Application::starting()
            ->providers($bundle)
            ->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $start = hrtime(true);

            $results = $scope->concurrent([
                'a' => fn() => (delay(0.05) || 'result_a'),
                'b' => fn() => (delay(0.05) || 'result_b'),
                'c' => fn() => (delay(0.05) || 'result_c'),
            ]);

            $elapsed = (hrtime(true) - $start) / 1e6;

            $this->assertEqualsCanonicalizing(['a', 'b', 'c'], array_keys($results));
            $this->assertEquals('result_a', $results['a']);
            $this->assertEquals('result_b', $results['b']);
            $this->assertEquals('result_c', $results['c']);

            $this->assertLessThan(150, $elapsed, 'Parallel execution should be faster than sequential');
        });
    }

    #[Test]
    public function concurrent_preserves_key_order(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $results = $scope->concurrent([
                'first' => fn() => 1,
                'second' => fn() => 2,
                'third' => fn() => 3,
            ]);

            $this->assertEquals(['first', 'second', 'third'], array_keys($results));
        });
    }

    #[Test]
    public function race_returns_first_settlement(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $fast = new Deferred();
            $slow = new Deferred();

            Loop::futureTick(fn() => $fast->resolve('fast'));

            $result = $scope->race([
                fn() => await($slow->promise()),
                fn() => await($fast->promise()),
            ]);

            $this->assertEquals('fast', $result);
        });
    }

    #[Test]
    public function race_rejects_on_first_error_if_all_fail(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $fast = new Deferred();
            $slow = new Deferred();

            Loop::futureTick(fn() => $fast->reject(new \RuntimeException('fast error')));

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('fast error');

            $scope->race([
                fn() => await($slow->promise()),
                fn() => await($fast->promise()),
            ]);
        });
    }

    #[Test]
    public function any_returns_first_success(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $failFast = new Deferred();
            $succeedSlow = new Deferred();

            Loop::futureTick(fn() => $failFast->reject(new \RuntimeException('error')));
            Loop::addTimer(0.01, fn() => $succeedSlow->resolve('success'));

            $result = $scope->any([
                fn() => await($failFast->promise()),
                fn() => await($succeedSlow->promise()),
            ]);

            $this->assertEquals('success', $result);
        });
    }

    #[Test]
    public function any_throws_composite_when_all_fail(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $this->expectException(CompositeException::class);

            $scope->any([
                function () { throw new \RuntimeException('error1'); },
                function () { throw new \RuntimeException('error2'); },
            ]);
        });
    }

    #[Test]
    public function map_respects_concurrency_limit(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $results = $scope->map(
                range(1, 5),
                fn(int $item) => fn() => $item * 2,
                limit: 2,
            );

            $this->assertEquals([2, 4, 6, 8, 10], array_values($results));
        });
    }

    #[Test]
    public function map_preserves_key_order(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $items = ['a' => 1, 'b' => 2, 'c' => 3];

            $results = $scope->map(
                $items,
                fn(int $v) => fn() => $v * 10,
            );

            $this->assertEquals(['a' => 10, 'b' => 20, 'c' => 30], $results);
        });
    }

    #[Test]
    public function cancellation_checked_before_task_execution(): void
    {
        $app = Application::starting()->compile();

        $token = CancellationToken::create();
        $scope = $app->createScope($token);

        $token->cancel();

        $this->expectException(CancelledException::class);

        $scope->resolve(fn() => 'should not execute');
    }

    #[Test]
    public function fiber_scope_registry_tracks_concurrent_scopes(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $capturedScopes = [];

            $scope->concurrent([
                function () use (&$capturedScopes) {
                    $capturedScopes[] = FiberScopeRegistry::current();
                    return null;
                },
                function () use (&$capturedScopes) {
                    $capturedScopes[] = FiberScopeRegistry::current();
                    return null;
                },
            ]);

            $this->assertCount(2, $capturedScopes);
            foreach ($capturedScopes as $captured) {
                $this->assertSame($scope, $captured);
            }
        });
    }

    #[Test]
    public function series_executes_sequentially(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $order = [];

            $results = $scope->series([
                function () use (&$order) { $order[] = 1; return 'a'; },
                function () use (&$order) { $order[] = 2; return 'b'; },
                function () use (&$order) { $order[] = 3; return 'c'; },
            ]);

            $this->assertEquals([1, 2, 3], $order);
            $this->assertEquals(['a', 'b', 'c'], array_values($results));
        });
    }

    #[Test]
    public function waterfall_passes_result_to_next(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $result = $scope->waterfall([
                fn($scope, $prev) => 1,
                fn($scope, $prev) => $prev + 10,
                fn($scope, $prev) => $prev * 2,
            ]);

            $this->assertEquals(22, $result);
        });
    }

    #[Test]
    public function map_enforces_backpressure(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $maxConcurrent = 0;
            $currentConcurrent = 0;

            $results = $scope->map(
                range(1, 6),
                function (int $item) use (&$maxConcurrent, &$currentConcurrent): callable {
                    return function () use ($item, &$maxConcurrent, &$currentConcurrent): int {
                        $currentConcurrent++;
                        $maxConcurrent = max($maxConcurrent, $currentConcurrent);
                        $result = $item * 2;
                        $currentConcurrent--;
                        return $result;
                    };
                },
                limit: 2,
            );

            $this->assertEquals([2, 4, 6, 8, 10, 12], array_values($results));
            $this->assertLessThanOrEqual(2, $maxConcurrent, 'Should never exceed limit of 2 concurrent');
        });
    }

    #[Test]
    public function concurrent_with_deferred_controlled_resolution(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $deferreds = [
                'a' => new Deferred(),
                'b' => new Deferred(),
                'c' => new Deferred(),
            ];

            Loop::addTimer(0.01, fn() => $deferreds['c']->resolve('third_resolved_first'));
            Loop::addTimer(0.02, fn() => $deferreds['a']->resolve('first_resolved_second'));
            Loop::addTimer(0.03, fn() => $deferreds['b']->resolve('second_resolved_third'));

            $results = $scope->concurrent([
                'a' => fn() => await($deferreds['a']->promise()),
                'b' => fn() => await($deferreds['b']->promise()),
                'c' => fn() => await($deferreds['c']->promise()),
            ]);

            $this->assertEquals('first_resolved_second', $results['a']);
            $this->assertEquals('second_resolved_third', $results['b']);
            $this->assertEquals('third_resolved_first', $results['c']);
            $this->assertEquals(['a', 'b', 'c'], array_keys($results));
        });
    }

    #[Test]
    public function settle_collects_all_outcomes(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $settlements = $scope->settle([
                'success' => fn() => 'ok',
                'failure' => fn() => throw new \RuntimeException('fail'),
                'another' => fn() => 42,
            ]);

            $this->assertCount(3, $settlements);

            $this->assertTrue($settlements['success']->isOk);
            $this->assertSame('ok', $settlements['success']->value);

            $this->assertFalse($settlements['failure']->isOk);
            $this->assertInstanceOf(\RuntimeException::class, $settlements['failure']->error);
            $this->assertSame('fail', $settlements['failure']->error->getMessage());

            $this->assertTrue($settlements['another']->isOk);
            $this->assertSame(42, $settlements['another']->value);
        });
    }

    #[Test]
    public function settle_preserves_key_order(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $bag = $scope->settle([
                'z' => fn() => 1,
                'a' => fn() => 2,
                'm' => fn() => 3,
            ]);

            $keys = [];
            foreach ($bag as $key => $settlement) {
                $keys[] = $key;
            }
            $this->assertEquals(['z', 'a', 'm'], $keys);
        });
    }

    #[Test]
    public function settle_respects_cancellation(): void
    {
        $app = Application::starting()->compile();

        $token = CancellationToken::create();
        $scope = $app->createScope($token);

        $token->cancel();

        $this->expectException(CancelledException::class);

        $scope->settle([
            'task' => fn() => 'never runs',
        ]);
    }

    #[Test]
    public function settle_executes_in_parallel(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $start = hrtime(true);

            $settlements = $scope->settle([
                'a' => fn() => (delay(0.05) || 'a'),
                'b' => fn() => (delay(0.05) || 'b'),
                'c' => fn() => (delay(0.05) || throw new \RuntimeException('c_error')),
            ]);

            $elapsed = (hrtime(true) - $start) / 1e6;

            $this->assertLessThan(150, $elapsed, 'Parallel execution should complete in ~50ms, not 150ms');
            $this->assertCount(3, $settlements);
        });
    }

    #[Test]
    public function settle_with_all_failures(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $settlements = $scope->settle([
                'a' => fn() => throw new \RuntimeException('error_a'),
                'b' => fn() => throw new \InvalidArgumentException('error_b'),
            ]);

            $this->assertFalse($settlements['a']->isOk);
            $this->assertFalse($settlements['b']->isOk);
            $this->assertInstanceOf(\RuntimeException::class, $settlements['a']->error);
            $this->assertInstanceOf(\InvalidArgumentException::class, $settlements['b']->error);
        });
    }

    #[Test]
    public function timeout_returns_result_within_limit(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $result = $scope->timeout(1.0, fn() => 'fast');

            $this->assertSame('fast', $result);
        });
    }

    #[Test]
    public function timeout_throws_cancelled_when_exceeded(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $this->expectException(CancelledException::class);

            $scope->timeout(0.01, function () {
                delay(0.5);
                return 'too slow';
            });
        });
    }

    #[Test]
    public function timeout_composes_with_parent_cancellation(): void
    {
        $app = Application::starting()->compile();

        $token = CancellationToken::create();
        $scope = $app->createScope($token);

        $token->cancel();

        $this->expectException(CancelledException::class);

        $scope->timeout(10.0, fn() => 'never runs');
    }

    #[Test]
    public function timeout_cleans_up_child_scope(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $disposed = false;

            try {
                $scope->timeout(0.01, function ($childScope) use (&$disposed) {
                    $childScope->onDispose(function () use (&$disposed) {
                        $disposed = true;
                    });
                    delay(0.5);
                    return 'never';
                });
            } catch (CancelledException) {
            }

            $this->assertTrue($disposed, 'Child scope should be disposed after timeout');
        });
    }

    #[Test]
    public function settle_returns_settlement_bag(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $bag = $scope->settle([
                'success' => fn() => 'ok',
                'failure' => fn() => throw new \RuntimeException('fail'),
            ]);

            $this->assertInstanceOf(SettlementBag::class, $bag);
        });
    }

    #[Test]
    public function settle_bag_get_with_default(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $bag = $scope->settle([
                'orders' => fn() => ['order1', 'order2'],
                'prefs' => fn() => throw new \RuntimeException('prefs failed'),
            ]);

            $orders = $bag->get('orders', []);
            $prefs = $bag->get('prefs', ['default_pref']);

            $this->assertSame(['order1', 'order2'], $orders);
            $this->assertSame(['default_pref'], $prefs);
        });
    }

    #[Test]
    public function settle_bag_extract_bulk(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $bag = $scope->settle([
                'a' => fn() => 'alpha',
                'b' => fn() => throw new \RuntimeException('b failed'),
                'c' => fn() => 'charlie',
            ]);

            $result = $bag->extract([
                'a' => 'default_a',
                'b' => 'default_b',
                'c' => 'default_c',
            ]);

            $this->assertSame([
                'a' => 'alpha',
                'b' => 'default_b',
                'c' => 'charlie',
            ], $result);
        });
    }

    #[Test]
    public function settle_bag_aggregate_checks(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $bag = $scope->settle([
                'a' => fn() => 'success',
                'b' => fn() => throw new \RuntimeException('fail'),
            ]);

            $this->assertTrue($bag->anyOk);
            $this->assertTrue($bag->anyErr);
            $this->assertFalse($bag->allOk);
            $this->assertFalse($bag->allErr);
            $this->assertSame(['a'], $bag->okKeys);
            $this->assertSame(['b'], $bag->errKeys);
        });
    }

    #[Test]
    public function settle_bag_backwards_compatible_array_access(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $scope = $app->createScope();

            $settlements = $scope->settle([
                'success' => fn() => 'ok',
                'failure' => fn() => throw new \RuntimeException('fail'),
            ]);

            $this->assertTrue($settlements['success']->isOk);
            $this->assertSame('ok', $settlements['success']->value);
            $this->assertFalse($settlements['failure']->isOk);
            $this->assertInstanceOf(\RuntimeException::class, $settlements['failure']->error);
        });
    }
}
