# Convoy Core - Async PHP

Convoy is an async coordination library for PHP 8.4+. It replaces callbacks with typed tasks—named computations that carry their own identity, behavior, and lifecycle through a unified execution model built on ReactPHP.

## Installation

```bash
composer require convoy/core
```

Requires PHP 8.4+.

## Quick Start

```php
$app = Application::starting()->providers(new AppBundle())->compile();
$app->startup();

$scope = $app->createScope();

$result = $scope->execute(Task::of(static fn(Scope $s) =>
    $s->service(OrderService::class)->process(42)
));

$scope->dispose();
$app->shutdown();
```

## How It Works

Convoy's model: **Application -> Scope -> Tasks**.

```
Application::starting($context)
    -> compile()           // Validate service graph, create app
    -> startup()           // Run startup hooks, enable shutdown handlers
    -> createScope()       // Create execution context
    -> execute(Task)       // Run typed tasks
    -> dispose()           // Cleanup scope resources
    -> shutdown()          // Cleanup app resources
```

Every task implements `Dispatchable`—a single-method interface:

```php
interface Dispatchable {
    public function __invoke(Scope $scope): mixed;
}
```

The scope provides everything: service resolution, concurrency primitives, cancellation state, disposal hooks. No global state. No service locator. No hidden context.

**Why this matters:**

- **Testable**: Mock the scope, test the task. No framework coupling.
- **Explicit**: Dependencies flow through the argument, not magic injection.
- **Fiber-safe**: Each task runs on its own fiber with the scope automatically available.
- **Composable**: Tasks spawn subtasks with the same signature—`concurrent()`, `race()`, `map()` all pass the scope through.

## The Task System

### Two Ways to Define Tasks

**Quick tasks** for one-offs:

```php
$task = Task::of(static fn(Scope $s) => $s->service(UserRepo::class)->find($id));
$user = $scope->execute($task);
```

**Invokable classes** for everything else:

```php
final readonly class FetchUser implements Dispatchable
{
    public function __construct(private int $id) {}

    public function __invoke(Scope $scope): User
    {
        return $scope->service(UserRepo::class)->find($this->id);
    }
}

$user = $scope->execute(new FetchUser(42));
```

The invokable approach gives you:

- **Traceable**: Stack traces show `FetchUser::__invoke`, not `Closure@handler.php:47`
- **Testable**: Mock the scope, invoke the task, assert the result
- **Serializable**: Constructor args are data—queue jobs, distribute across workers
- **Inspectable**: The class name is the identity; constructor args are the inputs

### Behavior via Interfaces

Tasks declare behavior through PHP 8.4 property hooks:

```php
final class DatabaseQuery implements Dispatchable, Retryable, HasTimeout
{
    public RetryPolicy $retryPolicy {
        get => RetryPolicy::exponential(3);
    }

    public float $timeout {
        get => 5.0;
    }

    public function __invoke(Scope $scope): array
    {
        return $scope->service(Database::class)->query($this->sql);
    }
}
```

The behavior pipeline applies automatically: **timeout wraps retry wraps trace wraps work**.

| Interface | Property | Purpose |
|-----------|----------|---------|
| `Retryable` | `RetryPolicy $retryPolicy { get; }` | Automatic retry with policy |
| `HasTimeout` | `float $timeout { get; }` | Automatic timeout in seconds |
| `HasPriority` | `int $priority { get; }` | Priority queue ordering |
| `UsesPool` | `UnitEnum $pool { get; }` | Pool-aware scheduling |
| `Traceable` | `string $traceName { get; }` | Custom trace label |

### Task Configuration

For `Task::of()` closures, attach behavior via config:

```php
$task = Task::create(
    static fn(Scope $s) => $s->service(Api::class)->fetch($url),
    TaskConfig::default()
        ->withTimeout(5.0)
        ->withRetry(RetryPolicy::exponential(3))
);
```

## Concurrency Primitives

| Method | Behavior | Returns |
|--------|----------|---------|
| `concurrent($tasks)` | Run all in parallel, wait for all | Array of results |
| `race($tasks)` | First to settle (success or failure) | Single result |
| `any($tasks)` | First success (ignores failures) | Single result |
| `map($items, $fn, $limit)` | Bounded parallelism over collection | Array of results |
| `settle($tasks)` | Run all, collect outcomes including failures | SettlementBag |
| `timeout($seconds, $task)` | Run with deadline | Result or throws |
| `series($tasks)` | Sequential execution | Array of results |
| `waterfall($tasks)` | Sequential, passing result forward | Final result |

```php
// Parallel fetch
[$customer, $inventory] = $scope->concurrent([
    new FetchCustomer($customerId),
    new ValidateInventory($items),
]);

// First successful response wins (fallback pattern)
$data = $scope->any([
    new FetchFromPrimary($key),
    new FetchFromFallback($key),
]);

// 10,000 items. 10 concurrent workers.
$results = $scope->map($items, fn($item) => new ProcessItem($item), limit: 10);

// Collect all outcomes, even failures
$bag = $scope->settle([
    'primary' => new FetchFromPrimary($key),
    'backup' => new FetchFromBackup($key),
]);
// $bag->get('primary', $fallback), $bag->allOk, $bag->anyErr
```

