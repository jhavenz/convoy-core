<?php

declare(strict_types=1);

/**
 * Hello Task - Your First Convoy Application
 *
 * Demonstrates the minimal Convoy lifecycle:
 * 1. Application::starting() - configure context
 * 2. compile() - build service graph
 * 3. startup() - initialize singletons
 * 4. createScope() - get execution scope
 * 5. execute() - run your task
 * 6. shutdown() - cleanup
 *
 * Run: php examples/01-beginner/01-hello-task.php
 */

require_once __DIR__ . '/../_shared/bootstrap.php';

use Convoy\Application;
use Convoy\ExecutionScope;
use Convoy\Task\Task;

$app = Application::starting()->compile();
$app->startup();

$scope = $app->createScope();

$result = $scope->execute(Task::of(
    static fn() => [
        'message' => 'Hello from Convoy!',
        'time' => date('Y-m-d H:i:s'),
    ]
));

echo json_encode($result, JSON_PRETTY_PRINT) . "\n";

$scope->dispose();
$app->shutdown();
