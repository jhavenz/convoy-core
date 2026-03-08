# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Run all tests
composer test

# Run specific test file
php vendor/bin/phpunit tests/Integration/Task/TaskExecutionTest.php

# Run specific test method
php vendor/bin/phpunit --filter testExecutesBasicTask

# Code style check
composer cs

# Fix code style
composer cs:fix

# Static analysis (if configured)
composer rector:dry     # preview
composer rector         # apply
```

## Architecture

### Core Primitives (v2)

```
Application         ->  compiles service graph, creates scopes
    |
Scope (ExecutionScope)  ->  concurrency primitives, service resolution, disposal
    |
Dispatchable        ->  typed tasks with behavior contracts
```

### Task System

**The fundamental shift in v2:** Tasks are first-class citizens with identity.

```php
// Dispatchable: the core task interface
interface Dispatchable {
    public function __invoke(Scope $scope): mixed;
}

// Task: static closure wrapper via factory (private constructor)
$task = Task::of(static fn(Scope $s) => $s->service(Db::class)->query(...));

// Task with config
$task = Task::create(
    static fn(Scope $s) => $s->service(Db::class)->query(...),
    TaskConfig::default()->withTimeout(5.0)->withRetry(RetryPolicy::exponential(3))
);

// Invokable class: named, inspectable, serializable (preferred)
final class FetchUser implements Dispatchable {
    public function __construct(private int $id) {}
    public function __invoke(Scope $scope): User { /* ... */ }
}
```

### Behavior Interfaces (PHP 8.4 property hooks)

Tasks declare behavior via interfaces:

| Interface | Property | Purpose |
|-----------|----------|---------|
| `Retryable` | `RetryPolicy $retryPolicy { get; }` | Automatic retry with policy |
| `HasTimeout` | `float $timeout { get; }` | Automatic timeout in seconds |
| `UsesPool` | `UnitEnum $pool { get; }` | Pool-aware scheduling |
| `HasPriority` | `int $priority { get; }` | Priority queue ordering |
| `Traceable` | `string $traceName { get; }` | Custom trace label |

```php
final class DatabaseQuery implements Dispatchable, Retryable, HasTimeout {
    public RetryPolicy $retryPolicy {
        get => RetryPolicy::exponential(3);
    }

    public float $timeout {
        get => 5.0;
    }

    public function __invoke(Scope $scope): array { /* ... */ }
}
```

The behavior pipeline applies automatically: `timeout wraps retry wraps trace wraps work`

### Scope Methods (v2 signatures)

All methods now accept `Dispatchable` instead of `callable`:

| Method | Behavior |
|--------|----------|
| `execute(Dispatchable)` | Execute with behavior pipeline |
| `executeFresh(Dispatchable)` | Execute in isolated child scope |
| `concurrent(Dispatchable[])` | `Promise\all()` - wait for all |
| `race(Dispatchable[])` | `Promise\race()` - first to settle |
| `any(Dispatchable[])` | `Promise\any()` - first success |
| `map($items, Closure)` | Bounded parallelism (returns Dispatchable) |
| `settle(Dispatchable[])` | All outcomes as `Settlement` |
| `series(Dispatchable[])` | Sequential execution |
| `waterfall(Dispatchable[])` | Sequential with result passing via attribute |
| `timeout(float, Dispatchable)` | Run with timeout |
| `retry(Dispatchable, RetryPolicy)` | Run with retry (prefer interface) |
| `defer(Dispatchable)` | Fire-and-forget |

### Scope Attributes

Context passes through the execution graph via attributes:

```php
$scope = $scope->withAttribute('requestId', $id);
$requestId = $scope->attribute('requestId');
```

Waterfall uses `_waterfall_previous` attribute automatically.

### Service System

**Unchanged from v1.** Registration flow:
```
ServiceBundle.services()  ->  ServiceCatalog  ->  ServiceGraphCompiler  ->  ServiceGraph
```

Key files:
- `src/Service/ServiceGraphCompiler.php` - validation and compilation
- `src/Service/LazySingleton.php` - application-scoped instances
- `src/Service/DeferredScope.php` - request-scoped instances (now with Dispatchable methods)
- `src/Service/LazyFactory.php` - PHP 8.4 ReflectionClass::newLazyGhost

### Orchestration Components

| Component | Purpose |
|-----------|---------|
| `TaskScheduler` | Priority + pool-aware batch execution |
| `LazySequence` | Generator-based streaming with bounded concurrency |
| `Collect` | Terminal: collect sequence to array |
| `Reduce` | Terminal: reduce with accumulator |
| `First` | Terminal: first element |
| `ManagedResource` | WeakMap-based resource cleanup |

```php
// LazySequence example
$results = LazySequence::from(fn($s) => fetchPages())
    ->filter(fn($page) => $page->hasData())
    ->mapConcurrent(fn($page) => processPage($page), concurrency: 5)
    ->take(100)
    ->collect();

