<?php

declare(strict_types=1);

/**
 * Worker Basics
 *
 * inWorker() offloads CPU-bound tasks to separate PHP processes.
 * The event loop stays responsive while heavy computation runs in parallel.
 *
 * Key insight: fibers handle I/O concurrency (delays overlap), workers handle
 * CPU parallelism (computations run simultaneously on different cores).
 *
 * This example compares technical analysis in-process vs in-worker,
 * demonstrating worker warm-up and the overhead/benefit tradeoff.
 *
 * Run: php examples/02-intermediate/06-worker-basics.php
 */

require_once __DIR__ . '/../_shared/bootstrap.php';

use Convoy\Application;
use Convoy\Examples\Support\AsyncStockReader;
use Convoy\Examples\Support\StockBundle;
use Convoy\Examples\Support\TechnicalAnalyzer;
use Convoy\Examples\Tasks\AnalyzeStock;
use Convoy\ExecutionScope;
use Convoy\Task\Task;

$app = Application::starting([
    'data_path' => __DIR__ . '/../_shared/Data',
])
    ->providers(new StockBundle())
    ->compile();

$app->startup();
$scope = $app->createScope();

echo "=== Worker Basics: Technical Analysis ===\n\n";

$stockData = $scope->execute(Task::of(
    static fn(ExecutionScope $es) => $es
        ->service(AsyncStockReader::class)
        ->readStock($es, 'AAPL')
));

$prices = TechnicalAnalyzer::extractPrices($stockData['rows']);

echo "Loaded AAPL: " . count($prices) . " data points\n\n";

echo "--- In-Process Analysis ---\n";
$inProcStart = hrtime(true);

$inProcResult = $scope->execute(new AnalyzeStock('AAPL', $prices));

$inProcTime = (hrtime(true) - $inProcStart) / 1e6;
printf("Time: %.2fms\n", $inProcTime);
printf("RSI: %.2f | Signal: %s\n", $inProcResult['rsi'], $inProcResult['signal']);

echo "\n--- First Worker Call (cold start) ---\n";
$coldStart = hrtime(true);

$workerResult = $scope->inWorker(new AnalyzeStock('AAPL', $prices));

$coldTime = (hrtime(true) - $coldStart) / 1e6;
printf("Time: %.2fms (includes process spawn)\n", $coldTime);
printf("RSI: %.2f | Signal: %s\n", $workerResult['rsi'], $workerResult['signal']);

echo "\n--- Second Worker Call (warm) ---\n";
$warmStart = hrtime(true);

$warmResult = $scope->inWorker(new AnalyzeStock('AAPL', $prices));

$warmTime = (hrtime(true) - $warmStart) / 1e6;
printf("Time: %.2fms (reuses existing worker)\n", $warmTime);
printf("RSI: %.2f | Signal: %s\n", $warmResult['rsi'], $warmResult['signal']);

echo "\n--- Third Worker Call (warm) ---\n";
$thirdStart = hrtime(true);

$thirdResult = $scope->inWorker(new AnalyzeStock('AAPL', $prices));

$thirdTime = (hrtime(true) - $thirdStart) / 1e6;
printf("Time: %.2fms\n", $thirdTime);

echo "\n=== Summary ===\n";
printf("In-process:    %.2fms\n", $inProcTime);
printf("Worker (cold): %.2fms (%.1fx overhead)\n", $coldTime, $coldTime / $inProcTime);
printf("Worker (warm): %.2fms (%.1fx vs in-process)\n", $warmTime, $warmTime / $inProcTime);

echo "\nKey insight: Worker overhead is real but amortized across many calls.\n";
echo "Use workers when CPU work blocks the event loop for too long.\n";

$scope->dispose();
$app->shutdown();
