<?php

declare(strict_types=1);

namespace Convoy\Tests\Integration\Middleware;

use Convoy\Application;
use Convoy\Middleware\TaskInterceptor;
use Convoy\Scope;
use Convoy\Tests\Support\AsyncTestCase;
use Convoy\Tests\Support\TestServiceBundle;
use PHPUnit\Framework\Attributes\Test;

final class TaskInterceptorTest extends AsyncTestCase
{
    #[Test]
    public function interceptor_pipeline_executes_in_order(): void
    {
        $order = new \ArrayObject();

        $interceptor1 = new class($order) implements TaskInterceptor {
            public function __construct(private \ArrayObject $order) {}
            public function process(object $task, Scope $scope, callable $next): mixed
            {
                $this->order[] = 'before_1';
                $result = $next();
                $this->order[] = 'after_1';
                return $result;
            }
        };

        $interceptor2 = new class($order) implements TaskInterceptor {
            public function __construct(private \ArrayObject $order) {}
            public function process(object $task, Scope $scope, callable $next): mixed
            {
                $this->order[] = 'before_2';
                $result = $next();
                $this->order[] = 'after_2';
                return $result;
            }
        };

        $app = Application::starting()
            ->taskMiddleware($interceptor1, $interceptor2)
            ->compile();

        $scope = $app->createScope();
        $scope->resolve(function () use ($order) { $order[] = 'task'; });

        $this->assertEquals(
            ['before_1', 'before_2', 'task', 'after_2', 'after_1'],
            $order->getArrayCopy(),
            'Interceptors should wrap in LIFO order'
        );
    }

    #[Test]
    public function interceptor_can_modify_result(): void
    {
        $interceptor = new class implements TaskInterceptor {
            public function process(object $task, Scope $scope, callable $next): mixed
            {
                $result = $next();
                return $result * 2;
            }
        };

        $app = Application::starting()
            ->taskMiddleware($interceptor)
            ->compile();

        $scope = $app->createScope();
        $result = $scope->resolve(fn() => 21);

        $this->assertEquals(42, $result);
    }

    #[Test]
    public function interceptor_receives_task_object(): void
    {
        $capturedTask = null;

        $interceptor = new class($capturedTask) implements TaskInterceptor {
            public function __construct(private mixed &$captured) {}
            public function process(object $task, Scope $scope, callable $next): mixed
            {
                $this->captured = $task;
                return $next();
            }
        };

        $app = Application::starting()
            ->taskMiddleware($interceptor)
            ->compile();

        $task = new class {
            public function __invoke(Scope $scope): string
            {
                return 'invokable result';
            }
        };

        $scope = $app->createScope();
        $scope->resolve($task);

        $this->assertSame($task, $capturedTask);
    }

    #[Test]
    public function interceptor_can_catch_exceptions(): void
    {
        $caughtException = null;

        $interceptor = new class($caughtException) implements TaskInterceptor {
            public function __construct(private mixed &$caught) {}
            public function process(object $task, Scope $scope, callable $next): mixed
            {
                try {
                    return $next();
                } catch (\Throwable $e) {
                    $this->caught = $e;
                    return 'recovered';
                }
            }
        };

        $app = Application::starting()
            ->taskMiddleware($interceptor)
            ->compile();

        $scope = $app->createScope();
        $result = $scope->resolve(function () {
            throw new \RuntimeException('task failed');
        });

        $this->assertEquals('recovered', $result);
        $this->assertInstanceOf(\RuntimeException::class, $caughtException);
        $this->assertEquals('task failed', $caughtException->getMessage());
    }

    #[Test]
    public function exception_propagates_through_pipeline(): void
    {
        $exceptions = [];

        $interceptor1 = new class($exceptions) implements TaskInterceptor {
            public function __construct(private array &$exceptions) {}
            public function process(object $task, Scope $scope, callable $next): mixed
            {
                try {
                    return $next();
                } catch (\Throwable $e) {
                    $this->exceptions[] = 'interceptor1: ' . $e->getMessage();
                    throw $e;
                }
            }
        };

        $interceptor2 = new class($exceptions) implements TaskInterceptor {
            public function __construct(private array &$exceptions) {}
            public function process(object $task, Scope $scope, callable $next): mixed
            {
                try {
                    return $next();
                } catch (\Throwable $e) {
                    $this->exceptions[] = 'interceptor2: ' . $e->getMessage();
                    throw $e;
                }
            }
        };

        $app = Application::starting()
            ->taskMiddleware($interceptor1, $interceptor2)
            ->compile();

        $scope = $app->createScope();

        try {
            $scope->resolve(function () {
                throw new \RuntimeException('original error');
            });
        } catch (\RuntimeException) {
        }

        $this->assertEquals([
            'interceptor2: original error',
            'interceptor1: original error',
        ], $exceptions);
    }
}
