<?php

declare(strict_types=1);

/**
 * Service Resolution
 *
 * Convoy uses ServiceBundle to register services with the container.
 * Services are resolved via $scope->service(ClassName::class).
 *
 * Service types:
 * - singleton(): One instance for entire application lifetime
 * - scoped(): New instance per scope (request/command)
 *
 * Run: php examples/01-beginner/03-service-resolution.php
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

$result = $scope->execute(Task::of(
    static function (ExecutionScope $es): array {
        $reader = $es->service(AsyncStockReader::class);
        $aggregator = $es->service(StockAggregator::class);

        $symbols = $reader->symbols();
        $data = $reader->readStock($es, $symbols[0]);
        $stats = $aggregator->aggregate($data);

        return [
            'available_symbols' => $symbols,
            'sample_stats' => $stats,
        ];
    }
));

echo json_encode($result, JSON_PRETTY_PRINT) . "\n";

$scope->dispose();
$app->shutdown();
