<?php

declare(strict_types=1);

namespace Convoy\Examples\Support;

use Convoy\Service\ServiceBundle;
use Convoy\Service\Services;

final class StockBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $services->singleton(AsyncStockReader::class)
            ->factory(fn() => new AsyncStockReader(
                $context['data_path'] ?? __DIR__ . '/../Data'
            ));

        $services->singleton(StockAggregator::class)
            ->factory(fn() => new StockAggregator());

        $services->singleton(TechnicalAnalyzer::class)
            ->factory(fn() => new TechnicalAnalyzer());

        $services->singleton(MonteCarloSimulator::class)
            ->factory(fn() => new MonteCarloSimulator());
    }
}
