# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2025-03-01

### Changed

- **PSR-4 compliance**: `Scope` renamed to `ExecutionScope` with dedicated file
- **Modern random API**: `RetryPolicy` now uses `random_int()` instead of `mt_rand()`
- **Simplified internals**: Removed redundant type guards and instanceof checks

### Fixed

- Cancellation check added to `map()` inner loop for faster cancellation response

## [0.1.0] - 2025-02-26

### Added

- **Core primitives**
  - `Application` and `ApplicationBuilder` for bootstrap
  - `TaskScope` interface and `Scope` implementation for task execution
  - Service graph compilation with dependency validation

- **Concurrency primitives**
  - `concurrent()` - run all concurent tasks, wait for all
  - `race()` - return first to settle (success or failure)
  - `any()` - return first success, throw if all fail
  - `map()` - bounded parallelism over collections
  - `series()` - sequential execution
  - `waterfall()` - sequential with result passing
  - `settle()` - run all, collect outcomes without short-circuiting
  - `timeout()` - task-level timeout wrapper

- **Cancellation**
  - `CancellationToken` with manual, timeout, and composite modes
  - Cooperative cancellation via `throwIfCancelled()` and `$scope->isCancelled`

- **Retry**
  - `RetryPolicy` with exponential, linear, and fixed backoff
  - Jitter support to prevent thundering herd
  - Exception filtering via `retryingOn()`

- **Services**
  - `ServiceBundle` interface for provider registration
  - Singleton and scoped lifetimes
  - Lazy initialization via PHP 8.4 lazy ghosts
  - Interface aliasing
  - Compile-time validation: cycles, missing deps, captive dependencies

- **Lifecycle hooks**
  - `onInit` - after factory creates instance
  - `onStartup` - application startup
  - `onDispose` - scope disposal
  - `onShutdown` - application shutdown

- **Runners**
  - `HttpRunner` - ReactPHP HTTP server integration
  - `ConsoleRunner` - CLI command execution

- **Tracing**
  - Built-in execution tracing via `CONVOY_TRACE=1`
  - Programmatic access via `$scope->trace()`

- **Middleware**
  - `TaskInterceptor` for task execution wrapping
  - `ServiceTransform` for service definition modification

### Requirements

- PHP 8.4+
- react/async ^4.3
- react/promise ^3.2
- react/event-loop ^1.5

[Unreleased]: https://github.com/convoy-php/core/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/convoy-php/core/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/convoy-php/core/releases/tag/v0.1.0
