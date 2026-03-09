<?php

declare(strict_types=1);

/**
 * Stock Price Console Application Example
 *
 * Demonstrates:
 * - CommandGroup with concurrent data operations
 * - $scope->concurrent() for parallel CSV reads
 * - Command argument handling via $scope->attribute('args')
 * - Invokable task classes with Traceable interface
 * - Return int exit codes
 *
 * Run:
 *   CONVOY_TRACE=1 php examples/console-app.php aggregate
 *   CONVOY_TRACE=1 php examples/console-app.php compare AAPL GOOGL
 *   CONVOY_TRACE=1 php examples/console-app.php top 5
 */

require_once __DIR__ . '/bootstrap.php';

use Convoy\Application;
use Convoy\Console\Command;
use Convoy\Console\CommandConfig;
use Convoy\Console\CommandGroup;
use Convoy\Examples\Support\StockAggregator;
use Convoy\Examples\Support\StockBundle;
use Convoy\Examples\Support\StockDataReader;
use Convoy\ExecutionScope;
use Convoy\Runner\ConsoleRunner;
use Convoy\Scope;
use Convoy\Service\ServiceBundle;
use Convoy\Service\Services;
use Convoy\Task\Dispatchable;
use Convoy\Task\Task;
use Convoy\Task\Traceable;
use Convoy\Trace\TraceType;

/**
 * Invokable task class demonstrating Traceable interface.
 */
final class ReadAllStocks implements Dispatchable, Traceable
{
    public string $traceName {
        get => 'ReadAllStocks';
    }

    /** @param Scope&ExecutionScope $scope */
    public function __invoke(Scope $scope): mixed
    {
        assert($scope instanceof ExecutionScope);

        $reader = $scope->service(StockDataReader::class);
        $symbols = $reader->symbols();

        return $scope->map(
            $symbols,
            static fn(string $sym) => Task::of(
                static fn(ExecutionScope $inner) => $inner->service(StockDataReader::class)->readStock($sym)
            ),
            limit: 5,
        );
    }
}

$app = Application::starting([
    'CONVOY_TRACE' => getenv('CONVOY_TRACE') ?: '1',
    'data_path' => __DIR__ . '/data',
])
    ->providers(new class() implements ServiceBundle {
        public function services(Services $services, array $context): void
        {
            $services->singleton(StockDataReader::class)
                ->factory(fn() => new StockDataReader(
                    $context['data_path'] ?? __DIR__ . '/../data'
                ));

            $services->singleton(StockAggregator::class)
                ->factory(fn() => new StockAggregator());
        }
    })
    ->compile();

$commands = CommandGroup::of([
    'aggregate' => new Command(
        fn: static function (ExecutionScope $es): int {
            $aggregator = $es->service(StockAggregator::class);

            $es->trace()->log(TraceType::Executing, 'aggregate-all');

            $allStocks = $es->execute(new ReadAllStocks());

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

            $reader = $es->service(StockDataReader::class);
            $aggregator = $es->service(StockAggregator::class);

            $es->trace()->log(TraceType::Executing, "compare-$symbol1-$symbol2");

            [$stock1, $stock2] = $es->concurrent([
                $symbol1 => Task::of(
                    static fn(ExecutionScope $inner) => $inner->service(StockDataReader::class)->readStock($symbol1)
                ),
                $symbol2 => Task::of(
                    static fn(ExecutionScope $inner) => $inner->service(StockDataReader::class)->readStock($symbol2)
                ),
            ]);

            if ($stock1['rows'] === []) {
                echo "Stock not found: $symbol1\n";
                return 1;
            }

            if ($stock2['rows'] === []) {
                echo "Stock not found: $symbol2\n";
                return 1;
            }

            $comparison = $aggregator->compare($stock1, $stock2);

            echo json_encode($comparison, JSON_PRETTY_PRINT) . "\n";

            return 0;
        },
        config: new CommandConfig(description: 'Compare two stocks'),
    ),

    'top' => new Command(
        fn: static function (ExecutionScope $es): int {
            $args = $es->attribute('args', []);
            $n = (int) ($args[0] ?? 5);

            if ($n < 1) {
                $n = 5;
            }

            $aggregator = $es->service(StockAggregator::class);

            $es->trace()->log(TraceType::Executing, "top-$n");

            $allStocks = $es->execute(new ReadAllStocks());

            $top = $aggregator->topByAverage($allStocks, $n);

            echo json_encode(['top' => $top], JSON_PRETTY_PRINT) . "\n";

            return 0;
        },
        config: new CommandConfig(description: 'Show top N stocks by average price'),
    ),
]);

$runner = ConsoleRunner::withCommands($app, $commands);
exit($runner->run($argv));
