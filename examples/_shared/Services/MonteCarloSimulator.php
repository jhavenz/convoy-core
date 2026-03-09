<?php

declare(strict_types=1);

namespace Convoy\Examples\Support;

/**
 * Monte Carlo price simulation engine.
 *
 * Genuinely CPU-intensive: runs thousands of iterations to project price
 * distributions. Each iteration uses geometric Brownian motion with
 * historical volatility parameters. This is the canonical "offload to worker"
 * use case - pure computation that blocks the event loop.
 */
final readonly class MonteCarloSimulator
{
    /**
     * Run Monte Carlo simulation for future price projection.
     *
     * Uses geometric Brownian motion:
     *   S(t+1) = S(t) × exp((μ - σ²/2)Δt + σ√Δt × Z)
     *
     * where Z is standard normal random variable.
     *
     * @param list<float> $prices Historical closing prices (oldest first)
     * @param int $days Number of days to simulate forward
     * @param int $iterations Number of simulation runs
     * @return array{
     *     current: float,
     *     mean: float,
     *     p5: float,
     *     p25: float,
     *     p50: float,
     *     p75: float,
     *     p95: float,
     *     minSim: float,
     *     maxSim: float
     * }|null
     */
    public function simulate(array $prices, int $days = 30, int $iterations = 10000): ?array
    {
        if (count($prices) < 20) {
            return null;
        }

        $returns = [];
        for ($i = 1; $i < count($prices); $i++) {
            if ($prices[$i - 1] > 0) {
                $returns[] = log($prices[$i] / $prices[$i - 1]);
            }
        }

        if (count($returns) < 10) {
            return null;
        }

        $mu = array_sum($returns) / count($returns);
        $squaredDiffs = array_map(fn($r) => ($r - $mu) ** 2, $returns);
        $variance = array_sum($squaredDiffs) / (count($returns) - 1);
        $sigma = sqrt($variance);

        $drift = $mu - ($sigma ** 2 / 2);

        $currentPrice = end($prices);
        $finalPrices = [];

        for ($i = 0; $i < $iterations; $i++) {
            $price = $currentPrice;

            for ($d = 0; $d < $days; $d++) {
                $z = $this->boxMullerTransform();
                $dailyReturn = exp($drift + $sigma * $z);
                $price *= $dailyReturn;
            }

            $finalPrices[] = $price;
        }

        sort($finalPrices);

        return [
            'current' => round($currentPrice, 2),
            'mean' => round(array_sum($finalPrices) / $iterations, 2),
            'p5' => round($this->percentile($finalPrices, 5), 2),
            'p25' => round($this->percentile($finalPrices, 25), 2),
            'p50' => round($this->percentile($finalPrices, 50), 2),
            'p75' => round($this->percentile($finalPrices, 75), 2),
            'p95' => round($this->percentile($finalPrices, 95), 2),
            'minSim' => round(min($finalPrices), 2),
            'maxSim' => round(max($finalPrices), 2),
        ];
    }

    /**
     * Box-Muller transform: generates standard normal random variable.
     */
    private function boxMullerTransform(): float
    {
        $u1 = mt_rand() / mt_getrandmax();
        $u2 = mt_rand() / mt_getrandmax();

        if ($u1 === 0.0) {
            $u1 = 0.0001;
        }

        return sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
    }

    /**
     * Calculate percentile from sorted array.
     *
     * @param list<float> $sorted Must be pre-sorted ascending
     */
    private function percentile(array $sorted, int $p): float
    {
        $index = ($p / 100) * (count($sorted) - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $fraction = $index - $lower;

        if ($lower === $upper) {
            return $sorted[$lower];
        }

        return $sorted[$lower] + $fraction * ($sorted[$upper] - $sorted[$lower]);
    }
}
