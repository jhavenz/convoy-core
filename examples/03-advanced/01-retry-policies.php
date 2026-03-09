<?php

declare(strict_types=1);

/**
 * Retry Policies
 *
 * Convoy supports automatic retries with configurable backoff strategies:
 * - RetryPolicy::exponential() - doubles delay each attempt (2^n)
 * - RetryPolicy::linear() - increases delay linearly (n * base)
 * - RetryPolicy::fixed() - same delay every attempt
 *
 * Tasks can implement Retryable interface or use $scope->retry().
 *
 * Run: CONVOY_TRACE=1 php examples/03-advanced/01-retry-policies.php
 */

require_once __DIR__ . '/../_shared/bootstrap.php';

use Convoy\Application;
use Convoy\Concurrency\RetryPolicy;
use Convoy\Examples\Exceptions\NonRetryableException;
use Convoy\Examples\Exceptions\RetryableException;
use Convoy\Examples\Tasks\UnreliableTask;
use Convoy\ExecutionScope;
use Convoy\Task\Task;

$app = Application::starting()->compile();
$app->startup();
$scope = $app->createScope();

echo "=== Task with Retryable Interface ===\n";
echo "Task fails on attempts 1-2, succeeds on attempt 3\n\n";

UnreliableTask::resetAttempts();
try {
    $result = $scope->execute(new UnreliableTask(failUntilAttempt: 3));
    echo "Result: " . json_encode($result) . "\n";
} catch (Throwable $e) {
    echo "Failed after all retries: " . $e->getMessage() . "\n";
}

echo "\n=== Manual Retry with \$scope->retry() ===\n";

$manualAttempts = 0;
$result = $scope->retry(
    Task::of(static function (ExecutionScope $es) use (&$manualAttempts): array {
        $manualAttempts++;
        if ($manualAttempts < 2) {
            throw new RuntimeException("Manual retry failure");
        }
        return ['success' => true, 'attempts' => $manualAttempts];
    }),
    RetryPolicy::fixed(attempts: 3, delayMs: 25),
);

echo "Result: " . json_encode($result) . "\n";

echo "\n=== Retry with Exception Filtering ===\n";

$filterAttempts = 0;
try {
    $scope->retry(
        Task::of(static function () use (&$filterAttempts): never {
            $filterAttempts++;
            if ($filterAttempts === 1) {
                throw new RetryableException("Will retry");
            }
            throw new NonRetryableException("Won't retry");
        }),
        RetryPolicy::fixed(attempts: 3, delayMs: 10)->retryingOn(RetryableException::class),
    );
} catch (NonRetryableException $e) {
    echo "Stopped on NonRetryableException after $filterAttempts attempts\n";
}

$scope->dispose();
$app->shutdown();
