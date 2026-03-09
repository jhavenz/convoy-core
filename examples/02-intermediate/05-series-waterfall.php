<?php

declare(strict_types=1);

/**
 * Series and Waterfall
 *
 * When tasks must run sequentially (not concurrently):
 * - series(): Run tasks in order, collect all results
 * - waterfall(): Run tasks in order, pass each result to next
 *
 * Use cases:
 * - Database transactions requiring order
 * - API calls where each needs the previous result
 * - Pipeline processing
 *
 * Run: CONVOY_TRACE=1 php examples/02-intermediate/05-series-waterfall.php
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

echo "=== Series: Sequential with All Results ===\n";

$seriesResults = $scope->execute(Task::of(
    static function (ExecutionScope $es): array {
        return $es->series([
            Task::of(static fn(ExecutionScope $inner) => [
                'step' => 1,
                'action' => 'Fetch AAPL',
                'data' => $inner->service(AsyncStockReader::class)->readStock($inner, 'AAPL'),
            ]),
            Task::of(static fn(ExecutionScope $inner) => [
                'step' => 2,
                'action' => 'Fetch GOOGL',
                'data' => $inner->service(AsyncStockReader::class)->readStock($inner, 'GOOGL'),
            ]),
            Task::of(static fn(ExecutionScope $inner) => [
                'step' => 3,
                'action' => 'Fetch MSFT',
                'data' => $inner->service(AsyncStockReader::class)->readStock($inner, 'MSFT'),
            ]),
        ]);
    }
));

echo "Series returned " . count($seriesResults) . " results:\n";
foreach ($seriesResults as $result) {
    echo sprintf("  Step %d: %s (%d rows)\n",
        $result['step'], $result['action'], count($result['data']['rows']));
}

echo "\n=== Waterfall: Pipeline Processing ===\n";
echo "(Previous result accessed via \$es->attribute('_waterfall_previous'))\n\n";

$waterfallResult = $scope->execute(Task::of(
    static function (ExecutionScope $es): array {
        return $es->waterfall([
            Task::of(static function (ExecutionScope $inner) {
                $reader = $inner->service(AsyncStockReader::class);
                return [
                    'symbols' => $reader->symbols(),
                    'selected' => [],
                ];
            }),

            Task::of(static function (ExecutionScope $inner) {
                $prev = $inner->attribute('_waterfall_previous');
                $reader = $inner->service(AsyncStockReader::class);
                $first3 = array_slice($prev['symbols'], 0, 3);
                $stocks = [];
                foreach ($first3 as $sym) {
                    $stocks[$sym] = $reader->readStock($inner, $sym);
                }
                return [
                    'stocks' => $stocks,
                    'count' => count($stocks),
                ];
            }),

            Task::of(static function (ExecutionScope $inner) {
                $prev = $inner->attribute('_waterfall_previous');
                $aggregator = $inner->service(StockAggregator::class);
                $aggregated = [];
                foreach ($prev['stocks'] as $symbol => $data) {
                    $aggregated[$symbol] = $aggregator->aggregate($data);
                }
                return [
                    'aggregated' => $aggregated,
                    'pipeline_complete' => true,
                ];
            }),
        ]);
    }
));

echo "Waterfall final result:\n";
echo json_encode($waterfallResult, JSON_PRETTY_PRINT) . "\n";

$scope->dispose();
$app->shutdown();
