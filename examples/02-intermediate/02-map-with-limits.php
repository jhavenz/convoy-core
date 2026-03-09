<?php

declare(strict_types=1);

/**
 * Map with Bounded Parallelism
 *
 * map() processes collections with controlled concurrency. The limit
 * parameter caps how many tasks run simultaneously.
 *
 * Why limit concurrency?
 * - Database connection pools have fixed sizes
 * - APIs have rate limits
 * - Memory usage scales with concurrent tasks
 *
 * Run: CONVOY_TRACE=1 php examples/02-intermediate/02-map-with-limits.php
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

echo "=== Processing 10 Stocks with limit=3 ===\n";
$start = microtime(true);

$results = $scope->execute(Task::of(
    static function (ExecutionScope $es): array {
        $reader = $es->service(AsyncStockReader::class);
        $aggregator = $es->service(StockAggregator::class);
        $symbols = $reader->symbols();

        $stockData = $es->map(
            $symbols,
            static fn(string $sym) => Task::of(
                static fn(ExecutionScope $inner) => $inner
                    ->service(AsyncStockReader::class)
                    ->readStock($inner, $sym)
            ),
            limit: 3,
        );

        return array_map(
            fn($data) => $aggregator->aggregate($data),
            $stockData
        );
    }
));

$elapsed = (microtime(true) - $start) * 1000;

echo sprintf("Processed %d stocks in %.1fms\n\n", count($results), $elapsed);

echo "Top 5 by average close:\n";
usort($results, fn($a, $b) => $b['avgClose'] <=> $a['avgClose']);
foreach (array_slice($results, 0, 5) as $i => $stock) {
    echo sprintf("  %d. %s: $%.2f avg (days: %d)\n",
        $i + 1, $stock['symbol'], $stock['avgClose'], $stock['days']);
}

$scope->dispose();
$app->shutdown();
