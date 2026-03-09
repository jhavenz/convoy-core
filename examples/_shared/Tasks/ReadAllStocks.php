<?php

declare(strict_types=1);

namespace Convoy\Examples\Tasks;

use Convoy\Examples\Support\AsyncStockReader;
use Convoy\ExecutionScope;
use Convoy\Task\Executable;
use Convoy\Task\Task;
use Convoy\Task\Traceable;

/**
 * Parallel stock reader demonstrating bounded concurrency.
 *
 * Reads all stock CSVs concurrently with a limit to avoid overwhelming
 * the system. Each read has simulated latency - running in parallel
 * makes total time ~= slowest single read, not sum of all reads.
 */
final class ReadAllStocks implements Executable, Traceable
{
    public function __construct(
        private int $limit = 5,
    ) {
    }

    public string $traceName {
        get => 'ReadAllStocks';
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        $reader = $scope->service(AsyncStockReader::class);
        $symbols = $reader->symbols();

        return $scope->map(
            $symbols,
            static fn(string $sym) => Task::of(
                static fn(ExecutionScope $inner) => $inner
                    ->service(AsyncStockReader::class)
                    ->readStock($inner, $sym)
            ),
            limit: $this->limit,
        );
    }
}
