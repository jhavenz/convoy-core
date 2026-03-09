<?php

declare(strict_types=1);

/**
 * Concurrent Basics
 *
 * concurrent() runs multiple tasks in parallel and waits for all to complete.
 * This is where async shines - overlapping I/O operations.
 *
 * With simulated 50ms latency per stock read:
 * - Sequential: 2 reads = ~100ms
 * - Concurrent: 2 reads = ~50ms (overlap)
 *
 * Run: CONVOY_TRACE=1 php examples/02-intermediate/01-concurrent-basics.php
 */

require_once __DIR__ . '/../_shared/bootstrap.php';

use Convoy\Application;
use Convoy\Examples\Support\AsyncStockReader;
use Convoy\Examples\Support\StockAggregator;
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

echo "=== Sequential Reads ===\n";
$seqStart = microtime(true);

$sequential = $scope->execute(Task::of(
    static function (ExecutionScope $es): array {
        $reader = $es->service(AsyncStockReader::class);

        $aapl = $reader->readStock($es, 'AAPL');
        $googl = $reader->readStock($es, 'GOOGL');

        return ['AAPL' => count($aapl['rows']), 'GOOGL' => count($googl['rows'])];
    }
));

$seqTime = (microtime(true) - $seqStart) * 1000;
echo sprintf("Sequential: %.1fms - %s\n", $seqTime, json_encode($sequential));

echo "\n=== Concurrent Reads ===\n";
$concStart = microtime(true);

$concurrent = $scope->execute(Task::of(
    static function (ExecutionScope $es): array {
        $results = $es->concurrent([
            'AAPL' => Task::of(
                static fn(ExecutionScope $inner) => $inner
                    ->service(AsyncStockReader::class)
                    ->readStock($inner, 'AAPL')
            ),
            'GOOGL' => Task::of(
                static fn(ExecutionScope $inner) => $inner
                    ->service(AsyncStockReader::class)
                    ->readStock($inner, 'GOOGL')
            ),
        ]);

        return [
            'AAPL' => count($results['AAPL']['rows']),
            'GOOGL' => count($results['GOOGL']['rows']),
        ];
    }
));

$concTime = (microtime(true) - $concStart) * 1000;
echo sprintf("Concurrent: %.1fms - %s\n", $concTime, json_encode($concurrent));

echo sprintf("\nSpeedup: %.1fx faster\n", $seqTime / $concTime);

$scope->dispose();
$app->shutdown();
