<?php

declare(strict_types=1);

/**
 * Timeouts
 *
 * Protect against slow operations with timeouts:
 * - HasTimeout interface on task classes
 * - $scope->timeout() for inline timeout wrapping
 *
 * When timeout triggers, a CancelledException is thrown with "Timeout exceeded".
 *
 * Run: CONVOY_TRACE=1 php examples/03-advanced/02-timeouts.php
 */

require_once __DIR__ . '/../_shared/bootstrap.php';

use Convoy\Application;
use Convoy\Examples\Tasks\SlowTask;
use Convoy\Exception\CancelledException;
use Convoy\ExecutionScope;
use Convoy\Task\Task;

$app = Application::starting()->compile();
$app->startup();
$scope = $app->createScope();

echo "=== Task with HasTimeout Interface ===\n";
echo "Task sleeps 500ms but timeout is 100ms\n\n";

try {
    $result = $scope->execute(new SlowTask(sleepSeconds: 0.5, timeoutSeconds: 0.1));
    echo "Result: " . json_encode($result) . "\n";
} catch (CancelledException $e) {
    echo "CancelledException caught: " . $e->getMessage() . "\n";
}

echo "\n=== Manual Timeout with \$scope->timeout() ===\n";

try {
    $result = $scope->timeout(
        0.05,
        Task::of(static function (ExecutionScope $es): array {
            $es->delay(0.2);
            return ['completed' => true];
        })
    );
    echo "Result: " . json_encode($result) . "\n";
} catch (CancelledException $e) {
    echo "CancelledException caught: " . $e->getMessage() . "\n";
}

echo "\n=== Fast Task Completes Within Timeout ===\n";

$result = $scope->timeout(
    1.0,
    Task::of(static function (ExecutionScope $es): array {
        $es->delay(0.01);
        return ['completed' => true, 'fast' => true];
    })
);

echo "Result: " . json_encode($result) . "\n";

$scope->dispose();
$app->shutdown();
