<?php

declare(strict_types=1);

namespace Convoy\Handler;

use Convoy\ExecutionScope;
use Convoy\Task\Executable;
use Convoy\Task\Scopeable;

/**
 * Wraps a handler task with middleware.
 *
 * Middleware are Scopeable|Executable that can call the next handler via
 * $scope->attribute('handler.next'). Each middleware receives the
 * scope and can modify attributes before/after the inner call.
 *
 * @migration convoy/http, convoy/console
 *
 * Shared middleware chain supporting both HTTP requests and CLI commands.
 * May remain in convoy-core as shared infrastructure, or be duplicated
 * if middleware semantics diverge between protocols.
 */
final readonly class MiddlewareWrapper implements Executable
{
    /**
     * @param list<Scopeable|Executable> $middleware
     */
    public function __construct(
        private Scopeable|Executable $handler,
        private array $middleware,
    ) {
    }

    /**
     * @param list<Scopeable|Executable> $middleware
     */
    private function buildStack(Scopeable|Executable $handler, array $middleware): Scopeable|Executable
    {
        $next = $handler;

        foreach (array_reverse($middleware) as $mw) {
            $next = new MiddlewareChainLink($mw, $next);
        }

        return $next;
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        if ($this->middleware === []) {
            return $scope->execute($this->handler);
        }

        $stack = $this->buildStack($this->handler, $this->middleware);

        return $scope->execute($stack);
    }
}
