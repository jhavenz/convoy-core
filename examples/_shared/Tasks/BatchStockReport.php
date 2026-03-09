<?php

declare(strict_types=1);

namespace Convoy\Examples\Tasks;

use Convoy\ExecutionScope;
use Convoy\Task\Executable;
use Convoy\Task\Traceable;

final class BatchStockReport implements Executable, Traceable
{
    public function __construct(
        /** @var list<array{0: string, 1: string}> */
        private array $pairs,
    ) {
    }

    public string $traceName {
        get => 'BatchStockReport';
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        $results = $scope->map(
            $this->pairs,
            fn(array $pair) => new CompareStocks($pair[0], $pair[1]),
            limit: 3,
        );

        $summary = [];
        foreach ($results as $idx => $comparison) {
            if (!isset($comparison['error'])) {
                $summary[] = [
                    'pair' => $this->pairs[$idx],
                    'winner' => $comparison['winner'],
                    'avgDiff' => $comparison['avgDiff'],
                ];
            }
        }

        return [
            'comparisons' => count($summary),
            'results' => $summary,
        ];
    }
}
