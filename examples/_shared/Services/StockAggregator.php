<?php

declare(strict_types=1);

namespace Convoy\Examples\Support;

class StockAggregator
{
    /**
     * @param array{symbol: string, rows: list<array{date: string, open: float, high: float, low: float, close: float, volume: int}>} $stockData
     * @return array{symbol: string, avgClose: float, minClose: float, maxClose: float, totalVolume: int, days: int}
     */
    public function aggregate(array $stockData): array
    {
        $rows = $stockData['rows'];

        if ($rows === []) {
            return [
                'symbol' => $stockData['symbol'],
                'avgClose' => 0.0,
                'minClose' => 0.0,
                'maxClose' => 0.0,
                'totalVolume' => 0,
                'days' => 0,
            ];
        }

        $closes = array_column($rows, 'close');
        $volumes = array_column($rows, 'volume');

        return [
            'symbol' => $stockData['symbol'],
            'avgClose' => round(array_sum($closes) / count($closes), 2),
            'minClose' => min($closes),
            'maxClose' => max($closes),
            'totalVolume' => array_sum($volumes),
            'days' => count($rows),
        ];
    }

    /**
     * @param array{symbol: string, rows: list<array{date: string, open: float, high: float, low: float, close: float, volume: int}>} $stock1
     * @param array{symbol: string, rows: list<array{date: string, open: float, high: float, low: float, close: float, volume: int}>} $stock2
     * @return array{stocks: array{0: string, 1: string}, avgDiff: float, volatilityRatio: float, winner: string}
     */
    public function compare(array $stock1, array $stock2): array
    {
        $agg1 = $this->aggregate($stock1);
        $agg2 = $this->aggregate($stock2);

        $volatility1 = $agg1['maxClose'] - $agg1['minClose'];
        $volatility2 = $agg2['maxClose'] - $agg2['minClose'];

        return [
            'stocks' => [$agg1['symbol'], $agg2['symbol']],
            'avgDiff' => round($agg1['avgClose'] - $agg2['avgClose'], 2),
            'volatilityRatio' => $volatility2 > 0 ? round($volatility1 / $volatility2, 2) : 0.0,
            'winner' => $agg1['avgClose'] >= $agg2['avgClose'] ? $agg1['symbol'] : $agg2['symbol'],
        ];
    }

    /**
     * @param list<array{symbol: string, rows: list<array{date: string, open: float, high: float, low: float, close: float, volume: int}>}> $allStocks
     * @return list<array{symbol: string, avgClose: float, minClose: float, maxClose: float, totalVolume: int, days: int}>
     */
    public function topByAverage(array $allStocks, int $n): array
    {
        $aggregated = array_map(fn($s) => $this->aggregate($s), $allStocks);

        usort($aggregated, fn($a, $b) => $b['avgClose'] <=> $a['avgClose']);

        return array_slice($aggregated, 0, $n);
    }
}
