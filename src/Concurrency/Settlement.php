<?php

declare(strict_types=1);

namespace Convoy\Concurrency;

use RuntimeException;
use Throwable;

/**
 * Outcome wrapper for settled tasks - captures success or failure.
 *
 * Unlike Result (control flow monad), Settlement represents a task that
 * has completed - either successfully or with an error. Used by settle()
 * to collect all outcomes without short-circuiting on failure.
 */
final readonly class Settlement
{
    private function __construct(
        public bool $isOk,
        public mixed $value,
        public ?Throwable $error,
    ) {
    }

    public static function ok(mixed $value): self
    {
        return new self(isOk: true, value: $value, error: null);
    }

    public static function err(Throwable $error): self
    {
        return new self(isOk: false, value: null, error: $error);
    }

    /**
     * Extract the value, throwing if this is an error settlement.
     *
     * @throws Throwable The original error if this settlement captured a failure
     */
    public function unwrap(): mixed
    {
        if (!$this->isOk) {
            throw $this->error ?? new RuntimeException('Settlement is in error state');
        }

        return $this->value;
    }

    /**
     * Extract the value, returning the default if this is an error settlement.
     */
    public function unwrapOr(mixed $default): mixed
    {
        return $this->isOk ? $this->value : $default;
    }

    /**
     * Get the error, or null if this is a success settlement.
     */
    public function error(): ?Throwable
    {
        return $this->error;
    }
}
