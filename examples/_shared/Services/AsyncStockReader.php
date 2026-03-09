<?php

declare(strict_types=1);

namespace Convoy\Examples\Support;

use Convoy\ExecutionScope;

/**
 * Async-friendly stock data reader with simulated I/O latency.
 *
 * Uses $scope->delay() to simulate network/disk latency in an async-friendly
 * way. This demonstrates concurrency benefits: when multiple reads run in
 * parallel, the delays overlap instead of stacking.
 */
final readonly class AsyncStockReader
{
    public function __construct(
        private string $dataPath,
        private float $minLatency = 0.02,
        private float $maxLatency = 0.08,
    ) {
    }

    /**
     * @return list<string>
     */
    public function symbols(): array
    {
        return ['AAPL', 'GOOGL', 'MSFT', 'AMZN', 'META', 'NVDA', 'TSLA', 'NFLX', 'AMD', 'INTC'];
    }

    /**
     * Read stock data with simulated async latency.
     *
     * @return array{symbol: string, rows: list<array{date: string, open: float, high: float, low: float, close: float, volume: int}>, count?: int, error?: string}
     */
    public function readStock(ExecutionScope $scope, string $symbol): array
    {
        $latency = $this->minLatency + (mt_rand() / mt_getrandmax())
                   * ($this->maxLatency - $this->minLatency);
        $scope->delay($latency);

        $file = $this->dataPath . '/stocks-' . strtolower($symbol) . '.csv';

        if (!file_exists($file)) {
            return ['symbol' => $symbol, 'rows' => [], 'error' => 'not_found'];
        }

        $handle = fopen($file, 'r');

        if ($handle === false) {
            return ['symbol' => $symbol, 'rows' => [], 'error' => 'read_failed'];
        }

        $rows = [];
        fgetcsv($handle, escape: '');

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            if (count($row) < 6) {
                continue;
            }

            $rows[] = [
                'date' => $row[0],
                'open' => (float) $row[1],
                'high' => (float) $row[2],
                'low' => (float) $row[3],
                'close' => (float) $row[4],
                'volume' => (int) $row[5],
            ];
        }

        fclose($handle);

        return ['symbol' => $symbol, 'rows' => $rows, 'count' => count($rows)];
    }
}
