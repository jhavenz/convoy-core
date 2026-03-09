<?php

declare(strict_types=1);

namespace Convoy\Handler;

use Convoy\Scope;
use Convoy\Task\Dispatchable;

/**
 * A single link in the middleware chain.
 *
 * Sets 'handler.next' attribute so middleware can call the next handler.
 *
 * @migration convoy/http, convoy/console
 *
 * Shared middleware chain link supporting both HTTP requests and CLI commands.
 * May remain in convoy-core as shared infrastructure, or be duplicated
 * if middleware semantics diverge between protocols.
 */
final readonly class MiddlewareChainLink implements Dispatchable
{
    public function __construct(
        private Dispatchable $middleware,
        private Dispatchable $next,
    ) {
    }

    public function __invoke(Scope $scope): mixed
    {
        $scope = $scope->withAttribute('handler.next', $this->next);

        return $scope->execute($this->middleware);
    }
}
