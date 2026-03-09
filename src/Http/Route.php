<?php

declare(strict_types=1);

namespace Convoy\Http;

use Closure;
use Convoy\Scope;
use Convoy\Task\Dispatchable;

/**
 * HTTP route handler as an invokable with fn + config.
 *
 * Routes are defined with a closure that receives ExecutionScope at dispatch time.
 * File loading receives Scope; handler execution receives ExecutionScope.
 */
final readonly class Route implements Dispatchable
{
    public function __construct(
        public Closure $fn,
        public RouteConfig $config = new RouteConfig(),
    ) {
    }

    public function __invoke(Scope $scope): mixed
    {
        return ($this->fn)($scope);
    }
}
