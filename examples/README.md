# Convoy Core Examples

Real-world examples demonstrating concurrent data processing with Convoy.

## Setup

```bash
cd packages/convoy-core
composer install
```

## HTTP Server

Concurrent stock price API serving data from 10 CSV files.

```bash
CONVOY_TRACE=1 php examples/http-server.php
```

Endpoints:

```bash
# All stocks - reads 10 CSVs concurrently (bounded to 5 parallel)
curl http://localhost:8080/stocks

# Single stock
curl http://localhost:8080/stocks/AAPL

# Health check
curl http://localhost:8080/health
```

**Patterns demonstrated:**

- `RouteGroup` with route parameter extraction
- `$scope->map()` for bounded parallelism
- Custom trace messages via `$scope->trace()->log()`
- Service resolution via `$scope->service()`

## Console Application

Stock analysis CLI with concurrent data operations.

```bash
# Aggregate all stocks
CONVOY_TRACE=1 php examples/console-app.php aggregate

# Compare two stocks
CONVOY_TRACE=1 php examples/console-app.php compare AAPL GOOGL

# Top N by average price
CONVOY_TRACE=1 php examples/console-app.php top 5
```

**Patterns demonstrated:**

- `CommandGroup` with argument parsing
- `$scope->concurrent()` for parallel fetches
- Invokable task class with `Traceable` interface
- Exit code handling

## Trace Output

With `CONVOY_TRACE=1`, you'll see execution timing:

```
    0ms  STRT  compiling
    2ms  STRT  startup
    3ms  EXEC  aggregate-all
    4ms  CON>    map(10, limit=5)
    5ms  EXEC    Task
    5ms  EXEC    Task
    5ms  EXEC    Task
    5ms  EXEC    Task
    5ms  EXEC    Task
    6ms  DONE    Task  +1.2ms
    6ms  EXEC    Task
    ...
   12ms  CON<    map joined  +8.1ms
   12ms  DONE  aggregate-all  +9.3ms

1 svc  4.2MB peak  0 gc  14.5ms total
```

## Data Files

The `data/` directory contains stubbed stock prices for:
AAPL, GOOGL, MSFT, AMZN, META, NVDA, TSLA, NFLX, AMD, INTC

Each CSV has 20 rows of daily price data (open, high, low, close, volume).
