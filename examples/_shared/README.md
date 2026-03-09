# Shared Example Infrastructure

Common services, tasks, and data used across all example tiers.

## Services

### AsyncStockReader

Async-friendly stock data reader with simulated I/O latency (20-80ms per read).

```php
$reader = $scope->service(AsyncStockReader::class);
$data = $reader->readStock($scope, 'AAPL');
```

Uses `$scope->delay()` for latency simulation - overlaps when run concurrently.

### StockAggregator

Aggregation and comparison utilities for stock data.

```php
$aggregator = $scope->service(StockAggregator::class);
$stats = $aggregator->aggregate($stockData);
$comparison = $aggregator->compare($stock1, $stock2);
$top5 = $aggregator->topByAverage($allStocks, 5);
```

### StockBundle

ServiceBundle that registers both services:

```php
$app = Application::starting(['data_path' => __DIR__ . '/_shared/Data'])
    ->providers(new StockBundle())
    ->compile();
```

## Tasks

### ReadAllStocks

Executable task that reads all 10 stocks with bounded parallelism.

```php
$allStocks = $scope->execute(new ReadAllStocks(limit: 5));
```

### CompareStocks

Executable task that fetches two stocks concurrently and compares them.

```php
$comparison = $scope->execute(new CompareStocks('AAPL', 'GOOGL'));
```

## Data

10 CSV files with 120 trading days each (roughly 6 months of data):

- `stocks-aapl.csv` - Apple Inc.
- `stocks-googl.csv` - Alphabet Inc.
- `stocks-msft.csv` - Microsoft Corp.
- `stocks-amzn.csv` - Amazon.com Inc.
- `stocks-meta.csv` - Meta Platforms Inc.
- `stocks-nvda.csv` - NVIDIA Corp.
- `stocks-tsla.csv` - Tesla Inc.
- `stocks-nflx.csv` - Netflix Inc.
- `stocks-amd.csv` - Advanced Micro Devices
- `stocks-intc.csv` - Intel Corp.

Each CSV contains: `date,open,high,low,close,volume`
