# Convoy Core Examples

Progressive examples demonstrating Convoy's async coordination capabilities.

## Quick Start

```bash
# Run any example
php examples/01-beginner/01-hello-task.php

# With tracing enabled
CONVOY_TRACE=1 php examples/02-intermediate/01-concurrent-basics.php
```

## Tiers

### 01-Beginner

Foundational concepts for getting started with Convoy.

| Example | Description |
|---------|-------------|
| `01-hello-task.php` | Minimal Convoy app lifecycle |
| `02-invokable-task.php` | Scopeable vs Executable classes |
| `03-service-resolution.php` | ServiceBundle and dependency injection |
| `04-simple-http-route.php` | Single route HTTP server |

### 02-Intermediate

Concurrency primitives and handler patterns.

| Example | Description |
|---------|-------------|
| `01-concurrent-basics.php` | `concurrent()` parallel execution |
| `02-map-with-limits.php` | Bounded parallelism with `map()` |
| `03-http-routes.php` | RouteGroup with concurrent data |
| `04-console-commands.php` | CommandGroup patterns |
| `05-series-waterfall.php` | Sequential execution modes |

### 03-Advanced

Production patterns and complex orchestration.

| Example | Description |
|---------|-------------|
| `01-retry-policies.php` | Retryable interface, backoff strategies |
| `02-timeouts.php` | HasTimeout, `$scope->timeout()` |
| `03-cancellation.php` | CancellationToken patterns |
| `04-settle-errors.php` | SettlementBag, partial failures |
| `05-race-any.php` | First-wins patterns |
| `06-composite-tasks.php` | Nested concurrent operations |
| `07-production-server.php` | Full HTTP server |

## Shared Infrastructure

The `_shared/` directory contains common services and data:

- **Services**: `AsyncStockReader`, `StockAggregator`, `StockBundle`
- **Tasks**: `ReadAllStocks`, `CompareStocks`
- **Data**: 10 stock CSVs with 120 trading days each

See `_shared/README.md` for details.

## Key Concepts

### Async via Simulated Latency

`AsyncStockReader` uses `$scope->delay()` to simulate I/O latency. This demonstrates:

- Sequential reads: delays stack (100ms + 100ms = 200ms)
- Concurrent reads: delays overlap (100ms || 100ms = ~100ms)

### Scope Types

- **Scope**: Service resolution, attributes, tracing
- **ExecutionScope**: Full capabilities including concurrency primitives

### Task Types

- **Scopeable**: Tasks needing only service resolution
- **Executable**: Tasks needing concurrency primitives

### Concurrency Primitives

```php
$scope->concurrent([...])  // All in parallel, wait for all
$scope->map($items, $fn)   // Bounded parallelism
$scope->race([...])        // First to settle wins
$scope->any([...])         // First success wins
$scope->settle([...])      // All complete, collect errors
$scope->series([...])      // Sequential, all results
$scope->waterfall([...])   // Sequential, chained results
```

## Trace Output

With `CONVOY_TRACE=1`, you'll see execution timing:

```
    0ms  STRT  compiling
    2ms  STRT  startup
    3ms  EXEC  ReadAllStocks
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
   12ms  DONE  ReadAllStocks  +9.3ms

1 svc  4.2MB peak  0 gc  14.5ms total
```
