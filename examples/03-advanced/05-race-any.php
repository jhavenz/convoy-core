<?php

declare(strict_types=1);

/**
 * Race and Any
 *
 * First-wins patterns for competitive task execution:
 * - race(): First task to settle (success OR failure) wins
 * - any(): First task to succeed wins (failures ignored until all fail)
 *
 * Use cases:
 * - Multiple redundant data sources
 * - Timeout with fallback
 * - Hedged requests (send to multiple servers, use first response)
 *
 * Run: CONVOY_TRACE=1 php examples/03-advanced/05-race-any.php
 */

require_once __DIR__ . '/../_shared/bootstrap.php';

use Convoy\Application;
use Convoy\Examples\Support\AsyncStockReader;
use Convoy\Examples\Support\StockBundle;
use Convoy\ExecutionScope;
use Convoy\Task\Task;

$app = Application::starting([
    'data_path' => __DIR__ . '/../_shared/Data',
])
    ->providers(new StockBundle())
    ->compile();

$app->startup();
$scope = $app->createScope();

echo "=== Race: First to Settle Wins ===\n";
echo "Three tasks with different delays. First completion wins.\n\n";

$raceStart = microtime(true);

$winner = $scope->execute(Task::of(
    static function (ExecutionScope $es): array {
        return $es->race([
            'slow' => Task::of(static function (ExecutionScope $inner): array {
                $inner->delay(0.15);
                return ['source' => 'slow', 'delay' => 150];
            }),
            'medium' => Task::of(static function (ExecutionScope $inner): array {
                $inner->delay(0.08);
                return ['source' => 'medium', 'delay' => 80];
            }),
            'fast' => Task::of(static function (ExecutionScope $inner): array {
                $inner->delay(0.03);
                return ['source' => 'fast', 'delay' => 30];
            }),
        ]);
    }
));

$raceTime = (microtime(true) - $raceStart) * 1000;
echo sprintf("Winner (%.1fms): %s\n", $raceTime, json_encode($winner));

echo "\n=== Race: Error Wins If Fastest ===\n";

try {
    $errorWinner = $scope->execute(Task::of(
        static function (ExecutionScope $es): mixed {
            return $es->race([
                'slow_success' => Task::of(static function (ExecutionScope $inner): array {
                    $inner->delay(0.1);
                    return ['success' => true];
                }),
                'fast_error' => Task::of(static function (ExecutionScope $inner): never {
                    $inner->delay(0.02);
                    throw new RuntimeException('Fast failure wins race');
                }),
            ]);
        }
    ));
    echo "Result: " . json_encode($errorWinner) . "\n";
} catch (RuntimeException $e) {
    echo "Exception (fast error won): " . $e->getMessage() . "\n";
}

echo "\n=== Any: First Success Wins ===\n";
echo "Errors are ignored until all tasks fail.\n\n";

$anyStart = microtime(true);

$anyWinner = $scope->execute(Task::of(
    static function (ExecutionScope $es): array {
        return $es->any([
            'fast_fail' => Task::of(static function (ExecutionScope $inner): never {
                $inner->delay(0.01);
                throw new RuntimeException('Fast failure (ignored)');
            }),
            'medium_fail' => Task::of(static function (ExecutionScope $inner): never {
                $inner->delay(0.03);
                throw new RuntimeException('Medium failure (ignored)');
            }),
            'slow_success' => Task::of(static function (ExecutionScope $inner): array {
                $inner->delay(0.05);
                return ['source' => 'slow_success', 'message' => 'I won despite being slow!'];
            }),
        ]);
    }
));

$anyTime = (microtime(true) - $anyStart) * 1000;
echo sprintf("Winner (%.1fms): %s\n", $anyTime, json_encode($anyWinner));

echo "\n=== Any: All Fail Throws AggregateException ===\n";

try {
    $scope->execute(Task::of(
        static function (ExecutionScope $es): mixed {
            return $es->any([
                'fail1' => Task::of(static fn() => throw new RuntimeException('Error 1')),
                'fail2' => Task::of(static fn() => throw new RuntimeException('Error 2')),
                'fail3' => Task::of(static fn() => throw new RuntimeException('Error 3')),
            ]);
        }
    ));
} catch (Throwable $e) {
    echo "All failed. Exception: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
}

echo "\n=== Practical: Hedged Stock Request ===\n";
echo "Query same stock from 'primary' and 'backup' sources.\n\n";

$hedgedResult = $scope->execute(Task::of(
    static function (ExecutionScope $es): array {
        return $es->any([
            'primary' => Task::of(static function (ExecutionScope $inner): array {
                $inner->delay(0.06);
                return $inner->service(AsyncStockReader::class)->readStock($inner, 'AAPL');
            }),
            'backup' => Task::of(static function (ExecutionScope $inner): array {
                $inner->delay(0.04);
                return $inner->service(AsyncStockReader::class)->readStock($inner, 'AAPL');
            }),
        ]);
    }
));

echo sprintf("Got %d rows from fastest source\n", count($hedgedResult['rows']));

$scope->dispose();
$app->shutdown();
