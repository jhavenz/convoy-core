<?php

declare(strict_types=1);

namespace Convoy;

use Closure;
use Convoy\Concurrency\CancellationToken;
use Convoy\Concurrency\RetryPolicy;
use Convoy\Concurrency\Settlement;
use Convoy\Concurrency\SettlementBag;
use Convoy\Middleware\TaskInterceptor;
use Convoy\Service\CompiledService;
use Convoy\Service\DeferredScope;
use Convoy\Service\FiberScopeRegistry;
use Convoy\Service\LazyFactory;
use Convoy\Service\LazySingleton;
use Convoy\Service\ServiceGraph;
use Convoy\Trace\Trace;
use Convoy\Trace\TraceType;
use React\Promise\Deferred;

use function React\Async\async;
use function React\Async\await;
use function React\Async\delay;
use function React\Promise\all;
use function React\Promise\any;
use function React\Promise\race;

interface Scope
{
    public bool $isCancelled { get; }

    public function service(string $type): object;

    public function resolve(callable $task): mixed;

    public function resolveFresh(callable $task): mixed;

    /**
     * @param array<string|int, callable(Scope): mixed> $tasks
     * @return array<string|int, mixed>
     */
    public function concurrent(array $tasks): array;

    /** @param array<string|int, callable(Scope): mixed> $tasks */
    public function race(array $tasks): mixed;

    /** @param array<string|int, callable(Scope): mixed> $tasks */
    public function any(array $tasks): mixed;

    /**
     * @param array<string|int, mixed> $items
     * @return array<string|int, mixed>
     */
    public function map(array $items, callable $fn, int $limit = 10): array;

    /**
     * @param list<callable(Scope): mixed> $tasks
     * @return list<mixed>
     */
    public function series(array $tasks): array;

    /** @param list<callable> $tasks */
    public function waterfall(array $tasks): mixed;

    public function delay(float $seconds): void;

    public function retry(callable $task, RetryPolicy $policy): mixed;

    /**
     * Run all tasks concurrently, collecting all outcomes.
     * Never throws - errors captured in Settlement::err().
     *
     * @param array<string|int, callable> $tasks
     */
    public function settle(array $tasks): SettlementBag;

    /**
     * Run task with timeout. Throws CancelledException if timeout exceeded.
     *
     * @throws \Convoy\Exception\CancelledException
     */
    public function timeout(float $seconds, callable $task): mixed;

    public function throwIfCancelled(): void;

    public function cancellation(): CancellationToken;

    public function onDispose(Closure $callback): void;

    public function dispose(): void;

    public function trace(): Trace;
}

final class ExecutionScope implements Scope
{
    public bool $isCancelled {
        get => $this->cancellation->isCancelled;
    }

    /** @var array<string, object> */
    private array $scopedInstances = [];

    /** @var list<Closure> */
    private array $disposeCallbacks = [];

    /** @var list<string> */
    private array $creationOrder = [];

    private bool $disposed = false;

    public function __construct(
        private readonly ServiceGraph $graph,
        private readonly LazySingleton $singletons,
        private readonly CancellationToken $cancellation,
        private readonly Trace $trace,
        /** @var list<TaskInterceptor> */
        private readonly array $taskInterceptors = [],
    ) {
    }

    public function service(string $type): object
    {
        $this->throwIfCancelled();

        $resolved = $this->graph->aliases[$type] ?? $type;

        if ($this->graph->hasConfig($resolved)) {
            return $this->graph->config($resolved);
        }

        $compiled = $this->graph->resolve($resolved);

        if ($compiled->singleton) {
            return $this->singletons->get($type, fn(string $t): object => $this->service($t));
        }

        if (isset($this->scopedInstances[$resolved])) {
            return $this->scopedInstances[$resolved];
        }

        $instance = $this->createScopedInstance($compiled);

        $this->scopedInstances[$resolved] = $instance;
        $this->creationOrder[] = $resolved;

        return $instance;
    }

