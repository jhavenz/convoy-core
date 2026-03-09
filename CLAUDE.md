# CLAUDE.md

## Commands

```bash
composer test                              # Run all tests
composer cs                                # Check code style
composer cs:fix                            # Fix code style
php vendor/bin/phpunit --filter testName   # Single test
```

## Architecture

### Scope Hierarchy

```
Scope (interface)
├── service(), attribute(), withAttribute(), trace()
│
ExecutionScope (interface extends Scope)
├── execute(), concurrent(), race(), any(), map()
├── series(), waterfall(), settle(), timeout(), retry()
├── delay(), defer(), throwIfCancelled(), cancellation()
├── onDispose(), dispose(), $isCancelled
│
ExecutionLifecycleScope (implementation)

Scopeable (interface) - SEPARATE
├── __invoke(Scope $scope): mixed

Executable (interface) - SEPARATE
├── __invoke(ExecutionScope $scope): mixed
```

**Scope**: Minimal interface—service resolution, attributes, tracing.
**ExecutionScope**: Full execution—concurrency primitives, cancellation, disposal.
**Scopeable**: Tasks needing only service resolution.
**Executable**: Tasks needing full execution capabilities.

The `execute()` method accepts `Scopeable|Executable` union type.

### Handler System

```
Route/Command         ->  invokables with fn + config
RouteGroup/CommandGroup  ->  typed collections ("GET /path" keys)
HandlerGroup          ->  internal dispatch mechanics
HandlerLoader         ->  file-based discovery
```

| Type | Location | Purpose |
|------|----------|---------|
| `Route` | `src/Http/Route.php` | HTTP handler invokable |
| `RouteGroup` | `src/Http/RouteGroup.php` | Typed route collection |
| `RouteConfig` | `src/Http/RouteConfig.php` | Route config (methods, pattern, middleware) |
| `Command` | `src/Console/Command.php` | CLI handler invokable |
| `CommandGroup` | `src/Console/CommandGroup.php` | Typed command collection |
| `CommandConfig` | `src/Console/CommandConfig.php` | Command config (description, args) |

### File Loading Pattern

```php
<?php
// routes/api.php - file receives Scope, returns RouteGroup
return static fn(Scope $s): RouteGroup => RouteGroup::of([
    'GET /users' => new Route(
        fn: static fn(ExecutionScope $es) => $es->service(UserRepo::class)->all(),
    ),
]);
```

File closures receive `Scope` (service resolution). Handler `fn` closures receive `ExecutionScope` (full execution).

### Task System

```php
<?php

// Quick task via factory (implements Scopeable)
Task::of(static fn(ExecutionScope $es) => $es->service(SomeService::class)->work());

// Invokable class - Scopeable for service resolution only
final class DoWork implements Scopeable {
    public function __invoke(Scope $scope): mixed {
        return $scope->service(SomeService::class)->work();
    }
}

// Invokable class - Executable for concurrency primitives
final class DoParallelWork implements Executable {
    public function __invoke(ExecutionScope $scope): mixed {
        return $scope->concurrent([...]);
    }
}
```

Behavior via interfaces: `Retryable`, `HasTimeout`, `HasPriority`, `UsesPool`, `Traceable`.

### Service System

```php
<?php

class AppBundle implements ServiceBundle {
    public function services(Services $services, array $context): void {
        $services->singleton(DbPool::class)
            ->factory(fn() => new DbPool($context['db_url']))
            ->onShutdown(fn($p) => $p->drain());

        $services->scoped(RequestLogger::class)
            ->lazy()
            ->onDispose(fn($log) => $log->flush());
    }
}
```

### Key Files

| File | Purpose |
|------|---------|
| `src/Scope.php` | Minimal scope interface |
| `src/ExecutionScope.php` | Full execution interface |
| `src/ExecutionLifecycleScope.php` | Concrete implementation |
| `src/Application.php` | App bootstrap, createScope() |
| `src/Handler/HandlerLoader.php` | loadRouteDirectory(), loadCommandDirectory() |
| `src/Runner/HttpRunner.php` | ReactPHP HTTP server |
| `src/Runner/ConsoleRunner.php` | CLI dispatch |

## Code Style

- All PHP code blocks require `<?php` opening tag
- Formal docblock comments (`/** */`) for design decisions
- Static closures only in `Task::of()` (prevents reference cycles)
- Prefer `protected(set)` over `readonly` for property hooks compatibility

## PHP 8.4+ Features

- Property hooks (`public RetryPolicy $retryPolicy { get => ... }`)
- `readonly` classes
- `ReflectionClass::newLazyGhost()` for lazy services
- `array_any()`, `array_all()` for short-circuit checks
