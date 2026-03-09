<?php

declare(strict_types=1);

/**
 * CPU Offload: Monte Carlo Simulations
 *
 * Monte Carlo price simulations are genuinely CPU-intensive. Each simulation
 * runs thousands of iterations of random walks. This is THE use case for
 * worker processes: true parallel computation across CPU cores.
 *
 * This example compares three execution strategies:
 * 1. Sequential in-process: one after another, blocking
 * 2. Concurrent in-process: fibers, but still single CPU
 * 3. Workers: true parallel processes, multiple CPUs
 *
 * Run: php examples/03-advanced/08-cpu-offload.php
 */

require_once __DIR__ . '/../_shared/bootstrap.php';

use Convoy\Application;
use Convoy\Examples\Support\AsyncStockReader;
use Convoy\Examples\Support\StockBundle;
use Convoy\Examples\Support\TechnicalAnalyzer;
use Convoy\Examples\Tasks\SimulatePrice;
use Convoy\ExecutionScope;
use Convoy\Task\Task;

$app = Application::starting([
    'data_path' => __DIR__ . '/../_shared/Data',
])
    ->providers(new StockBundle())
    ->compile();

$app->startup();
$scope = $app->createScope();

$symbols = ['AAPL', 'GOOGL', 'MSFT', 'AMZN', 'META'];
$iterations = 5000;

echo "=== Monte Carlo Simulation: {$iterations} iterations × " . count($symbols) . " stocks ===\n\n";

echo "Loading stock data...\n";
$loadStart = hrtime(true);

$stockPrices = $scope->execute(Task::of(
    static function (ExecutionScope $es) use ($symbols): array {
        $reader = $es->service(AsyncStockReader::class);
        $prices = [];

        $results = $es->concurrent(
            array_map(
                fn($sym) => Task::of(
                    static fn(ExecutionScope $inner) => $inner
                        ->service(AsyncStockReader::class)
                        ->readStock($inner, $sym)
                ),
                array_combine($symbols, $symbols)
            )
        );

        foreach ($results as $symbol => $data) {
            $prices[$symbol] = TechnicalAnalyzer::extractPrices($data['rows']);
        }

        return $prices;
    }
));

$loadTime = (hrtime(true) - $loadStart) / 1e6;
printf("Data loaded in %.1fms (I/O - concurrent fibers)\n\n", $loadTime);

echo "--- Sequential In-Process ---\n";
$seqStart = hrtime(true);
$seqResults = [];

foreach ($symbols as $symbol) {
    $result = $scope->execute(new SimulatePrice($symbol, $stockPrices[$symbol], 30, $iterations));
    $seqResults[$symbol] = $result;
    printf("  %s: %s\n", $symbol, $result['range30'] ?? 'error');
}

$seqTime = (hrtime(true) - $seqStart) / 1e6;
printf("Total: %.1fms\n\n", $seqTime);

echo "--- Concurrent In-Process (fibers, single CPU) ---\n";
$concStart = hrtime(true);

$concResults = $scope->execute(Task::of(
    static function (ExecutionScope $es) use ($symbols, $stockPrices, $iterations): array {
        return $es->concurrent(
            array_map(
                fn($sym) => new SimulatePrice($sym, $stockPrices[$sym], 30, $iterations),
                array_combine($symbols, $symbols)
            )
        );
    }
));

$concTime = (hrtime(true) - $concStart) / 1e6;
foreach ($concResults as $symbol => $result) {
    printf("  %s: %s\n", $symbol, $result['range30'] ?? 'error');
}
printf("Total: %.1fms\n\n", $concTime);

echo "--- Worker (sequential offload, isolated process) ---\n";
$workStart = hrtime(true);

$workResults = [];
foreach ($symbols as $symbol) {
    $result = $scope->inWorker(new SimulatePrice($symbol, $stockPrices[$symbol], 30, $iterations));
    $workResults[$symbol] = $result;
    printf("  %s: %s\n", $symbol, $result['range30'] ?? 'error');
}

$workTime = (hrtime(true) - $workStart) / 1e6;
printf("Total: %.1fms\n\n", $workTime);

echo "=== Performance Summary ===\n";
printf("Sequential:    %7.1fms (baseline)\n", $seqTime);
printf("Concurrent:    %7.1fms (%.2fx vs sequential)\n", $concTime, $seqTime / $concTime);
printf("Worker:        %7.1fms (%.2fx vs sequential)\n", $workTime, $seqTime / $workTime);

echo "\n";
echo "Key insight: Concurrent fibers don't help CPU-bound work (no I/O to overlap).\n";
echo "Workers offload to separate process, keeping the event loop responsive.\n";
echo "\n";
echo "With a worker pool (multiple workers), true parallel speedup is possible.\n";
echo "Current implementation uses single worker - sequential but isolated.\n";

$scope->dispose();
$app->shutdown();
