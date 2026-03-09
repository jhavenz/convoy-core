<?php

declare(strict_types=1);

/**
 * Production HTTP Server
 *
 * Full-featured async HTTP server demonstrating production patterns:
 * - Multiple route groups
 * - Error handling
 * - Concurrent API calls
 * - Request timeouts
 * - Health checks
 *
 * Run: php examples/03-advanced/07-production-server.php
 *
 * Endpoints:
 *   GET /health              - Health check
 *   GET /api/stocks          - All stocks aggregated
 *   GET /api/stocks/{symbol} - Single stock
 *   GET /api/compare/{a}/{b} - Compare two stocks
 *   GET /api/analysis        - Full market analysis
 */

require_once __DIR__ . '/../_shared/bootstrap.php';

use Convoy\Application;
use Convoy\Examples\Support\AsyncStockReader;
use Convoy\Examples\Support\StockAggregator;
use Convoy\Examples\Support\StockBundle;
use Convoy\Examples\Tasks\CompareStocks;
use Convoy\Examples\Tasks\ReadAllStocks;
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
        fn: static fn(ExecutionScope $es) => [
            'status' => 'ok',
            'time' => date('c'),
            'uptime' => time() - ($_SERVER['REQUEST_TIME'] ?? time()),
        ],
    ),

    'GET /api/stocks' => new Route(
        fn: static function (ExecutionScope $es): array {
            $aggregator = $es->service(StockAggregator::class);
            $start = microtime(true);

            try {
                $allStocks = $es->execute(new ReadAllStocks(limit: 5));

                $results = array_map(
                    fn($data) => $aggregator->aggregate($data),
                    $allStocks
                );

                return [
                    'stocks' => $results,
                    'count' => count($results),
                    'elapsed_ms' => round((microtime(true) - $start) * 1000, 1),
                ];
            } catch (Throwable $e) {
                return [
                    'error' => 'Failed to fetch stocks',
                    'message' => $e->getMessage(),
                ];
            }
        },
    ),

    'GET /api/stocks/{symbol}' => new Route(
        fn: static function (ExecutionScope $es): array {
            $symbol = strtoupper((string) $es->attribute('route.symbol'));
            $reader = $es->service(AsyncStockReader::class);
            $aggregator = $es->service(StockAggregator::class);

            try {
                $data = $reader->readStock($es, $symbol);

                if (isset($data['error']) || $data['rows'] === []) {
                    return ['error' => "Stock not found: $symbol"];
                }

                $stats = $aggregator->aggregate($data);

                return [
                    'symbol' => $symbol,
                    'stats' => $stats,
                    'latest' => $data['rows'][count($data['rows']) - 1] ?? null,
                ];
            } catch (Throwable $e) {
                return [
                    'error' => "Failed to fetch $symbol",
                    'message' => $e->getMessage(),
                ];
            }
        },
    ),

    'GET /api/compare/{a}/{b}' => new Route(
        fn: static function (ExecutionScope $es): array {
            $a = strtoupper((string) $es->attribute('route.a'));
            $b = strtoupper((string) $es->attribute('route.b'));

            try {
                $result = $es->execute(new CompareStocks($a, $b));

                if (isset($result['error'])) {
                    return $result;
                }

                return [
                    'comparison' => $result,
                    'recommendation' => $result['winner'] . ' has higher average close price',
                ];
            } catch (Throwable $e) {
                return [
                    'error' => 'Comparison failed',
                    'message' => $e->getMessage(),
                ];
            }
        },
    ),

    'GET /api/analysis' => new Route(
        fn: static function (ExecutionScope $es): array {
            $aggregator = $es->service(StockAggregator::class);
            $start = microtime(true);

            try {
                $allStocks = $es->timeout(5.0, new ReadAllStocks(limit: 5));

                $aggregated = array_map(
                    fn($data) => $aggregator->aggregate($data),
                    $allStocks
                );

                usort($aggregated, fn($a, $b) => $b['avgClose'] <=> $a['avgClose']);
                $top3 = array_slice($aggregated, 0, 3);

                $comparisons = $es->concurrent([
                    '1v2' => new CompareStocks($top3[0]['symbol'], $top3[1]['symbol']),
                    '1v3' => new CompareStocks($top3[0]['symbol'], $top3[2]['symbol']),
                ]);

                $totalVolume = array_sum(array_column($aggregated, 'totalVolume'));

                return [
                    'analysis' => [
                        'top3' => $top3,
                        'leader' => $top3[0]['symbol'],
                        'total_market_volume' => $totalVolume,
                        'comparisons' => $comparisons,
                    ],
                    'meta' => [
                        'stocks_analyzed' => count($aggregated),
                        'elapsed_ms' => round((microtime(true) - $start) * 1000, 1),
                    ],
                ];
            } catch (Throwable $e) {
                return [
                    'error' => 'Analysis failed',
                    'message' => $e->getMessage(),
                ];
            }
        },
    ),
]);

echo "=== Production Stock API Server ===\n\n";
echo "Starting on http://0.0.0.0:8093\n\n";
echo "Endpoints:\n";
echo "  GET /health               - Health check\n";
echo "  GET /api/stocks           - All stocks aggregated\n";
echo "  GET /api/stocks/{symbol}  - Single stock (AAPL, GOOGL, etc.)\n";
echo "  GET /api/compare/{a}/{b}  - Compare two stocks\n";
echo "  GET /api/analysis         - Full market analysis\n\n";
echo "Try:\n";
echo "  curl http://localhost:8093/api/stocks\n";
echo "  curl http://localhost:8093/api/compare/AAPL/GOOGL\n";
echo "  curl http://localhost:8093/api/analysis\n\n";

$runner = HttpRunner::withRoutes($app, $routes, requestTimeout: 10.0);
exit($runner->run('0.0.0.0:8093'));
