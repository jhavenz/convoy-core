<?php

declare(strict_types=1);

/**
 * Settle and Partial Failures
 *
 * settle() runs all tasks and collects results without short-circuiting on
 * errors. Returns SettlementBag with ergonomic access patterns:
 *
 * - $bag->allOk / $bag->anyOk / $bag->anyErr
 * - $bag->values / $bag->errors
 * - $bag->okKeys / $bag->errKeys
 * - $bag->get($key, $default)
 * - $bag->partition() -> [values, errors]
 * - $bag->mapOk(fn) -> transform successes
 *
 * Run: CONVOY_TRACE=1 php examples/03-advanced/04-settle-errors.php
 */

require_once __DIR__ . '/../_shared/bootstrap.php';

use Convoy\Application;
use Convoy\Examples\Support\AsyncStockReader;
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

echo "=== Settle with Mixed Success/Failure ===\n\n";

$bag = $scope->execute(Task::of(
    static function (ExecutionScope $es): \Convoy\Concurrency\SettlementBag {
        return $es->settle([
            'aapl' => Task::of(
                static fn(ExecutionScope $inner) => $inner
                    ->service(AsyncStockReader::class)
                    ->readStock($inner, 'AAPL')
            ),
            'googl' => Task::of(
                static fn(ExecutionScope $inner) => $inner
                    ->service(AsyncStockReader::class)
                    ->readStock($inner, 'GOOGL')
            ),
            'invalid' => Task::of(
                static fn(ExecutionScope $inner) => throw new RuntimeException('Simulated API error')
            ),
            'fake_stock' => Task::of(
                static fn(ExecutionScope $inner) => $inner
                    ->service(AsyncStockReader::class)
                    ->readStock($inner, 'FAKE')
            ),
        ]);
    }
));

echo "Settlement Stats:\n";
echo "  All OK: " . ($bag->allOk ? 'yes' : 'no') . "\n";
echo "  Any OK: " . ($bag->anyOk ? 'yes' : 'no') . "\n";
echo "  Any Errors: " . ($bag->anyErr ? 'yes' : 'no') . "\n";
echo "  OK Keys: " . implode(', ', $bag->okKeys) . "\n";
echo "  Error Keys: " . implode(', ', $bag->errKeys) . "\n";

echo "\n=== Accessing Values ===\n";

foreach ($bag->values as $key => $value) {
    $count = is_array($value) && isset($value['rows']) ? count($value['rows']) : 'N/A';
    echo "  $key: $count rows\n";
}

echo "\n=== Accessing Errors ===\n";

foreach ($bag->errors as $key => $error) {
    echo "  $key: " . $error->getMessage() . "\n";
}

echo "\n=== Safe Get with Defaults ===\n";

$aaplData = $bag->get('aapl', ['rows' => []]);
$invalidData = $bag->get('invalid', ['error' => 'default']);
$missingData = $bag->get('nonexistent', ['fallback' => true]);

echo "  aapl: " . count($aaplData['rows']) . " rows\n";
echo "  invalid: " . json_encode($invalidData) . "\n";
echo "  nonexistent: " . json_encode($missingData) . "\n";

echo "\n=== Partition into Successes/Failures ===\n";

[$successes, $failures] = $bag->partition();

echo "  Successes: " . count($successes) . "\n";
echo "  Failures: " . count($failures) . "\n";

echo "\n=== Map Over Successes ===\n";

$rowCounts = $bag->mapOk(fn($value, $key) => [
    'key' => $key,
    'rows' => is_array($value) && isset($value['rows']) ? count($value['rows']) : 0,
]);

echo json_encode($rowCounts, JSON_PRETTY_PRINT) . "\n";

$scope->dispose();
$app->shutdown();
