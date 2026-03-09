<?php

declare(strict_types=1);

namespace Convoy\Examples\Tasks;

use Convoy\Examples\Support\TechnicalAnalyzer;
use Convoy\Scope;
use Convoy\Task\Scopeable;
use Convoy\Task\Traceable;

/**
 * Full technical analysis for a single stock.
 *
 * Accepts pre-loaded prices (no I/O) and runs CPU-intensive indicator
 * calculations. Ideal for worker offload when analyzing multiple stocks
 * in parallel.
 *
 * @implements Scopeable
 */
final class AnalyzeStock implements Scopeable, Traceable
{
    public string $traceName {
        get => "AnalyzeStock({$this->symbol})";
    }

    /**
     * @param string $symbol Stock symbol for identification
     * @param list<float> $prices Closing prices (oldest first)
     */
    public function __construct(
        public readonly string $symbol,
        public readonly array $prices,
    ) {
    }

    private function deriveSignal(
        ?float $rsi,
        array $macd,
        ?float $sma20,
        ?float $sma50,
    ): string {
        $bullish = 0;
        $bearish = 0;

        if ($rsi !== null) {
            if ($rsi < 30) {
                $bullish++;
            } elseif ($rsi > 70) {
                $bearish++;
            }
        }

        if ($macd['histogram'] !== null) {
            if ($macd['histogram'] > 0) {
                $bullish++;
            } else {
                $bearish++;
            }
        }

        if ($sma20 !== null && $sma50 !== null) {
            if ($sma20 > $sma50) {
                $bullish++;
            } else {
                $bearish++;
            }
        }

        if ($bullish > $bearish) {
            return 'BULLISH';
        }

        if ($bearish > $bullish) {
            return 'BEARISH';
        }

        return 'NEUTRAL';
    }

    /**
     * @return array{
     *     symbol: string,
     *     currentPrice: float,
     *     rsi: float|null,
     *     macd: array{macd: float|null, signal: float|null, histogram: float|null},
     *     sma20: float|null,
     *     sma50: float|null,
     *     ema12: float|null,
     *     ema26: float|null,
     *     bollinger: array{upper: float|null, middle: float|null, lower: float|null, width: float|null},
     *     volatility: float|null,
     *     signal: string
     * }
     */
    public function __invoke(Scope $scope): array
    {
        $analyzer = $scope->service(TechnicalAnalyzer::class);

        $rsi = $analyzer->rsi($this->prices);
        $macd = $analyzer->macd($this->prices);
        $sma20 = $analyzer->sma($this->prices, 20);
        $sma50 = $analyzer->sma($this->prices, 50);
        $bollinger = $analyzer->bollingerBands($this->prices);

        $signal = $this->deriveSignal($rsi, $macd, $sma20, $sma50);

        $prices = $this->prices;

        return [
            'symbol' => $this->symbol,
            'currentPrice' => end($prices) ?: 0.0,
            'rsi' => $rsi,
            'macd' => $macd,
            'sma20' => $sma20,
            'sma50' => $sma50,
            'ema12' => $analyzer->ema($this->prices, 12),
            'ema26' => $analyzer->ema($this->prices, 26),
            'bollinger' => $bollinger,
            'volatility' => $analyzer->volatility($this->prices),
            'signal' => $signal,
        ];
    }
}