## Services

```php
use Convoy\Service\ServiceBundle;
use Convoy\Service\Services;

class AppBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $services->singleton(DatabasePool::class)
            ->factory(fn() => new DatabasePool($context['db_url']))
            ->onStartup(fn($pool) => $pool->warmUp(5))
            ->onShutdown(fn($pool) => $pool->drain());

        $services->singleton(UserRepo::class)
            ->needs(DatabasePool::class)
            ->factory(fn($pool) => new UserRepo($pool));

        $services->scoped(RequestLogger::class)
            ->lazy()
            ->factory(fn() => new RequestLogger())
            ->onDispose(fn($log) => $log->flush());
    }
}
```

**Scoping:**

| Method | Lifecycle |
|--------|-----------|
| `singleton()` | One instance per application |
| `scoped()` | One instance per scope, disposed with scope |
| `eager()` | Singleton created at startup (not lazily) |
| `lazy()` | Defer creation until first access (PHP 8.4 lazy ghosts) |

**Lifecycle hooks:**

| Hook | When | Use case |
|------|------|----------|
| `onInit` | After factory creates instance | Validation, logging |
| `onStartup` | Application startup | Connection warming, cache priming |
| `onDispose` | Scope disposal (reverse order) | Request cleanup |
| `onShutdown` | Application shutdown (reverse order) | Connection draining |

**Compile-time validation catches:**

- Missing dependencies
- Cyclic dependencies
- Singletons depending on scoped services (captive dependency)
- Interface bindings with missing implementations

## Cancellation & Retry

```php
use Convoy\Concurrency\CancellationToken;
use Convoy\Concurrency\RetryPolicy;

// Timeout for entire scope
$scope = $app->createScope(CancellationToken::timeout(30.0));

// Manual cancellation
$token = CancellationToken::create();
$scope = $app->createScope($token);
// Later: $token->cancel();

// Task-level timeout
$result = $scope->timeout(5.0, new SlowApiCall($id));

// Retry with exponential backoff
$result = $scope->retry(
    new FetchFromApi($url),
    RetryPolicy::exponential(attempts: 3)
        ->retryingOn(ConnectionException::class, TimeoutException::class)
);

// Check cancellation within tasks
final class LongRunningTask implements Dispatchable
{
    public function __invoke(Scope $scope): mixed
    {
        foreach ($this->chunks as $chunk) {
            $scope->throwIfCancelled();  // Throws CancelledException
            $this->process($chunk);
        }

        return $this->result;
    }
}
```

## Runners

### HTTP Server

```php
use Convoy\Runner\HttpRunner;

$handler = Task::of(static fn(Scope $s) =>
    $s->service(Router::class)->dispatch($s->attribute('request'))
);

$runner = new HttpRunner($app, $handler, requestTimeout: 30.0);
$runner->run('0.0.0.0:8080');  // Blocks, runs event loop
```

### Console Commands

```php
use Convoy\Runner\ConsoleRunner;

$runner = new ConsoleRunner($app);

$runner->command('migrate', Task::of(static fn(Scope $s) =>
    $s->service(Migrator::class)->run()
));

$runner->command('cache:clear', Task::of(static fn(Scope $s) =>
    $s->service(Cache::class)->clear()
));

exit($runner->run($argv));
```

## Tracing

```bash
CONVOY_TRACE=1 php server.php
```

```
    0ms  STRT  compiling
    4ms  STRT  startup
    4ms  STRT  ready
    6ms  CON>    concurrent(2)
    7ms  EXEC    FetchCustomer
    8ms  DONE    FetchCustomer  +0.61ms
    8ms  EXEC    ValidateInventory
   19ms  DONE    ValidateInventory  +10.6ms
   19ms  CON<    concurrent(2) joined  +12.8ms

0 svc  4.0MB peak  0 gc  39.8ms total
```

Invokable tasks display with their class name. Closures show file and line. Concurrent blocks indent their children.

## Deterministic Cleanup

Resource cleanup in async PHP requires discipline. Convoy treats it as first-class:

**Scope-level cleanup:**

```php
$scope = $app->createScope();

$scope->onDispose(fn() => $connection->close());
$scope->onDispose(fn() => $transaction->rollback());

// Your task code...

$scope->dispose();  // Cleanup fires in reverse order, guaranteed
```

**Task-level cleanup with fresh scopes:**

```php
// executeFresh creates a child scope that auto-disposes after the task
$result = $scope->executeFresh(new ProcessWithTempResources($data));
// Child scope's onDispose hooks fire automatically
```

## What's Next

HTTP client abstractions, database connection pooling, and queue workers are in development. The foundation you learn here carries forward.
