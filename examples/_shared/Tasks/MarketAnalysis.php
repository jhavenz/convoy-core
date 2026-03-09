<?php

declare(strict_types=1);

namespace Convoy\Examples\Tasks;

use Convoy\Examples\Support\StockAggregator;
use Convoy\ExecutionScope;
use Convoy\Task\Executable;
use Convoy\Task\Traceable;

final class MarketAnalysis implements Executable, Traceable
{
    public string $traceName {
        get => 'MarketAnalysis';
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        $aggregator = $scope->service(StockAggregator::class);

        $allStocks = $scope->execute(new ReadAllStocks(limit: 5));

        $aggregated = array_map(
            fn($data) => $aggregator->aggregate($data),
            $allStocks
        );

        usort($aggregated, fn($a, $b) => $b['avgClose'] <=> $a['avgClose']);
        $top3 = array_slice($aggregated, 0, 3);

        $comparisons = $scope->concurrent([
            'first_vs_second' => new CompareStocks($top3[0]['symbol'], $top3[1]['symbol']),
            'first_vs_third' => new CompareStocks($top3[0]['symbol'], $top3[2]['symbol']),
            'second_vs_third' => new CompareStocks($top3[1]['symbol'], $top3[2]['symbol']),
        ]);

        return [
            'top3' => $top3,
            'comparisons' => $comparisons,
            'market_leader' => $top3[0]['symbol'],
        ];
    }
}
