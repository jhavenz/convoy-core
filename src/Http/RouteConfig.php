<?php

declare(strict_types=1);

namespace Convoy\Http;

use Convoy\Handler\HandlerConfig;
use Convoy\Task\Dispatchable;

/**
 * HTTP route configuration with path matching and middleware.
 *
 * @migration convoy/http
 *
 * This route compiler will support pluggable adapters once migrated:
 * - FastRoute: Compiled dispatch, optional params, caching
 * - Symfony Routing: Full Symfony ecosystem compatibility
 * - Laravel: Fluent route definition, implicit bindings
 *
 * Current implementation uses basic regex matching (O(n) per request).
 * Adapters will provide O(1) compiled dispatch for large route sets.
 */
final readonly class RouteConfig extends HandlerConfig
{
    /**
     * @param list<string> $methods
     * @param list<string> $paramNames
     * @param list<Dispatchable> $middleware
     * @param list<string> $tags
     */
    public function __construct(
        public array $methods = ['GET'],
        public string $pattern = '',
        public array $paramNames = [],
        public array $middleware = [],
        array $tags = [],
        int $priority = 0,
    ) {
        parent::__construct($tags, $priority);
    }

    /**
     * Compile a path pattern into regex and extract param names.
     *
     * /users/{id}        -> /users/(?P<id>[^/]+)
     * /users/{id:\d+}    -> /users/(?P<id>\d+)
     */
    public static function compile(string $path, string|array $method = 'GET'): self
    {
        $methods = is_array($method) ? $method : [$method];
        $methods = array_map(strtoupper(...), $methods);

        $paramNames = [];
        $pattern = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}#',
            static function (array $m) use (&$paramNames): string {
                $paramNames[] = $m[1];
                $constraint = $m[2] ?? '[^/]+';
                return "(?P<{$m[1]}>{$constraint})";
            },
            $path,
        );

        $pattern = '#^' . $pattern . '$#';

        return new self(
            methods: $methods,
            pattern: $pattern,
            paramNames: $paramNames,
        );
    }

    /**
     * Check if this route matches the given method and path.
     *
     * @return array<string, string>|null Params if matched, null otherwise
     */
    public function matches(string $method, string $path): ?array
    {
        $method = strtoupper($method);

        if (!in_array($method, $this->methods, true)) {
            return null;
        }

        if (!preg_match($this->pattern, $path, $matches)) {
            return null;
        }

        $params = [];
        foreach ($this->paramNames as $name) {
            if (isset($matches[$name])) {
                $params[$name] = $matches[$name];
            }
        }

        return $params;
    }

    public function withMethod(string|array $method): self
    {
        $methods = is_array($method) ? $method : [$method];
        $methods = array_map(strtoupper(...), $methods);

        return new self(
            $methods,
            $this->pattern,
            $this->paramNames,
            $this->middleware,
            $this->tags,
            $this->priority,
        );
    }

    public function withPath(string $path): self
    {
        $compiled = self::compile($path, $this->methods);

        return new self(
            $this->methods,
            $compiled->pattern,
            $compiled->paramNames,
            $this->middleware,
            $this->tags,
            $this->priority,
        );
    }

    public function withMiddleware(Dispatchable ...$middleware): self
    {
        return new self(
            $this->methods,
            $this->pattern,
            $this->paramNames,
            [...$this->middleware, ...$middleware],
            $this->tags,
            $this->priority,
        );
    }

    public function withTags(string ...$tags): self
    {
        return new self(
            $this->methods,
            $this->pattern,
            $this->paramNames,
            $this->middleware,
            [...$this->tags, ...$tags],
            $this->priority,
        );
    }

    public function withPriority(int $priority): self
    {
        return new self(
            $this->methods,
            $this->pattern,
            $this->paramNames,
            $this->middleware,
            $this->tags,
            $priority,
        );
    }
}
