<?php

declare(strict_types=1);

/**
 * Hybrid I/O + CPU Pattern
 *
 * Real applications mix I/O-bound and CPU-bound work. The optimal strategy:
 * - I/O operations: concurrent fibers (delays overlap)
 * - CPU operations: worker processes (true parallelism)
 *
 * This example demonstrates the pattern:
 * 1. Concurrently read stock data (I/O - fibers)
 * 2. Offload analysis to workers (CPU - processes)
 * 3. Aggregate results in main process
 *
 * The event loop stays responsive throughout - never blocked by either
 * network latency OR heavy computation.
 *
 * Run: php examples/03-advanced/09-hybrid-io-cpu.php
 */

require_once __DIR__ . '/../_shared/bootstrap.php';

use Convoy\Application;
use Convoy\Examples\Support\AsyncStockReader;
use Convoy\Examples\Support\StockBundle;
use Convoy\Examples\Support\TechnicalAnalyzer;
use Convoy\Examples\Tasks\AnalyzeStock;
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

$symbols = ['AAPL', 'GOOGL', 'MSFT', 'AMZN', 'META', 'NVDA'];

echo "=== Hybrid I/O + CPU: Full Portfolio Analysis ===\n";
echo sprintf("Analyzing %d stocks with technical indicators + Monte Carlo\n\n", count($symbols));

$totalStart = hrtime(true);

$result = $scope->execute(Task::of(
    static function (ExecutionScope $es) use ($symbols): array {
        $reader = $es->service(AsyncStockReader::class);

        echo "Phase 1: Loading stock data (I/O - concurrent fibers)...\n";
        $ioStart = hrtime(true);

        $stockDataTasks = array_map(
            fn($sym) => Task::of(
                static fn(ExecutionScope $inner) => $inner
                    ->service(AsyncStockReader::class)
                    ->readStock($inner, $sym)
            ),
            array_combine($symbols, $symbols)
        );

        $stockData = $es->concurrent($stockDataTasks);
        $ioTime = (hrtime(true) - $ioStart) / 1e6;

        printf("  Loaded %d stocks in %.1fms (concurrent I/O)\n\n", count($stockData), $ioTime);

        $pricesBySymbol = [];
        foreach ($stockData as $symbol => $data) {
            $pricesBySymbol[$symbol] = TechnicalAnalyzer::extractPrices($data['rows']);
        }

        echo "Phase 2: Technical Analysis (CPU - worker process)...\n";
        $analysisStart = hrtime(true);

        $analyses = [];
        foreach ($symbols as $sym) {
            $analyses[$sym] = $es->inWorker(new AnalyzeStock($sym, $pricesBySymbol[$sym]));
        }

        $analysisTime = (hrtime(true) - $analysisStart) / 1e6;
        printf("  Analyzed %d stocks in %.1fms (worker offload)\n\n", count($analyses), $analysisTime);

        echo "Phase 3: Monte Carlo Simulations (CPU - worker process)...\n";
        $simStart = hrtime(true);

        $simulations = [];
        foreach ($symbols as $sym) {
            $simulations[$sym] = $es->inWorker(new SimulatePrice($sym, $pricesBySymbol[$sym], 30, 3000));
        }

        $simTime = (hrtime(true) - $simStart) / 1e6;
        printf("  Simulated %d stocks in %.1fms (worker offload)\n\n", count($simulations), $simTime);

        return [
            'analyses' => $analyses,
            'simulations' => $simulations,
            'timing' => [
                'io' => $ioTime,
                'analysis' => $analysisTime,
                'simulation' => $simTime,
            ],
        ];
    }
));

$totalTime = (hrtime(true) - $totalStart) / 1e6;

echo "=== Results ===\n\n";
printf("%-6s | %6s | %7s | %-8s | %s\n", 'Symbol', 'RSI', 'Vol%', 'Signal', '30-Day Range (90% CI)');
echo str_repeat('-', 70) . "\n";

foreach ($symbols as $symbol) {
    $analysis = $result['analyses'][$symbol];
    $sim = $result['simulations'][$symbol];

    printf(
        "%-6s | %6.2f | %6.2f%% | %-8s | %s\n",
        $symbol,
        $analysis['rsi'] ?? 0,
        $analysis['volatility'] ?? 0,
        $analysis['signal'],
        $sim['range30'] ?? 'N/A'
    );
}

echo "\n=== Timing Breakdown ===\n";
printf("I/O (fiber concurrent):     %7.1fms\n", $result['timing']['io']);
printf("Technical Analysis (workers): %7.1fms\n", $result['timing']['analysis']);
printf("Monte Carlo (workers):       %7.1fms\n", $result['timing']['simulation']);
printf("Total:                       %7.1fms\n", $totalTime);

echo "\n";
echo "The hybrid pattern: fibers for I/O concurrency, workers for CPU isolation.\n";
echo "Event loop stays responsive - CPU work runs in separate process.\n";

$scope->dispose();
$app->shutdown();
