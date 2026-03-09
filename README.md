# Convoy Core - Async PHP

Convoy is an async coordination library for PHP 8.4+. It replaces callbacks with typed tasks—named computations that carry their own identity, behavior, and lifecycle through a unified execution model built on ReactPHP.

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [How It Works](#how-it-works)
  - [Scope Hierarchy](#scope-hierarchy)
- [The Task System](#the-task-system)
  - [Two Ways to Define Tasks](#two-ways-to-define-tasks)
  - [Behavior via Interfaces](#behavior-via-interfaces)
- [Concurrency Primitives](#concurrency-primitives)
- [Route Groups](#route-groups)
  - [Loading Routes](#loading-routes)
  - [Composing Route Groups](#composing-route-groups)
- [Command Groups](#command-groups)
  - [Running Commands](#running-commands)
- [Services](#services)
- [Cancellation & Retry](#cancellation--retry)
- [Tracing](#tracing)
- [Deterministic Cleanup](#deterministic-cleanup)
- [Examples](#examples)

## Installation

```bash
composer require convoy/core
```

Requires PHP 8.4+.

## Quick Start

```php
<?php

$app = Application::starting()->providers(new AppBundle())->compile();
$app->startup();

$scope = $app->createScope();

$result = $scope->execute(Task::of(static fn(ExecutionScope $s) =>
    $s->service(OrderService::class)->process(42)
));

$scope->dispose();
$app->shutdown();
```

**Note:** Service classes like `OrderService`, `UserRepo`, `DatabasePool` in these examples are illustrative. Convoy Core provides the coordination primitives—your application brings the domain logic.

## How It Works

Convoy's model: **Application -> Scope -> Tasks**.

```
Application::starting($context)
    -> compile()           // Validate service graph, create app
    -> startup()           // Run startup hooks, enable shutdown handlers
    -> createScope()       // Create ExecutionScope
    -> execute(Task)       // Run typed tasks
    -> dispose()           // Cleanup scope resources
    -> shutdown()          // Cleanup app resources
```

Every task implements `Scopeable` or `Executable`—single-method interfaces:

```php
<?php

// Tasks needing only service resolution
interface Scopeable {
    public function __invoke(Scope $scope): mixed;
}

// Tasks needing execution primitives (concurrency, cancellation)
interface Executable {
    public function __invoke(ExecutionScope $scope): mixed;
}
```

### Scope Hierarchy

Convoy splits scope into two interfaces:

| Interface | Purpose |
|-----------|---------|
| `Scope` | Service resolution, attributes, tracing |
| `ExecutionScope` | Extends Scope with concurrency, cancellation, disposal |

**Scope** is the minimal interface for most code—service access and attribute passing.

**ExecutionScope** adds execution capabilities: `concurrent()`, `race()`, `execute()`, `throwIfCancelled()`, `dispose()`.

```php
<?php

// Scope: minimal interface
interface Scope {
    public function service(string $type): object;
    public function attribute(string $key, mixed $default = null): mixed;
    public function withAttribute(string $key, mixed $value): Scope;
    public function trace(): Trace;
}

// ExecutionScope: full execution capabilities
interface ExecutionScope extends Scope {
    public bool $isCancelled { get; }
    public function execute(Scopeable|Executable $task): mixed;
    public function concurrent(array $tasks): array;
    public function throwIfCancelled(): void;
    public function onDispose(Closure $callback): void;
    // ... and more
}
```

Handler closures receive `ExecutionScope` for full concurrency control. File loading receives `Scope` for service resolution.

## The Task System

### Two Ways to Define Tasks

**Quick tasks** for one-offs:

```php
<?php

$task = Task::of(static fn(ExecutionScope $s) => $s->service(UserRepo::class)->find($id));
$user = $scope->execute($task);
```

**Invokable classes** for everything else:

```php
<?php

final readonly class FetchUser implements Scopeable
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
<?php

final class DatabaseQuery implements Scopeable, Retryable, HasTimeout
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
<?php

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
```

## Route Groups

Typed collections of HTTP routes with `RouteGroup`:

```php
<?php
// routes/api.php

use Convoy\Http\Route;
use Convoy\Http\RouteGroup;
use Convoy\Scope;
use Convoy\ExecutionScope;

return static fn(Scope $s): RouteGroup => RouteGroup::of([
    'GET /users' => new Route(
        fn: static fn(ExecutionScope $es) => $es->service(UserRepo::class)->all(),
    ),
    'GET /users/{id}' => new Route(
        fn: static fn(ExecutionScope $es) => $es->service(UserRepo::class)->find(
            (int) $es->attribute('route.id')
        ),
    ),
    'POST /users' => new Route(
        fn: static fn(ExecutionScope $es) => $es->service(UserRepo::class)->create(
            $es->attribute('request.body')
        ),
        config: new RouteConfig(timeout: 5.0),
    ),
]);
```

### Loading Routes

```php
<?php

use Convoy\Handler\HandlerLoader;
use Convoy\Runner\HttpRunner;

$routes = HandlerLoader::loadRouteDirectory(__DIR__ . '/routes', $app->scope());
$runner = HttpRunner::withRoutes($app, $routes, requestTimeout: 30.0);
$runner->run('0.0.0.0:8080');
```

### Composing Route Groups

```php
<?php

$api = RouteGroup::create()
    ->merge($publicRoutes)
    ->mount('/admin', $adminRoutes)
    ->wrap(new AuthMiddleware());
```

## Command Groups

Typed collections of CLI commands with `CommandGroup`:

```php
<?php
// commands/db.php

use Convoy\Console\Command;
use Convoy\Console\CommandGroup;
use Convoy\Console\CommandConfig;
use Convoy\Scope;
use Convoy\ExecutionScope;

return static fn(Scope $s): CommandGroup => CommandGroup::of([
    'migrate' => new Command(
        fn: static fn(ExecutionScope $es) => $es->service(Migrator::class)->run(),
        config: new CommandConfig(description: 'Run database migrations'),
    ),
    'db:seed' => new Command(
        fn: static fn(ExecutionScope $es) => $es->service(Seeder::class)->run(),
    ),
]);
```

### Running Commands

```php
<?php

use Convoy\Handler\HandlerLoader;
use Convoy\Runner\ConsoleRunner;

$commands = HandlerLoader::loadCommandDirectory(__DIR__ . '/commands', $app->scope());
$runner = ConsoleRunner::withCommands($app, $commands);
exit($runner->run($argv));
```

## Services

```php
<?php

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

        $services->scoped(RequestLogger::class)
            ->lazy()
            ->onDispose(fn($log) => $log->flush());
    }
}
```

| Method | Lifecycle |
|--------|-----------|
| `singleton()` | One instance per application |
| `scoped()` | One instance per scope, disposed with scope |
| `lazy()` | Defer creation until first access (PHP 8.4 lazy ghosts) |

## Cancellation & Retry

```php
<?php

use Convoy\Concurrency\CancellationToken;
use Convoy\Concurrency\RetryPolicy;

// Timeout for entire scope
$scope = $app->createScope(CancellationToken::timeout(30.0));

// Task-level timeout
$result = $scope->timeout(5.0, new SlowApiCall($id));

// Retry with exponential backoff
$result = $scope->retry(
    new FetchFromApi($url),
    RetryPolicy::exponential(attempts: 3)
);

// Check cancellation within tasks (use Executable when you need ExecutionScope)
final class LongRunningTask implements Executable
{
    public function __invoke(ExecutionScope $scope): mixed
    {
        foreach ($this->chunks as $chunk) {
            $scope->throwIfCancelled();
            $this->process($chunk);
        }
        return $this->result;
    }
}
```

## Tracing

```bash
CONVOY_TRACE=1 php server.php
```

```
    0ms  STRT  compiling
    4ms  STRT  startup
    6ms  CON>    concurrent(2)
    7ms  EXEC    FetchCustomer
    8ms  DONE    FetchCustomer  +0.61ms
   19ms  CON<    concurrent(2) joined  +12.8ms

0 svc  4.0MB peak  0 gc  39.8ms total
```

## Deterministic Cleanup

```php
<?php

$scope = $app->createScope();
$scope->onDispose(fn() => $connection->close());

// Your task code...

$scope->dispose();  // Cleanup fires in reverse order
```

## Examples

The `examples/` directory contains progressive examples in three tiers:

### Beginner
- `01-hello-task.php` - Task::of() basics, app lifecycle
- `02-invokable-task.php` - Scopeable classes vs closures
- `03-service-resolution.php` - ServiceBundle, `$scope->service()`
- `04-simple-http-route.php` - Single route HTTP server

### Intermediate
- `01-concurrent-basics.php` - `$scope->concurrent()` parallel execution
- `02-map-with-limits.php` - Bounded parallelism
- `03-http-routes.php` - RouteGroup with concurrent data
- `04-console-commands.php` - CommandGroup patterns
- `05-series-waterfall.php` - Sequential execution modes

### Advanced
- `01-retry-policies.php` - Retryable interface, backoff strategies
- `02-timeouts.php` - HasTimeout, `$scope->timeout()`
- `03-cancellation.php` - CancellationToken patterns
- `04-settle-errors.php` - SettlementBag, partial failures
- `05-race-any.php` - race() and any() patterns
- `06-composite-tasks.php` - Nested concurrent operations
- `07-production-server.php` - Full HTTP server

```bash
# Beginner
php examples/01-beginner/01-hello-task.php

# Intermediate with tracing
CONVOY_TRACE=1 php examples/02-intermediate/01-concurrent-basics.php

# Advanced production server
php examples/03-advanced/07-production-server.php
curl http://localhost:8093/api/stocks
```

See `examples/README.md` for full details.
