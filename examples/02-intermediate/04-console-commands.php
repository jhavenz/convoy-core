<?php

declare(strict_types=1);

/**
 * Console Commands
 *
 * CommandGroup for CLI applications. Commands receive ExecutionScope
 * and return int exit codes. Arguments via $es->attribute('args').
 *
 * Run:
 *   php examples/02-intermediate/04-console-commands.php list
 *   php examples/02-intermediate/04-console-commands.php aggregate
 *   php examples/02-intermediate/04-console-commands.php compare AAPL GOOGL
 *   php examples/02-intermediate/04-console-commands.php top 5
 */

require_once __DIR__ . '/../_shared/bootstrap.php';

use Convoy\Application;
use Convoy\Console\Command;
use Convoy\Console\CommandConfig;
use Convoy\Console\CommandGroup;
use Convoy\Examples\Support\AsyncStockReader;
use Convoy\Examples\Support\StockAggregator;
use Convoy\Examples\Support\StockBundle;
use Convoy\Examples\Tasks\ReadAllStocks;
use Convoy\ExecutionScope;
use Convoy\Runner\ConsoleRunner;
use Convoy\Task\Task;

$app = Application::starting([
    'data_path' => __DIR__ . '/../_shared/Data',
])
    ->providers(new StockBundle())
    ->compile();

$commands = CommandGroup::of([
    'list' => new Command(
        fn: static function (ExecutionScope $es): int {
            $reader = $es->service(AsyncStockReader::class);
            $symbols = $reader->symbols();

            echo "Available symbols:\n";
            foreach ($symbols as $symbol) {
                echo "  - $symbol\n";
            }

            return 0;
        },
        config: new CommandConfig(description: 'List available stock symbols'),
    ),

    'aggregate' => new Command(
        fn: static function (ExecutionScope $es): int {
            $aggregator = $es->service(StockAggregator::class);

            $allStocks = $es->execute(new ReadAllStocks(limit: 5));

            $results = [];
            foreach ($allStocks as $data) {
                $results[] = $aggregator->aggregate($data);
            }

            echo json_encode(['stocks' => $results], JSON_PRETTY_PRINT) . "\n";

            return 0;
        },
        config: new CommandConfig(description: 'Aggregate all stock data'),
    ),

    'compare' => new Command(
        fn: static function (ExecutionScope $es): int {
            $args = $es->attribute('args', []);

            if (count($args) < 2) {
                echo "Usage: compare <symbol1> <symbol2>\n";
                return 1;
            }

            $symbol1 = strtoupper($args[0]);
            $symbol2 = strtoupper($args[1]);
            $aggregator = $es->service(StockAggregator::class);

            $results = $es->concurrent([
                $symbol1 => Task::of(
                    static fn(ExecutionScope $inner) => $inner
                        ->service(AsyncStockReader::class)
                        ->readStock($inner, $symbol1)
                ),
                $symbol2 => Task::of(
                    static fn(ExecutionScope $inner) => $inner
                        ->service(AsyncStockReader::class)
                        ->readStock($inner, $symbol2)
                ),
            ]);

            if ($results[$symbol1]['rows'] === []) {
                echo "Stock not found: $symbol1\n";
                return 1;
            }

            if ($results[$symbol2]['rows'] === []) {
                echo "Stock not found: $symbol2\n";
                return 1;
            }

            $comparison = $aggregator->compare($results[$symbol1], $results[$symbol2]);
            echo json_encode($comparison, JSON_PRETTY_PRINT) . "\n";

            return 0;
        },
        config: new CommandConfig(description: 'Compare two stocks'),
    ),

    'top' => new Command(
        fn: static function (ExecutionScope $es): int {
            $args = $es->attribute('args', []);
            $n = max(1, (int) ($args[0] ?? 5));
            $aggregator = $es->service(StockAggregator::class);

            $allStocks = $es->execute(new ReadAllStocks(limit: 5));
            $top = $aggregator->topByAverage($allStocks, $n);

            echo json_encode(['top' => $top], JSON_PRETTY_PRINT) . "\n";

            return 0;
        },
        config: new CommandConfig(description: 'Show top N stocks by average price'),
    ),
]);

$runner = ConsoleRunner::withCommands($app, $commands);
exit($runner->run($argv));
