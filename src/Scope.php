<?php

declare(strict_types=1);

namespace Convoy;

use Closure;
use Convoy\Concurrency\CancellationToken;
use Convoy\Concurrency\RetryPolicy;
use Convoy\Concurrency\SettlementBag;
use Convoy\Task\Dispatchable;
use Convoy\Trace\Trace;

interface Scope
{
    public bool $isCancelled { get; }

    public function service(string $type): object;

    public function execute(Dispatchable $task): mixed;

    public function executeFresh(Dispatchable $task): mixed;

    /**
     * @param array<string|int, Dispatchable> $tasks
     * @return array<string|int, mixed>
     */
    public function concurrent(array $tasks): array;

    /** @param array<string|int, Dispatchable> $tasks */
    public function race(array $tasks): mixed;

    /** @param array<string|int, Dispatchable> $tasks */
    public function any(array $tasks): mixed;

    /**
     * @template T
     * @param array<string|int, T> $items
     * @param Closure(T): Dispatchable $fn
     * @return array<string|int, mixed>
     */
    public function map(array $items, Closure $fn, int $limit = 10): array;

    /**
     * @param list<Dispatchable> $tasks
     * @return list<mixed>
     */
    public function series(array $tasks): array;

    /**
     * @param list<Dispatchable> $tasks
     */
    public function waterfall(array $tasks): mixed;

    public function delay(float $seconds): void;

    public function retry(Dispatchable $task, RetryPolicy $policy): mixed;

    /**
     * @param array<string|int, Dispatchable> $tasks
     */
    public function settle(array $tasks): SettlementBag;

    public function timeout(float $seconds, Dispatchable $task): mixed;

    public function throwIfCancelled(): void;

    public function cancellation(): CancellationToken;

    public function onDispose(Closure $callback): void;

    public function dispose(): void;

    public function trace(): Trace;

    public function defer(Dispatchable $task): void;

    public function withAttribute(string $key, mixed $value): Scope;

    public function attribute(string $key, mixed $default = null): mixed;
}
