<?php

declare(strict_types=1);

namespace Convoy\Examples\Tasks;

use Convoy\Examples\Support\MonteCarloSimulator;
use Convoy\Scope;
use Convoy\Task\Scopeable;
use Convoy\Task\Traceable;

/**
 * Monte Carlo price simulation for a single stock.
 *
 * THE canonical worker task: genuinely CPU-intensive with configurable
 * iteration count. Default 10,000 iterations blocks the event loop for
 * measurable time. Perfect demonstration of why workers exist.
 *
 * @implements Scopeable
 */
final class SimulatePrice implements Scopeable, Traceable
{
    public string $traceName {
        get => "SimulatePrice({$this->symbol}, {$this->iterations} iterations)";
    }

    /**
     * @param string $symbol Stock symbol for identification
     * @param list<float> $prices Historical closing prices (oldest first)
     * @param int $days Number of days to simulate forward
     * @param int $iterations Number of Monte Carlo iterations
     */
    public function __construct(
        public readonly string $symbol,
        public readonly array $prices,
        public readonly int $days = 30,
        public readonly int $iterations = 10000,
    ) {
    }

    /**
     * @return array{
     *     symbol: string,
     *     days: int,
     *     iterations: int,
     *     current: float,
     *     mean: float,
     *     p5: float,
     *     p25: float,
     *     p50: float,
     *     p75: float,
     *     p95: float,
     *     minSim: float,
     *     maxSim: float,
     *     range30: string
     * }|array{symbol: string, error: string}
     */
    public function __invoke(Scope $scope): array
    {
        $simulator = $scope->service(MonteCarloSimulator::class);

        $result = $simulator->simulate($this->prices, $this->days, $this->iterations);

        if ($result === null) {
            return [
                'symbol' => $this->symbol,
                'error' => 'insufficient_data',
            ];
        }

        return [
            'symbol' => $this->symbol,
            'days' => $this->days,
            'iterations' => $this->iterations,
            ...$result,
            'range30' => sprintf('$%.2f - $%.2f', $result['p5'], $result['p95']),
        ];
    }
}
