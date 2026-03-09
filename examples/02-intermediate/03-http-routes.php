<?php

declare(strict_types=1);

/**
 * HTTP Routes with Concurrent Data
 *
 * Multiple routes demonstrating different concurrency patterns:
 * - /stocks: map() for bounded parallel fetches
 * - /stocks/{symbol}: single async read
 * - /compare/{a}/{b}: concurrent() for parallel comparison
 *
 * Run: php examples/02-intermediate/03-http-routes.php
 * Test: curl http://localhost:8092/stocks
 *       curl http://localhost:8092/stocks/AAPL
 *       curl http://localhost:8092/compare/AAPL/GOOGL
 */

require_once __DIR__ . '/../_shared/bootstrap.php';

use Convoy\Application;
use Convoy\Examples\Support\AsyncStockReader;
use Convoy\Examples\Support\StockAggregator;
use Convoy\Examples\Support\StockBundle;
use Convoy\ExecutionScope;
use Convoy\Http\Route;
use Convoy\Http\RouteGroup;
use Convoy\Runner\HttpRunner;
use Convoy\Task\Task;

$app = Application::starting([
    'data_path' => __DIR__ . '/../_shared/Data',
])
    ->providers(new StockBundle())
    ->compile();

$routes = RouteGroup::of([
    'GET /health' => new Route(
        fn: static fn(ExecutionScope $es) => ['status' => 'ok', 'time' => date('c')],
    ),

    'GET /stocks' => new Route(
        fn: static function (ExecutionScope $es): array {
            $reader = $es->service(AsyncStockReader::class);
            $aggregator = $es->service(StockAggregator::class);

            $stockData = $es->map(
                $reader->symbols(),
                static fn(string $sym) => Task::of(
                    static fn(ExecutionScope $inner) => $inner
                        ->service(AsyncStockReader::class)
                        ->readStock($inner, $sym)
                ),
                limit: 5,
            );

            return [
                'stocks' => array_map(
                    fn($data) => $aggregator->aggregate($data),
                    $stockData
                ),
            ];
        },
    ),

    'GET /stocks/{symbol}' => new Route(
        fn: static function (ExecutionScope $es): array {
            $symbol = strtoupper((string) $es->attribute('route.symbol'));
            $reader = $es->service(AsyncStockReader::class);
            $aggregator = $es->service(StockAggregator::class);

            $data = $reader->readStock($es, $symbol);

            if (isset($data['error']) || $data['rows'] === []) {
                return ['error' => "Stock not found: $symbol"];
            }

            return $aggregator->aggregate($data);
        },
    ),

    'GET /compare/{a}/{b}' => new Route(
        fn: static function (ExecutionScope $es): array {
            $a = strtoupper((string) $es->attribute('route.a'));
            $b = strtoupper((string) $es->attribute('route.b'));
            $aggregator = $es->service(StockAggregator::class);

            $results = $es->concurrent([
                $a => Task::of(
                    static fn(ExecutionScope $inner) => $inner
                        ->service(AsyncStockReader::class)
                        ->readStock($inner, $a)
                ),
                $b => Task::of(
                    static fn(ExecutionScope $inner) => $inner
                        ->service(AsyncStockReader::class)
                        ->readStock($inner, $b)
                ),
            ]);

            if ($results[$a]['rows'] === [] || $results[$b]['rows'] === []) {
                return ['error' => 'One or both stocks not found'];
            }

            return $aggregator->compare($results[$a], $results[$b]);
        },
    ),
]);

echo "Starting server on http://0.0.0.0:8092\n";
echo "Endpoints:\n";
echo "  GET /stocks           - All stocks (concurrent)\n";
echo "  GET /stocks/{symbol}  - Single stock\n";
echo "  GET /compare/{a}/{b}  - Compare two stocks\n";

$runner = HttpRunner::withRoutes($app, $routes);
exit($runner->run('0.0.0.0:8092'));
