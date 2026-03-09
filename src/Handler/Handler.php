<?php

declare(strict_types=1);

namespace Convoy\Handler;

use Convoy\Console\CommandConfig;
use Convoy\Http\RouteConfig;
use Convoy\Task\Dispatchable;

/**
 * Factory for creating handler entries with typed configurations.
 *
 * @migration convoy/http, convoy/console
 *
 * Bimodal factory supporting both HTTP routes and CLI commands.
 * Route-specific factory methods migrate to convoy/http, command-specific
 * methods migrate to convoy/console. Core Handler class may remain in
 * convoy-core as shared infrastructure.
 */
final readonly class Handler
{
    public function __construct(
        public Dispatchable $task,
        public HandlerConfig $config,
    ) {
    }

    /**
     * Create an HTTP route handler.
     *
     * @param string|list<string> $method HTTP method(s)
     */
    public static function route(Dispatchable $task, string|array $method = 'GET'): self
    {
        $methods = is_array($method) ? $method : [$method];
        $methods = array_map(strtoupper(...), $methods);

        return new self($task, new RouteConfig(methods: $methods));
    }

    /**
     * Create a console command handler.
     */
    public static function command(Dispatchable $task, string $description = ''): self
    {
        return new self($task, new CommandConfig(description: $description));
    }

    /**
     * Create a handler with explicit config.
     */
    public static function of(Dispatchable $task, ?HandlerConfig $config = null): self
    {
        return new self($task, $config ?? new HandlerConfig());
    }
}
