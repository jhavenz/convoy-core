<?php

declare(strict_types=1);

namespace Convoy\Examples\Tasks;

use Convoy\Concurrency\RetryPolicy;
use Convoy\ExecutionScope;
use Convoy\Task\Executable;
use Convoy\Task\Retryable;
use Convoy\Task\Traceable;
use RuntimeException;

final class UnreliableTask implements Executable, Retryable, Traceable
{
    private static int $attempts = 0;

    public function __construct(
        private int $failUntilAttempt = 3,
    ) {
    }

    public string $traceName {
        get => 'UnreliableTask';
    }

    public RetryPolicy $retryPolicy {
        get => RetryPolicy::exponential(attempts: 5, baseDelayMs: 50);
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        self::$attempts++;

        if (self::$attempts < $this->failUntilAttempt) {
            throw new RuntimeException("Simulated failure (attempt " . self::$attempts . ")");
        }

        return [
            'success' => true,
            'attempts' => self::$attempts,
        ];
    }

    public static function resetAttempts(): void
    {
        self::$attempts = 0;
    }
}
