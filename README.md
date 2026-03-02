# Convoy Core - Async PHP

Convoy is an async coordination library for PHP 8.4+. It provides a unified task model built on ReactPHP where service resolution, concurrency primitives, and cleanup all flow through a single abstraction: the scoped environment.

## Installation

```bash
composer require convoy/core
```

Requires PHP 8.4+.

## Quick Start

```php
$app = Application::starting()->providers(new AppBundle())->compile();
$scope = $app->createScope();

$items = [['sku' => 'WIDGET-001', 'qty' => 2]];

[$customer, $inventory] = $scope->concurrent([
    fn($s) => $s->service(CustomerRepo::class)->find(42),
    fn($s) => $s->service(ProductRepo::class)->validateStock($items),
]);

$scope->dispose();
```

## How It Works

Convoy's model is simple: **Application -> Scope -> Tasks**.

```
Application::starting($context)
    -> compile()           // Validate service graph, create app
    -> createScope()       // Create execution context
    -> resolve/concurrent  // Run tasks
    -> dispose()           // Cleanup scope resources
    -> shutdown()          // Cleanup app resources
```

Every task receives `Scope` as its first argument. This is the unified signature throughout Convoy:

```php
fn(Scope $s) => $s->service(UserRepo::class)->find($id)
```

The scoped environment provides everything a task needs: service resolution, concurrency primitives, cancellation state, and disposal hooks. No global state. No service locator pattern. No hidden context.

**Why this matters:**

- **Testable**: Mock the scope, test the task. No framework coupling.
- **Explicit**: Dependencies flow through the argument, not magic injection.
- **Fiber-safe**: Each task runs on its own fiber with the scope automatically available.
- **Composable**: Tasks can spawn subtasks with the same signature—`concurrent()`, `race()`, `map()` all pass the scope through.

The scope is your execution context. Create one per request, per job, or per unit of work. When the scope disposes, everything it created disposes with it—in reverse order, deterministically.

A task is any callable that receives `Scope`. Closures for one-offs; invokable classes for everything else.

## Why Convoy?

### Invokable Tasks: Computations with Identity

The core abstraction is the **invokable task**—a computation that carries its own context.

```php
final readonly class FulfillOrder
{
    public function __construct(
        private int $customerId,
        private array $items,
    ) {}

    public function __invoke(Scope $scope): FulfillmentResult
    {
        [$customer, $inventory] = $scope->concurrent([
            new FetchCustomer($this->customerId),
            new ValidateInventory($this->items),
        ]);

        if (!$inventory->available) {
            throw new InsufficientStockException($inventory->issues);
        }

        $scope->resolve(new ReserveInventory($inventory->items));

        $scope->onDispose(fn() => $scope->service(InventoryService::class)->release($inventory->items));

        [$shipping, $order] = $scope->concurrent([
            new GetShippingQuote($customer->address, $inventory->weight),
            new CreateOrder($customer, $inventory->items),
        ]);

        $payment = $scope->retry(
            new ProcessPayment($customer, $inventory->total + $shipping->cost),
            RetryPolicy::exponential(attempts: 3)
        );

        return new FulfillmentResult($order, $shipping, $payment);
    }
}
```

Each task—`FetchCustomer`, `ValidateInventory`, `ReserveInventory`—is a named class with typed constructor arguments. The class name is the identity. The constructor captures the inputs. The `__invoke()` method defines the computation.

**What this enables:**

- **Traceable**: Stack traces and logs show `FetchCustomer::__invoke`, not `Closure@handler.php:47`
- **Testable**: Mock the scope, invoke the task, assert the result
- **Serializable**: Constructor args are data—queue jobs, distribute across workers, replay failed operations
- **Composable**: Tasks resolve other tasks; `FulfillOrder` orchestrates five subtasks through the same scope

Tasks receive the scope; they don't create it. This separation means the same task runs identically in an HTTP request, a queue worker, or a test harness.

### The Container Problem

Traditional DI containers validate at runtime. A singleton depending on a scoped service? You discover it when production explodes.

Convoy validates the service graph at compile time:

```
InvalidServiceConfigurationException:
Singleton 'OrderService' cannot depend on scoped 'RequestContext'
Dependency cycle would cause captive dependency.
```

Caught at compile. Not in production.

### Deterministic Cleanup

Resource cleanup in async PHP requires discipline. Connections can leak. Transactions can hang open. `__destruct` timing is unpredictable in long-running processes.

Convoy treats cleanup as a first-class concern:

**Scope-level cleanup:**

```php
$scope = $app->createScope();

$scope->onDispose(fn() => $connection->close());
$scope->onDispose(fn() => $transaction->rollback());

// Your task code...

$scope->dispose();  // Cleanup fires in reverse order, guaranteed
```

**Service-level cleanup:**

```php
$services->scoped(RequestLogger::class)
    ->factory(fn() => new RequestLogger())
    ->onDispose(fn($log) => $log->flush());  // Automatic on scope disposal

$services->singleton(ConnectionPool::class)
    ->factory(fn() => new ConnectionPool())
    ->onShutdown(fn($pool) => $pool->drain());  // Automatic on app shutdown
```

