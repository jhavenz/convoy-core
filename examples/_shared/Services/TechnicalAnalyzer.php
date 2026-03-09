<?php

declare(strict_types=1);

namespace Convoy\Examples\Support;

/**
 * CPU-intensive technical indicator calculations.
 *
 * Pure computations with no I/O - ideal for worker offload when processing
 * multiple stocks. Each method operates on price arrays extracted from
 * stock data rows.
 */
final readonly class TechnicalAnalyzer
{
    /**
     * Extract closing prices from stock data rows.
     *
     * @param list<array{close: float, ...}> $rows
     * @return list<float>
     */
    public static function extractPrices(array $rows): array
    {
        return array_map(fn($row) => $row['close'], $rows);
    }

    /**
     * Simple Moving Average.
     *
     * @param list<float> $prices Closing prices (oldest first)
     */
    public function sma(array $prices, int $period): ?float
    {
        if (count($prices) < $period) {
            return null;
        }

        $slice = array_slice($prices, -$period);

        return round(array_sum($slice) / $period, 4);
    }

    /**
     * Exponential Moving Average.
     *
     * @param list<float> $prices Closing prices (oldest first)
     */
    public function ema(array $prices, int $period): ?float
    {
        if (count($prices) < $period) {
            return null;
        }

        $multiplier = 2 / ($period + 1);

        $sma = array_sum(array_slice($prices, 0, $period)) / $period;
        $ema = $sma;

        for ($i = $period; $i < count($prices); $i++) {
            $ema = ($prices[$i] - $ema) * $multiplier + $ema;
        }

        return round($ema, 4);
    }

    /**
     * Relative Strength Index.
     *
     * RSI = 100 - (100 / (1 + RS))
     * RS = Average Gain / Average Loss
     *
     * @param list<float> $prices Closing prices (oldest first)
     */
    public function rsi(array $prices, int $period = 14): ?float
    {
        if (count($prices) < $period + 1) {
            return null;
        }

        $gains = [];
        $losses = [];

        for ($i = 1; $i < count($prices); $i++) {
            $change = $prices[$i] - $prices[$i - 1];
            if ($change > 0) {
                $gains[] = $change;
                $losses[] = 0.0;
            } else {
                $gains[] = 0.0;
                $losses[] = abs($change);
            }
        }

        $avgGain = array_sum(array_slice($gains, 0, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;

        for ($i = $period; $i < count($gains); $i++) {
            $avgGain = (($avgGain * ($period - 1)) + $gains[$i]) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $losses[$i]) / $period;
        }

        if ($avgLoss === 0.0) {
            return 100.0;
        }

        $rs = $avgGain / $avgLoss;

        return round(100 - (100 / (1 + $rs)), 2);
    }

    /**
     * Moving Average Convergence Divergence.
     *
     * MACD Line = 12-period EMA - 26-period EMA
     * Signal Line = 9-period EMA of MACD Line
     * Histogram = MACD Line - Signal Line
     *
     * @param list<float> $prices Closing prices (oldest first)
     * @return array{macd: float|null, signal: float|null, histogram: float|null}
     */
    public function macd(array $prices, int $fast = 12, int $slow = 26, int $signal = 9): array
    {
        if (count($prices) < $slow + $signal) {
            return ['macd' => null, 'signal' => null, 'histogram' => null];
        }

        $macdLine = [];
        $multiplierFast = 2 / ($fast + 1);
        $multiplierSlow = 2 / ($slow + 1);

        $emaFast = array_sum(array_slice($prices, 0, $fast)) / $fast;
        $emaSlow = array_sum(array_slice($prices, 0, $slow)) / $slow;

        for ($i = $slow; $i < count($prices); $i++) {
            $emaFast = ($prices[$i] - $emaFast) * $multiplierFast + $emaFast;
            $emaSlow = ($prices[$i] - $emaSlow) * $multiplierSlow + $emaSlow;
            $macdLine[] = $emaFast - $emaSlow;
        }

        if (count($macdLine) < $signal) {
            return ['macd' => null, 'signal' => null, 'histogram' => null];
        }

        $multiplierSignal = 2 / ($signal + 1);
        $signalLine = array_sum(array_slice($macdLine, 0, $signal)) / $signal;

        for ($i = $signal; $i < count($macdLine); $i++) {
            $signalLine = ($macdLine[$i] - $signalLine) * $multiplierSignal + $signalLine;
        }

        $currentMacd = end($macdLine);
        $histogram = $currentMacd - $signalLine;

        return [
            'macd' => round($currentMacd, 4),
            'signal' => round($signalLine, 4),
            'histogram' => round($histogram, 4),
        ];
    }

    /**
     * Bollinger Bands.
     *
     * Middle = 20-period SMA
     * Upper = Middle + (2 × 20-period standard deviation)
     * Lower = Middle - (2 × 20-period standard deviation)
     *
     * @param list<float> $prices Closing prices (oldest first)
     * @return array{upper: float|null, middle: float|null, lower: float|null, width: float|null}
     */
    public function bollingerBands(array $prices, int $period = 20, float $stdDevMultiplier = 2.0): array
    {
        if (count($prices) < $period) {
            return ['upper' => null, 'middle' => null, 'lower' => null, 'width' => null];
        }

        $slice = array_slice($prices, -$period);
        $middle = array_sum($slice) / $period;

        $squaredDiffs = array_map(
            fn($price) => ($price - $middle) ** 2,
            $slice
        );
        $stdDev = sqrt(array_sum($squaredDiffs) / $period);

        $upper = $middle + ($stdDevMultiplier * $stdDev);
        $lower = $middle - ($stdDevMultiplier * $stdDev);

        $width = $middle > 0 ? (($upper - $lower) / $middle) * 100 : 0;

        return [
            'upper' => round($upper, 4),
            'middle' => round($middle, 4),
            'lower' => round($lower, 4),
            'width' => round($width, 2),
        ];
    }

    /**
     * Historical Volatility (annualized).
     *
     * @param list<float> $prices Closing prices (oldest first)
     */
    public function volatility(array $prices, int $period = 20): ?float
    {
        if (count($prices) < $period + 1) {
            return null;
        }

        $slice = array_slice($prices, -($period + 1));

        $returns = [];
        for ($i = 1; $i < count($slice); $i++) {
            if ($slice[$i - 1] > 0) {
                $returns[] = log($slice[$i] / $slice[$i - 1]);
            }
        }

        if (count($returns) < 2) {
            return null;
        }

        $meanReturn = array_sum($returns) / count($returns);
        $squaredDiffs = array_map(
            fn($r) => ($r - $meanReturn) ** 2,
            $returns
        );
        $variance = array_sum($squaredDiffs) / (count($returns) - 1);
        $dailyVol = sqrt($variance);

        $annualizedVol = $dailyVol * sqrt(252);

        return round($annualizedVol * 100, 2);
    }
}
