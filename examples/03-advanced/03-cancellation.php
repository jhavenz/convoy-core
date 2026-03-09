<?php

declare(strict_types=1);

/**
 * Cancellation
 *
 * CancellationToken propagates cancellation through the task tree:
 * - Manual: CancellationToken::create() + token->cancel()
 * - Timeout: CancellationToken::timeout(seconds)
 * - Composite: CancellationToken::composite(...tokens)
 *
 * Tasks check cancellation via:
 * - $scope->throwIfCancelled()
 * - $scope->isCancelled
 *
 * Run: CONVOY_TRACE=1 php examples/03-advanced/03-cancellation.php
 */

require_once __DIR__ . '/../_shared/bootstrap.php';

use Convoy\Application;
use Convoy\Concurrency\CancellationToken;
use Convoy\Examples\Tasks\SteppedTask;
use Convoy\Exception\CancelledException;
use Convoy\ExecutionScope;
use Convoy\Task\Task;

$app = Application::starting()->compile();
$app->startup();

echo "=== Manual Cancellation ===\n";

$token = CancellationToken::create();

$scope = $app->createScope($token);

$scope->defer(Task::of(static function () use ($token): void {
    usleep(120000);
    $token->cancel();
}));

try {
    $result = $scope->execute(new SteppedTask(steps: 5, delaySeconds: 0.05));
    echo "Result: " . json_encode($result) . "\n";
} catch (CancelledException $e) {
    echo "CancelledException caught: Task was cancelled mid-execution\n";
}

$scope->dispose();

echo "\n=== Timeout-Based Cancellation ===\n";

$timeoutToken = CancellationToken::timeout(0.08);
$scope2 = $app->createScope($timeoutToken);

try {
    $result = $scope2->execute(new SteppedTask(steps: 10, delaySeconds: 0.02));
    echo "Result: " . json_encode($result) . "\n";
} catch (CancelledException $e) {
    echo "CancelledException caught: Timeout triggered cancellation\n";
}

$scope2->dispose();

echo "\n=== Checking Cancellation State ===\n";

$checkToken = CancellationToken::create();
$scope3 = $app->createScope($checkToken);

$result = $scope3->execute(Task::of(static function (ExecutionScope $es) use ($checkToken): array {
    $iterations = 0;
    while (!$es->isCancelled && $iterations < 5) {
        $iterations++;
        $es->delay(0.02);
        if ($iterations === 3) {
            $checkToken->cancel();
        }
    }
    return ['iterations' => $iterations, 'graceful_exit' => true];
}));

echo "Result: " . json_encode($result) . "\n";

$scope3->dispose();
$app->shutdown();
