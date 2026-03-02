# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Run all tests
composer test

# Run specific test file
php vendor/bin/phpunit tests/Integration/Scope/ConcurrentTest.php

# Run specific test method
php vendor/bin/phpunit --filter testConcurrentExecutesAllTasks

# Code style check
composer cs

# Fix code style
composer cs:fix

# Static analysis (if configured)
composer rector:dry     # preview
composer rector         # apply
```

## Architecture

### Core Primitives

```
Application         →  compiles service graph, creates scopes
    ↓
Scope (ExecutionScope)  →  concurrency primitives, service resolution, disposal
    ↓
ServiceGraph        →  validated service definitions with dependency order
```

### Service System

**Registration flow:**
```
ServiceBundle.services()  →  ServiceCatalog  →  ServiceGraphCompiler  →  ServiceGraph
```

**Compile-time validation** (`ServiceGraphCompiler`):
- Missing dependencies
- Cyclic dependencies (DFS traversal)
- Singleton-to-scoped violations (captive dependency)

**Scoping:**
- `singleton()` - one per application, in `LazySingleton`
- `scoped()` - one per scope, in `DeferredScope`
- `eager()` - created at startup
- `lazy()` - PHP 8.4 lazy ghosts via `LazyFactory`

**Key files:**
- `src/Service/ServiceGraphCompiler.php` - validation and compilation
- `src/Service/LazySingleton.php` - application-scoped instances
- `src/Service/DeferredScope.php` - request-scoped instances
- `src/Service/LazyFactory.php` - PHP 8.4 ReflectionClass::newLazyGhost

### Concurrency

All primitives live in `ExecutionScope.php`:

| Method | Behavior |
|--------|----------|
| `concurrent($tasks)` | `Promise\all()` - wait for all |
| `race($tasks)` | `Promise\race()` - first to settle |
| `any($tasks)` | `Promise\any()` - first success |
| `map($items, $fn, $limit)` | bounded parallelism via semaphore |
| `settle($tasks)` | all outcomes as `Settlement` |
| `series($tasks)` | sequential execution |
| `waterfall($tasks)` | sequential with result passing |

Tasks are wrapped in fibers, registered with `FiberScopeRegistry` for cancellation propagation.

### Cancellation

```php
CancellationToken::timeout(30.0)  // auto-cancel after 30s
CancellationToken::create()       // manual cancel via ->cancel()
CancellationToken::none()         // never cancels
```

Scope checks `$this->cancellation->isCancelled` before work. Child scopes inherit parent tokens.

### Retry

`RetryPolicy` supports:
- `exponential(attempts, baseDelayMs, maxDelayMs)` - 2^n backoff with 10% jitter
- `linear(attempts, baseDelayMs, maxDelayMs)`
- `fixed(attempts, delayMs)`
- `->retryingOn(Exception::class, ...)` - filter retryable exceptions

Never retries `CancelledException`.

### Tracing

`CONVOY_TRACE=1` enables trace output:
```
[0.12ms] ServiceInit: DatabasePool
[1.24ms] ConcurrentStart: parallel(3)
[1.89ms] ConcurrentEnd: parallel(3) joined
```

Programmatic: `$scope->trace()->entries()`, `->print()`, `->toArray()`

### Runners

- `HttpRunner` - ReactPHP HTTP server with per-request scopes
- `ConsoleRunner` - CLI command dispatch with single scope

### Exception Hierarchy

- `CancelledException` - timeout or manual cancellation
- `CompositeException` - aggregates multiple failures (concurrent operations)
- `CyclicDependencyException` - service graph cycle detected
- `InvalidServiceConfigurationException` - missing deps, captive dependency
- `ServiceNotFoundException` - unknown service type

## Test Structure

```
tests/
├── Integration/       # Full Application → Scope → service resolution
│   ├── Application/
│   ├── Cancellation/
│   ├── Middleware/
│   ├── Scope/
│   └── ServiceGraph/
├── Unit/              # Isolated component tests
│   └── Concurrency/
└── Support/
    └── Fixtures/      # Test service bundles, stubs
```

## Patterns

### Task signature

```php
fn(Scope $s) => $s->service(SomeService::class)->doWork()
```

The closure receives the scope, accesses services via `->service()`, returns result.

### Service bundle

```php
class AppBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $services->singleton(DbPool::class)
            ->factory(fn() => new DbPool($context['db_url']))
            ->onStartup(fn($p) => $p->warm())
            ->onShutdown(fn($p) => $p->drain());

        $services->scoped(RequestLogger::class)
            ->lazy()
            ->onDispose(fn($log) => $log->flush());
    }
}
```

### Application bootstrap

```php
$app = Application::starting(['db_url' => '...'])
    ->providers(new AppBundle())
    ->compile();

$app->startup();                          // runs onStartup hooks
$scope = $app->createScope();
$result = $scope->concurrent([...]);
$scope->dispose();                        // runs onDispose hooks
$app->shutdown();                         // runs onShutdown hooks
```

## PHP 8.4+ Usage

This codebase uses:
- Property hooks (`public bool $isCancelled { get => ... }`)
- `readonly` classes
- `array_any()` for short-circuit checks
- `ReflectionClass::newLazyGhost()` for lazy services

## Memory & Cleanup

- `WeakMap` in `FiberScopeRegistry` for fiber-to-scope tracking
- Disposal callbacks fire in reverse registration order
- `DeferredScope` clears instance map on dispose
- No `__destruct` reliance - explicit `dispose()` calls
