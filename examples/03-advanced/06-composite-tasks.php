<?php

declare(strict_types=1);

/**
 * Composite Tasks
 *
 * Complex operations built from nested concurrency primitives.
 * Demonstrates how Convoy's primitives compose cleanly.
 *
 * Pattern: Break complex operations into focused Executable classes,
 * then orchestrate them with concurrent/map/series.
 *
 * Run: CONVOY_TRACE=1 php examples/03-advanced/06-composite-tasks.php
 */

require_once __DIR__ . '/../_shared/bootstrap.php';

use Convoy\Application;
use Convoy\Examples\Support\StockBundle;
use Convoy\Examples\Tasks\BatchStockReport;
use Convoy\Examples\Tasks\MarketAnalysis;

$app = Application::starting([
    'data_path' => __DIR__ . '/../_shared/Data',
])
    ->providers(new StockBundle())
    ->compile();

$app->startup();
$scope = $app->createScope();

echo "=== Market Analysis (Nested Concurrency) ===\n";
echo "1. Read all stocks in parallel (limit 5)\n";
echo "2. Find top 3 by average close\n";
echo "3. Compare top 3 concurrently\n\n";

$analysisStart = microtime(true);

$analysis = $scope->execute(new MarketAnalysis());

$analysisTime = (microtime(true) - $analysisStart) * 1000;

echo "Top 3 Stocks:\n";
foreach ($analysis['top3'] as $i => $stock) {
    echo sprintf("  %d. %s: \$%.2f avg\n", $i + 1, $stock['symbol'], $stock['avgClose']);
}

echo "\nComparisons:\n";
foreach ($analysis['comparisons'] as $key => $cmp) {
    if (!isset($cmp['error'])) {
        echo sprintf("  %s: %s wins by \$%.2f\n", $key, $cmp['winner'], abs($cmp['avgDiff']));
    }
}

echo sprintf("\nMarket Leader: %s\n", $analysis['market_leader']);
echo sprintf("Total time: %.1fms\n", $analysisTime);

echo "\n=== Batch Stock Report ===\n";
echo "Compare multiple pairs with bounded parallelism.\n\n";

$batchStart = microtime(true);

$batchReport = $scope->execute(new BatchStockReport([
    ['AAPL', 'GOOGL'],
    ['MSFT', 'AMZN'],
    ['META', 'NVDA'],
    ['TSLA', 'NFLX'],
    ['AMD', 'INTC'],
]));

$batchTime = (microtime(true) - $batchStart) * 1000;

echo json_encode($batchReport, JSON_PRETTY_PRINT) . "\n";
echo sprintf("\nBatch time: %.1fms\n", $batchTime);

$scope->dispose();
$app->shutdown();
