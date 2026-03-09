<?php

declare(strict_types=1);

namespace Convoy\Examples\Tasks;

use Convoy\Examples\Support\AsyncStockReader;
use Convoy\Examples\Support\StockAggregator;
use Convoy\ExecutionScope;
use Convoy\Task\Executable;
use Convoy\Task\Task;
use Convoy\Task\Traceable;

/**
 * Parallel stock comparison demonstrating concurrent() usage.
 *
 * Fetches two stocks simultaneously, then compares them.
 * Total time ~= single stock read, not two reads sequentially.
 */
final class CompareStocks implements Executable, Traceable
{
    public function __construct(
        private string $symbol1,
        private string $symbol2,
    ) {
    }

    public string $traceName {
        get => "CompareStocks({$this->symbol1}vs{$this->symbol2})";
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        $aggregator = $scope->service(StockAggregator::class);
        $sym1 = $this->symbol1;
        $sym2 = $this->symbol2;

        $results = $scope->concurrent([
            $sym1 => Task::of(
                static fn(ExecutionScope $inner) => $inner
                    ->service(AsyncStockReader::class)
                    ->readStock($inner, $sym1)
            ),
            $sym2 => Task::of(
                static fn(ExecutionScope $inner) => $inner
                    ->service(AsyncStockReader::class)
                    ->readStock($inner, $sym2)
            ),
        ]);

        $stock1 = $results[$sym1];
        $stock2 = $results[$sym2];

        if (isset($stock1['error']) || $stock1['rows'] === []) {
            return ['error' => "Stock not found: {$this->symbol1}"];
        }

        if (isset($stock2['error']) || $stock2['rows'] === []) {
            return ['error' => "Stock not found: {$this->symbol2}"];
        }

        return $aggregator->compare($stock1, $stock2);
    }
}
