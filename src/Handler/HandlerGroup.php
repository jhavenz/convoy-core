<?php

declare(strict_types=1);

namespace Convoy\Handler;

use Convoy\Console\CommandConfig;
use Convoy\ExecutionScope;
use Convoy\Http\RouteConfig;
use Convoy\Task\Executable;
use Convoy\Task\Scopeable;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * Self-dispatching handler collection.
 *
 * HandlerGroup implements Dispatchable, reading scope attributes to determine
 * which handler to invoke:
 *
 * - 'request' (ServerRequestInterface) -> route matching
 * - 'command' (string) -> command matching
 * - 'handler.key' (string) -> direct lookup
 *
 * Runners become thin shells that set attributes and execute the group.
 *
 * @migration convoy/http, convoy/console
 *
 * Bimodal dispatch supporting both HTTP routes and CLI commands.
 * Route-specific methods (route(), routes(), dispatchRoute()) migrate to
 * convoy/http. Command-specific methods (command(), commands(), dispatchCommand())
 * migrate to convoy/console. Core dispatch logic may be extracted to a shared
 * interface or remain in convoy-core.
 */
final readonly class HandlerGroup implements Executable
{
    /**
     * @param array<string, Handler> $handlers
     * @param list<Scopeable|Executable> $middleware
     */
    private function __construct(
        private array $handlers,
        private array $middleware = [],
    ) {
    }

    /**
     * Create from an array of handlers.
     *
     * Keys can be:
     * - Route patterns: "GET /users", "POST /users/{id}"
     * - Command names: "migrate", "cache:clear"
     * - Arbitrary keys for direct lookup
     *
     * @param array<string, Handler|Scopeable|Executable> $handlers
     */
    public static function of(array $handlers): self
    {
        $normalized = [];

        foreach ($handlers as $key => $handler) {
            if ($handler instanceof Handler) {
                $normalized[$key] = self::finalizeHandler($key, $handler);
            } else {
                $normalized[$key] = self::finalizeHandler($key, Handler::of($handler));
            }
        }

        return new self($normalized);
    }

    /**
     * Create an empty group for fluent building.
     */
    public static function create(): self
    {
        return new self([]);
    }

    /**
     * Finalize handler config from key if needed.
     */
    private static function finalizeHandler(string $key, Handler $handler): Handler
    {
        if ($handler->config instanceof RouteConfig && $handler->config->pattern === '') {
            $parsed = self::parseRouteKey($key);

            if ($parsed !== null) {
                $config = RouteConfig::compile($parsed['path'], $handler->config->methods);

                return new Handler($handler->task, $config);
            }
        }

        if ($handler->config instanceof RouteConfig && $handler->config->pattern === '') {
            $config = RouteConfig::compile($key, $handler->config->methods);

            return new Handler($handler->task, $config);
        }

        return $handler;
    }

    /**
     * Parse "METHOD /path" or "METHOD,METHOD /path" keys.
     *
     * @return array{methods: list<string>, path: string}|null
     */
    private static function parseRouteKey(string $key): ?array
    {
        if (preg_match('#^([A-Z,]+)\s+(/\S*)$#', $key, $m)) {
            return [
                'methods' => explode(',', $m[1]),
                'path' => $m[2],
            ];
        }

        return null;
    }

    /**
     * Build route key from path and method(s).
     *
     * @param string|list<string> $method
     */
    private static function routeKey(string $path, string|array $method): string
    {
        $methods = is_array($method) ? $method : [$method];
        $methods = array_map(strtoupper(...), $methods);

        return implode(',', $methods) . ' ' . $path;
    }

    /**
     * Prefix a route key.
     */
    private static function prefixRouteKey(string $prefix, string $key): string
    {
        $parsed = self::parseRouteKey($key);

        if ($parsed !== null) {
            return implode(',', $parsed['methods']) . ' ' . $prefix . $parsed['path'];
        }

        return $key;
    }

    /**
     * Prefix a route config's pattern.
     */
    private static function prefixRouteConfig(string $prefix, RouteConfig $config): RouteConfig
    {
        $prefixPattern = preg_quote($prefix, '#');
        $innerPattern = substr($config->pattern, 2, -1);
        $newPattern = '#^' . $prefixPattern . $innerPattern . '$#';

        return new RouteConfig(
            $config->methods,
            $newPattern,
            $config->paramNames,
            $config->middleware,
            $config->tags,
            $config->priority,
        );
    }

    /**
     * Add an HTTP route.
     *
     * @param string|list<string> $method
     */
    public function route(string $path, Scopeable|Executable $handler, string|array $method = 'GET'): self
    {
        $key = self::routeKey($path, $method);
        $config = RouteConfig::compile($path, $method);

        return $this->add($key, new Handler($handler, $config));
    }

    /**
     * Add a console command.
     */
    public function command(string $name, Scopeable|Executable $handler, string $description = ''): self
    {
        return $this->add($name, Handler::command($handler, $description));
    }

    /**
     * Add a handler with explicit key.
     */
    public function add(string $key, Handler $handler): self
    {
        return new self(
            [...$this->handlers, $key => self::finalizeHandler($key, $handler)],
            $this->middleware,
        );
    }

    /**
     * Merge another group into this one.
     *
     * Handlers from $other override handlers with the same key.
     */
    public function merge(HandlerGroup $other): self
    {
        return new self(
            [...$this->handlers, ...$other->handlers],
            [...$this->middleware, ...$other->middleware],
        );
    }

    /**
     * Mount a group under a path prefix.
     *
     * Only affects route handlers. Commands are merged as-is.
     */
    public function mount(string $prefix, HandlerGroup $group): self
    {
        $prefix = rtrim($prefix, '/');
        $mounted = [];

        foreach ($group->handlers as $key => $handler) {
            if ($handler->config instanceof RouteConfig) {
                $newKey = self::prefixRouteKey($prefix, $key);
                $newConfig = self::prefixRouteConfig($prefix, $handler->config);
                $mounted[$newKey] = new Handler($handler->task, $newConfig);
            } else {
                $mounted[$key] = $handler;
            }
        }

        return new self(
            [...$this->handlers, ...$mounted],
            $this->middleware,
        );
    }

    /**
     * Wrap all handlers with middleware.
     *
     * Middleware runs in order: first added runs first.
     */
    public function wrap(Scopeable|Executable ...$middleware): self
    {
        return new self(
            $this->handlers,
            [...$this->middleware, ...$middleware],
        );
    }

    /**
     * Get all handler keys.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * Get a handler by key.
     */
    public function get(string $key): ?Handler
    {
        return $this->handlers[$key] ?? null;
    }

    /**
     * Get all route handlers.
     *
     * @return array<string, Handler>
     */
    public function routes(): array
    {
        return array_filter(
            $this->handlers,
            static fn(Handler $h): bool => $h->config instanceof RouteConfig,
        );
    }

    /**
     * Get all command handlers.
     *
     * @return array<string, Handler>
     */
    public function commands(): array
    {
        return array_filter(
            $this->handlers,
            static fn(Handler $h): bool => $h->config instanceof CommandConfig,
        );
    }

    private function dispatchByKey(ExecutionScope $scope): mixed
    {
        $key = $scope->attribute('handler.key');
        $handler = $this->handlers[$key] ?? null;

        if ($handler === null) {
            throw new RuntimeException("Handler not found: $key");
        }

        return $this->executeHandler($handler, $scope);
    }

    private function dispatchRoute(ExecutionScope $scope): mixed
    {
        /** @var ServerRequestInterface $request */
        $request = $scope->attribute('request');
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        foreach ($this->handlers as $handler) {
            if (!$handler->config instanceof RouteConfig) {
                continue;
            }

            $params = $handler->config->matches($method, $path);

            if ($params !== null) {
                $scope = $scope->withAttribute('route.params', $params);

                foreach ($params as $name => $value) {
                    $scope = $scope->withAttribute("route.$name", $value);
                }

                return $this->executeHandler($handler, $scope);
            }
        }

        throw new RuntimeException("No route matches $method $path");
    }

    private function dispatchCommand(ExecutionScope $scope): mixed
    {
        $name = $scope->attribute('command');
        $handler = $this->handlers[$name] ?? null;

        if ($handler === null) {
            throw new RuntimeException("Command not found: $name");
        }

        return $this->executeHandler($handler, $scope);
    }

    private function executeHandler(Handler $handler, ExecutionScope $scope): mixed
    {
        $task = $handler->task;

        $handlerMiddleware = $handler->config instanceof RouteConfig
            ? $handler->config->middleware
            : [];

        $allMiddleware = [...$this->middleware, ...$handlerMiddleware];

        if ($allMiddleware !== []) {
            $task = new MiddlewareWrapper($task, $allMiddleware);
        }

        return $scope->execute($task);
    }

    /**
     * Dispatch to the appropriate handler based on scope attributes.
     */
    public function __invoke(ExecutionScope $scope): mixed
    {
        if ($scope->attribute('handler.key') !== null) {
            return $this->dispatchByKey($scope);
        }

        if ($scope->attribute('request') instanceof ServerRequestInterface) {
            return $this->dispatchRoute($scope);
        }

        if ($scope->attribute('command') !== null) {
            return $this->dispatchCommand($scope);
        }

        throw new RuntimeException(
            'HandlerGroup requires one of: handler.key, request, or command attribute'
        );
    }
}
