<?php

declare(strict_types=1);

namespace Convoy\Http;

use Convoy\Handler\Handler;
use Convoy\Handler\HandlerGroup;
use Convoy\Scope;
use Convoy\Task\Dispatchable;

/**
 * Typed collection of HTTP routes.
 *
 * Keys are "METHOD /path" format, parsed automatically.
 * Wraps HandlerGroup for dispatch mechanics.
 */
final readonly class RouteGroup implements Dispatchable
{
    private HandlerGroup $inner;

    /** @param array<string, Route> $routes */
    private function __construct(array $routes)
    {
        $handlers = [];
        foreach ($routes as $key => $route) {
            $parsed = self::parseKey($key);
            $config = $route->config;

            if ($parsed !== null) {
                $config = RouteConfig::compile($parsed['path'], $parsed['methods']);
                $config = new RouteConfig(
                    $config->methods,
                    $config->pattern,
                    $config->paramNames,
                    $route->config->middleware,
                    $route->config->tags,
                    $route->config->priority,
                );
            }

            $handlers[$key] = new Handler($route, $config);
        }
        $this->inner = HandlerGroup::of($handlers);
    }

    /** @param array<string, Route> $routes */
    public static function of(array $routes): self
    {
        return new self($routes);
    }

    public static function create(): self
    {
        return new self([]);
    }

    /**
     * @return array{methods: list<string>, path: string}|null
     */
    private static function parseKey(string $key): ?array
    {
        if (preg_match('#^([A-Z,]+)\s+(/\S*)$#', $key, $m)) {
            return [
                'methods' => explode(',', $m[1]),
                'path' => $m[2],
            ];
        }

        return null;
    }

    private static function fromInner(HandlerGroup $inner): self
    {
        $instance = new self([]);
        $reflection = new \ReflectionClass($instance);
        $property = $reflection->getProperty('inner');
        $property->setValue($instance, $inner);

        return $instance;
    }

    public function merge(self $other): self
    {
        $new = new self([]);
        $newInner = $this->inner->merge($other->inner);

        return self::fromInner($newInner);
    }

    public function mount(string $prefix, self $group): self
    {
        $newInner = $this->inner->mount($prefix, $group->inner);

        return self::fromInner($newInner);
    }

    public function wrap(Dispatchable ...$middleware): self
    {
        $newInner = $this->inner->wrap(...$middleware);

        return self::fromInner($newInner);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return $this->inner->keys();
    }

    /**
     * Get the underlying HandlerGroup for dispatch.
     */
    public function handlers(): HandlerGroup
    {
        return $this->inner;
    }

    public function __invoke(Scope $scope): mixed
    {
        return ($this->inner)($scope);
    }
}