    public function resolve(callable $task): mixed
    {
        $this->throwIfCancelled();

        FiberScopeRegistry::register($this);

        try {
            return $this->executeTask($task);
        } finally {
            FiberScopeRegistry::unregister();
        }
    }

    public function resolveFresh(callable $task): mixed
    {
        $this->throwIfCancelled();

        $childScope = new ExecutionScope(
            $this->graph,
            $this->singletons,
            $this->cancellation,
            $this->trace,
            $this->taskInterceptors,
        );

        try {
            return $childScope->resolve($task);
        } finally {
            $childScope->dispose();
        }
    }

    /**
     * @param array<string|int, callable(Scope): mixed> $tasks
     * @return array<string|int, mixed>
     */
    public function concurrent(array $tasks): array
    {
        $this->throwIfCancelled();

        $count = count($tasks);
        $start = hrtime(true);
        $this->trace->log(TraceType::ConcurrentStart, "parallel($count)");

        $promises = [];

        foreach ($tasks as $key => $task) {
            $promises[$key] = async(fn(): mixed => $this->resolve($task))();
        }

        try {
            $results = await(all($promises));
            $elapsed = (hrtime(true) - $start) / 1e6;
            $this->trace->log(TraceType::ConcurrentEnd, "parallel($count) joined", ['elapsed' => $elapsed]);
            return $results;
        } catch (\Throwable $e) {
            $elapsed = (hrtime(true) - $start) / 1e6;
            $this->trace->log(TraceType::Failed, "parallel($count)", ['elapsed' => $elapsed, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /** @param array<string|int, callable(Scope): mixed> $tasks */
    public function race(array $tasks): mixed
    {
        $this->throwIfCancelled();

        $count = count($tasks);
        $start = hrtime(true);
        $this->trace->log(TraceType::ConcurrentStart, "race($count)");

        $promises = [];

        foreach ($tasks as $task) {
            $promises[] = async(fn(): mixed => $this->resolve($task))();
        }

        try {
            $result = await(race($promises));
            $elapsed = (hrtime(true) - $start) / 1e6;
            $this->trace->log(TraceType::ConcurrentEnd, "race($count) settled", ['elapsed' => $elapsed]);
            return $result;
        } catch (\Throwable $e) {
            $elapsed = (hrtime(true) - $start) / 1e6;
            $this->trace->log(TraceType::Failed, "race($count)", ['elapsed' => $elapsed, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /** @param array<string|int, callable(Scope): mixed> $tasks */
    public function any(array $tasks): mixed
    {
        $this->throwIfCancelled();

        $count = count($tasks);
        $start = hrtime(true);
        $this->trace->log(TraceType::ConcurrentStart, "any($count)");

        $promises = [];

        foreach ($tasks as $task) {
            $promises[] = async(fn(): mixed => $this->resolve($task))();
        }

        try {
            $result = await(any($promises));
            $elapsed = (hrtime(true) - $start) / 1e6;
            $this->trace->log(TraceType::ConcurrentEnd, "any($count) succeeded", ['elapsed' => $elapsed]);
            return $result;
        } catch (\Throwable $e) {
            $elapsed = (hrtime(true) - $start) / 1e6;
            $this->trace->log(TraceType::Failed, "any($count)", ['elapsed' => $elapsed, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * @param array<string|int, mixed> $items
     * @return array<string|int, mixed>
     */
    public function map(array $items, callable $fn, int $limit = 10): array
    {
        $this->throwIfCancelled();

        $start = hrtime(true);
        $count = count($items);
        $this->trace->log(TraceType::ConcurrentStart, "map($count, limit=$limit)");


        $index = 0;
        $results = [];
        $pending = [];
        $keys = array_keys($items);
        $resolve = $this->resolve(...);

        $startNext = static function () use (&$pending, &$results, &$index, $keys, $items, $fn, $limit, $resolve): void {
            while (count($pending) < $limit && $index < count($keys)) {
                $key = $keys[$index];
                $item = $items[$key];
                $currentKey = $key;
                $index++;

                $deferred = new Deferred();
                $pending[$currentKey] = $deferred->promise();

                async(static function () use ($fn, $item, $currentKey, &$results, &$pending, $deferred, $resolve): void {
                    try {
                        $task = $fn($item);
                        $results[$currentKey] = $resolve($task);
                        $deferred->resolve(null);
                    } catch (\Throwable $e) {
                        $deferred->reject($e);
                    } finally {
                        unset($pending[$currentKey]);
                    }
                })();
            }
        };

        $startNext();

        while ($pending !== [] || $index < count($keys)) {
            $this->throwIfCancelled();

            if ($pending !== []) {
                await(race($pending));
            }

            $startNext();
        }

        $elapsed = (hrtime(true) - $start) / 1e6;
        $this->trace->log(TraceType::ConcurrentEnd, "map($count) completed", ['elapsed' => $elapsed]);

        $ordered = [];
        foreach ($keys as $key) {
            $ordered[$key] = $results[$key];
        }

        return $ordered;
    }

    /**
     * @param list<callable(Scope): mixed> $tasks
     * @return list<mixed>
     */
    public function series(array $tasks): array
    {
        $this->throwIfCancelled();

        $results = [];
        foreach ($tasks as $task) {
            $results[] = $this->resolve($task);
        }

        return $results;
    }

    public function waterfall(array $tasks): mixed
    {
        $this->throwIfCancelled();

        $result = null;
        foreach ($tasks as $task) {
            $result = $this->resolve(fn($scope) => $task($scope, $result));
        }

        return $result;
    }

    public function delay(float $seconds): void
    {
        $this->throwIfCancelled();
        delay($seconds);
    }

    public function retry(callable $task, RetryPolicy $policy): mixed
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $policy->attempts) {
            $this->throwIfCancelled();

            $attempt++;

            try {
                return $this->resolve($task);
            } catch (\Throwable $e) {
                $lastException = $e;

                if (!$policy->shouldRetry($e) || $attempt >= $policy->attempts) {
                    throw $e;
                }

                $delayMs = $policy->calculateDelay($attempt);
                $this->trace->log(TraceType::Retry, "attempt $attempt", ['delay' => $delayMs]);

                $this->delay($delayMs / 1000);
            }
        }

        throw $lastException ?? new \RuntimeException("Retry exhausted with no exception");
    }

    public function settle(array $tasks): SettlementBag
    {
        $this->throwIfCancelled();

        $count = count($tasks);
        $start = hrtime(true);
        $this->trace->log(TraceType::ConcurrentStart, "settle($count)");

        $settlements = [];
        $promises = [];

        foreach ($tasks as $key => $task) {
            $currentKey = $key;
            $promises[$key] = async(function () use ($task, $currentKey, &$settlements): void {
                try {
                    $result = $this->resolve($task);
                    $settlements[$currentKey] = Settlement::ok($result);
                } catch (\Throwable $e) {
                    $settlements[$currentKey] = Settlement::err($e);
                }
            })();
        }

        await(all($promises));

        $elapsed = (hrtime(true) - $start) / 1e6;
        $this->trace->log(TraceType::ConcurrentEnd, "settle($count) completed", ['elapsed' => $elapsed]);

        // Restore key order
        $ordered = [];
        foreach (array_keys($tasks) as $key) {
            $ordered[$key] = $settlements[$key];
        }

        return new SettlementBag($ordered);
    }

    public function timeout(float $seconds, callable $task): mixed
    {
        $this->throwIfCancelled();

        $timeoutToken = CancellationToken::timeout($seconds);
        $token = CancellationToken::composite($this->cancellation, $timeoutToken);

        $childScope = new ExecutionScope(
            $this->graph,
            $this->singletons,
            $token,
            $this->trace,
            $this->taskInterceptors,
        );

        $taskPromise = async(fn() => $childScope->resolve($task))();

        $timeoutPromise = new \React\Promise\Promise(function ($resolve, $reject) use ($timeoutToken): void {
            $timeoutToken->onCancel(function () use ($reject): void {
                $reject(new \Convoy\Exception\CancelledException('Timeout exceeded'));
            });
        });

        try {
            return await(race([$taskPromise, $timeoutPromise]));
        } finally {
            $childScope->dispose();
        }
    }

    public function throwIfCancelled(): void
    {
        $this->cancellation->throwIfCancelled();
    }

    public function cancellation(): CancellationToken
    {
        return $this->cancellation;
    }

    public function onDispose(Closure $callback): void
    {
        $this->disposeCallbacks[] = $callback;
    }

    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }

        $this->disposed = true;

        foreach (array_reverse($this->disposeCallbacks) as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                error_log("Dispose callback failed: " . $e->getMessage());
            }
        }

        foreach (array_reverse($this->creationOrder) as $type) {
            $instance = $this->scopedInstances[$type] ?? null;

            if ($instance === null) {
                continue;
            }

            if (LazyFactory::isUninitialized($instance)) {
                continue;
            }

            $compiled = $this->graph->resolve($type);

            foreach ($compiled->lifecycle->onDispose as $hook) {
                try {
                    $hook($instance);
                } catch (\Throwable $e) {
                    error_log("Dispose hook failed for $type: " . $e->getMessage());
                }
            }

            $this->trace->log(TraceType::ServiceDispose, $compiled->shortName());
        }

        $this->scopedInstances = [];
        $this->creationOrder = [];
        $this->disposeCallbacks = [];
    }

    public function trace(): Trace
    {
        return $this->trace;
    }

    private function createScopedInstance(CompiledService $compiled): object
    {
        $deps = [new DeferredScope()];

        foreach ($compiled->dependencyOrder as $depType) {
            $deps[] = $this->service($depType);
        }

        if ($compiled->lazy) {
            return LazyFactory::wrap($compiled->type, fn() => ($compiled->factory)(...$deps), $this->trace);
        }

        $this->trace->log(TraceType::ServiceInit, $compiled->shortName());

        $instance = ($compiled->factory)(...$deps);

        foreach ($compiled->lifecycle->onInit as $hook) {
            $hook($instance);
        }

        return $instance;
    }

    private function executeTask(callable $task): mixed
    {
        $name = $this->taskName($task);
        $start = hrtime(true);

        $taskObj = is_object($task) ? $task : null;
        $this->trace->log(TraceType::Executing, $name, task: $taskObj);

        $pipeline = fn() => is_object($task) && method_exists($task, '__invoke')
            ? $task($this)
            : $task($this);

        if ($taskObj !== null) {
            foreach (array_reverse($this->taskInterceptors) as $mw) {
                $next = $pipeline;
                $pipeline = fn() => $mw->process($taskObj, $this, $next);
            }
        }

        try {
            $result = $pipeline();
            $elapsed = (hrtime(true) - $start) / 1e6;
            $this->trace->log(TraceType::Done, $name, ['elapsed' => $elapsed]);
            return $result;
        } catch (\Throwable $e) {
            $elapsed = (hrtime(true) - $start) / 1e6;
            $this->trace->log(TraceType::Failed, $name, ['elapsed' => $elapsed, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function taskName(callable $task): string
    {
        if ($task instanceof \Closure) {
            $ref = new \ReflectionFunction($task);
            $file = basename($ref->getFileName() ?: 'unknown');
            $line = $ref->getStartLine();
            return "Closure@$file:$line";
        }

        if (is_object($task)) {
            $parts = explode('\\', $task::class);
            return end($parts);
        }

        if (is_array($task)) {
            $class = is_object($task[0]) ? $task[0]::class : $task[0];
            $parts = explode('\\', $class);
            return end($parts) . '::' . $task[1];
        }

        return 'Closure';
    }
}
