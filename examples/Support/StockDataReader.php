<?php

declare(strict_types=1);

namespace Convoy\Examples\Support;

final readonly class StockDataReader
{
    public function __construct(
        private string $dataPath,
    ) {
    }

    /**
     * @return list<string>
     */
    public function symbols(): array
    {
        $files = glob($this->dataPath . '/stocks-*.csv') ?: [];
        $symbols = [];

        foreach ($files as $file) {
            if (preg_match('/stocks-([a-z]+)\.csv$/i', $file, $m)) {
                $symbols[] = strtoupper($m[1]);
            }
        }

        sort($symbols);
        return $symbols;
    }

    /**
     * @return array{symbol: string, rows: list<array{date: string, open: float, high: float, low: float, close: float, volume: int}>}
     */
    public function readStock(string $symbol): array
    {
        $file = $this->dataPath . '/stocks-' . strtolower($symbol) . '.csv';

        if (!file_exists($file)) {
            return ['symbol' => $symbol, 'rows' => []];
        }

        $handle = fopen($file, 'r');

        if ($handle === false) {
            return ['symbol' => $symbol, 'rows' => []];
        }

        $rows = [];
        $header = fgetcsv($handle, escape: '');

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

        return ['symbol' => $symbol, 'rows' => $rows];
    }
}
