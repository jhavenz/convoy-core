<?php

declare(strict_types=1);

/**
 * Stock Price HTTP Server Example
 *
 * Demonstrates:
 * - RouteGroup with concurrent data fetching
 * - $scope->concurrent() for parallel CSV reads
 * - $scope->map() for bounded parallelism
 * - Route parameter extraction via $scope->attribute('route.symbol')
 * - Custom trace messages via $scope->trace()->log()
 *
 * Run: CONVOY_TRACE=1 php examples/http-server.php
 *
 * Endpoints:
 *   GET /stocks         - All stocks aggregated from 10 CSVs concurrently
 *   GET /stocks/{symbol} - Single stock data
 *   GET /health         - Health check
 */

require_once __DIR__ . '/bootstrap.php';

use Convoy\Application;
use Convoy\Examples\Support\StockAggregator;
use Convoy\Examples\Support\StockBundle;
use Convoy\Examples\Support\StockDataReader;
use Convoy\ExecutionScope;
use Convoy\Http\Route;
use Convoy\Http\RouteGroup;
use Convoy\Runner\HttpRunner;
use Convoy\Task\Task;
use Convoy\Trace\TraceType;

$app = Application::starting([
    'CONVOY_TRACE' => getenv('CONVOY_TRACE') ?: '1',
    'data_path' => __DIR__ . '/data',
])
    ->providers(new StockBundle())
    ->compile();

$routes = RouteGroup::of([
    'GET /health' => new Route(
        fn: static fn(ExecutionScope $es) => ['status' => 'ok', 'time' => date('c')],
    ),

    'GET /stocks' => new Route(
        fn: static function (ExecutionScope $es): array {
            $reader = $es->service(StockDataReader::class);
            $aggregator = $es->service(StockAggregator::class);

            $es->trace()->log(TraceType::Executing, 'loading-symbols');

            $symbols = $reader->symbols();

            $es->trace()->log(TraceType::Done, 'loading-symbols', ['count' => count($symbols)]);

            /** @var array<string, array{symbol: string, rows: list<array{date: string, open: float, high: float, low: float, close: float, volume: int}>}> $stockData */
            $stockData = $es->map(
                $symbols,
                static fn(string $sym) => Task::of(
                    static fn(ExecutionScope $inner) => $inner->service(StockDataReader::class)->readStock($sym)
                ),
                limit: 5,
            );

            $results = [];

            foreach ($stockData as $data) {
                $results[] = $aggregator->aggregate($data);
            }

            return ['stocks' => $results];
        },
    ),

    'GET /stocks/{symbol}' => new Route(
        fn: static function (ExecutionScope $es): array {
            $symbol = strtoupper((string) $es->attribute('route.symbol'));
            $reader = $es->service(StockDataReader::class);
            $aggregator = $es->service(StockAggregator::class);

            $es->trace()->log(TraceType::Executing, "reading-$symbol");

            $data = $reader->readStock($symbol);

            if ($data['rows'] === []) {
                return ['error' => "Stock not found: $symbol"];
            }

            return $aggregator->aggregate($data);
        },
    ),
]);

$runner = HttpRunner::withRoutes($app, $routes, requestTimeout: 30.0);
exit($runner->run('0.0.0.0:8080'));