$scope->execute($results);
```

### Runners

| Runner | Use Case |
|--------|----------|
| `HttpRunner` | ReactPHP HTTP server with per-request scopes |
| `ConsoleRunner` | CLI command dispatch |

```php
// HTTP
$runner = new HttpRunner($app, new RequestHandler());
$runner->run('0.0.0.0:8080');

// Console
$runner = new ConsoleRunner($app);
$runner->command('migrate', new MigrateCommand());
$runner->run($argv);
```

## Key Patterns

### Task signature

```php
// Static closure via factory (enforced - constructor is private)
Task::of(static fn(Scope $s) => $s->service(SomeService::class)->doWork());

// With config (timeout, retry, priority, etc.)
Task::create(
    static fn(Scope $s) => $s->service(SomeService::class)->doWork(),
    TaskConfig::default()->withTimeout(10.0)
);

// Invokable class (preferred for reusable tasks)
final class DoWork implements Dispatchable {
    public function __invoke(Scope $scope): mixed {
        return $scope->service(SomeService::class)->doWork();
    }
}
```

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

$app->startup();
$scope = $app->createScope();
$result = $scope->execute(new MyTask());
$scope->dispose();
$app->shutdown();
```

## PHP 8.4+ Usage

This codebase uses:
- Property hooks (`public RetryPolicy $retryPolicy { get => ... }`)
- `readonly` classes
- `array_any()` for short-circuit checks
- `ReflectionClass::newLazyGhost()` for lazy services
- `ReflectionClass::newLazyProxy()` for managed resources

## Migration from v1

| v1 | v2 |
|----|-----|
| `resolve(callable)` | `execute(Dispatchable)` |
| `resolveFresh(callable)` | `executeFresh(Dispatchable)` |
| `concurrent([callable...])` | `concurrent([Dispatchable...])` |
| `timeout(float, callable)` | `timeout(float, Dispatchable)` |
| `retry(callable, policy)` | `retry(Dispatchable, policy)` |
| N/A | `defer(Dispatchable)` |
| N/A | `withAttribute(key, value)` |
| N/A | `attribute(key, default)` |

**Static factory pattern:** `Task::of()` and `Task::create()` - constructor is private. Rejects non-static closures to prevent reference cycles.

**Behavior via interfaces:** Instead of manual `retry()` calls, implement `Retryable` on your task class.

**Middleware rename:** `ServiceTransform` -> `ServiceTransformationMiddleware`, `ApplicationBuilder.middleware()` -> `serviceMiddleware()`. Old names are deprecated aliases.

## Code Style

**Formal comments:** Use `/** */` docblock syntax for all intentional design decisions, even single-line. This distinguishes deliberate choices from accidental omissions.

```php
/** Intentionally captures $this - runner is process-scoped, no leak risk. */
private function createShutdownHandler(): callable { ... }
```

**Windows tests:** When testing Windows-specific code paths (e.g., periodic timer shutdown polling), use PHPUnit's `#[RequiresOperatingSystem('Windows')]` attribute.
