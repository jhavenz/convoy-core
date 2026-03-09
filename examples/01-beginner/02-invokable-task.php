<?php

declare(strict_types=1);

/**
 * Invokable Task Classes
 *
 * Task::of() wraps closures, but invokable classes are better for:
 * - Constructor injection of parameters
 * - Type safety and IDE support
 * - Serialization (closures can't be serialized)
 * - Testing in isolation
 *
 * Two interface options:
 * - Scopeable: minimal interface, service resolution only
 * - Executable: full capabilities, concurrency primitives
 *
 * Run: php examples/01-beginner/02-invokable-task.php
 */

require_once __DIR__ . '/../_shared/bootstrap.php';

use Convoy\Application;
use Convoy\Examples\Tasks\Greeter;

$app = Application::starting()->compile();
$app->startup();

$scope = $app->createScope();

$result1 = $scope->execute(new Greeter('World'));
$result2 = $scope->execute(new Greeter('Convoy', 'Welcome to'));

echo "Result 1: " . json_encode($result1) . "\n";
echo "Result 2: " . json_encode($result2) . "\n";

$scope->dispose();
$app->shutdown();
