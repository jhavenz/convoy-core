<?php

declare(strict_types=1);

namespace Convoy;

use Closure;
use Convoy\Concurrency\CancellationToken;
use Convoy\Concurrency\RetryPolicy;
use Convoy\Concurrency\SettlementBag;
use Convoy\Trace\Trace;

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