**Task-level cleanup with fresh scopes:**

```php
// resolveFresh creates a child scope that auto-disposes after the task
$result = $scope->resolveFresh(fn($s) => $s->service(TempResource::class)->process());
// TempResource's onDispose hooks fire here, automatically
```

Cleanup logic stays with resource acquisition, not scattered across error handlers.

## Concurrency Primitives (if you know react/async:^4, these will look familiar)

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
// First successful response wins (fallback pattern)
$data = $scope->any([
    fn($s) => $s->service(PrimaryApi::class)->fetch($key),
    fn($s) => $s->service(FallbackApi::class)->fetch($key),
]);

// 10,000 items. 10 concurrent workers. One line.
$results = $scope->map($items, fn($item) => fn($s) =>
    $s->service(Processor::class)->process($item)
, limit: 10);

// Pipeline with intermediate results
$final = $scope->waterfall([
    fn($s, $prev) => fetchData(),
    fn($s, $data) => transformData($data),
    fn($s, $transformed) => saveData($transformed),
]);

// Collect all outcomes, even failures
$bag = $scope->settle([
    'primary' => fn($s) => $s->service(PrimaryApi::class)->fetch($key),
    'backup' => fn($s) => $s->service(BackupApi::class)->fetch($key),
]);
// $bag->get('primary', $fallback), $bag->allOk, $bag->anyErr, $bag->values
```

## Services

Concurrency without lifecycle management creates different problems: connection pools that never drain, caches that never warm, loggers that never flush. Services solve this.

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

$app = Application::starting(['db_url' => '...'])
    ->providers(new AppBundle())
    ->compile();
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
$result = $scope->timeout(5.0, fn($s) =>
    $s->service(SlowApi::class)->fetch($id)
);

// Retry with exponential backoff
$result = $scope->retry(
    fn($s) => $s->service(ApiClient::class)->fetch($url),
    RetryPolicy::exponential(attempts: 3)
        ->retryingOn(ConnectionException::class, TimeoutException::class)
);

// Check cancellation within tasks
$scope->resolve(function($s) {
    $s->throwIfCancelled();  // Throws CancelledException

    if ($s->isCancelled) {
        return $partialResult;  // Graceful early exit
    }
});
```

## Runners

Convoy core provides foundational runner implementations. These APIs are evolving—expect refinements as the library matures. The patterns shown here work today; the ergonomics will improve.

Build on these directly, or wait for higher-level abstractions from the Convoy ecosystem.

### HTTP Server

```php
use Convoy\Runner\HttpRunner;

$runner = new HttpRunner(
    app: $app,
    host: '0.0.0.0',
    port: 8080,
    handler: function($request, $scope) {
        return $scope->resolve(fn($s) =>
            $s->service(Router::class)->dispatch($request)
        );
    },
    requestTimeout: 30.0,
);

$runner->run();  // Blocks, runs event loop
```

### Console Commands

```php
use Convoy\Runner\ConsoleRunner;

$runner = new ConsoleRunner($app, [
    'migrate' => fn($scope, $args) => $scope->resolve(
        fn($s) => $s->service(Migrator::class)->run()
    ),
    'cache:clear' => fn($scope, $args) => $scope->resolve(
        fn($s) => $s->service(Cache::class)->clear()
    ),
]);

exit($runner->run($argv));
```

## Symfony Runtime Integration

Convoy is designed around `symfony/runtime` for maximum flexibility. The runtime component decouples your application from PHP's superglobals (`$_GET`, `$_POST`, `$_SERVER`)—a critical separation for long-running processes and async applications where request state must not leak between requests.

```php
// public/index.php
<?php

use Convoy\Application;
use Convoy\Runner\HttpRunner;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return function (array $context): HttpRunner {
    $app = Application::starting($context)
        ->providers(new AppBundle())
        ->compile();

    return new HttpRunner(
        app: $app,
        host: $context['HTTP_HOST'] ?? '0.0.0.0',
        port: (int) ($context['HTTP_PORT'] ?? 8080),
        handler: fn($request, $scope) => handleRequest($request, $scope),
    );
};
```

The runtime component handles environment loading, signal trapping, and process lifecycle. Your application receives a clean `$context` array instead of touching superglobals directly.

```bash
composer require symfony/runtime
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
    7ms  EXEC    FetchCustomer {id:42}
    8ms  DONE    FetchCustomer  +0.61ms
    8ms  EXEC    ValidateInventory {items:[1]}
   19ms  DONE    ValidateInventory  +10.6ms
   19ms  CON<    concurrent(2) joined  +12.8ms
   19ms  EXEC  GetShippingQuote {zip:"10001"}  4.0MB
   40ms  DONE  GetShippingQuote  +20.9ms

0 svc  4.0MB peak  0 gc  39.8ms total
```

Invokable tasks display with their constructor arguments: `FetchCustomer {id:42}`. Closures show file and line: `Closure@handler.php:47`. Concurrent blocks indent their children.

Programmatic access:

```php
$trace = $scope->trace();
$entries = $trace->entries();
$trace->print();
```

## What's Next

HTTP client abstractions, database connection pooling, and queue workers are in development. The foundation you learn here carries forward.
