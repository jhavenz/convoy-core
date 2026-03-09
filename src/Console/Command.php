<?php

declare(strict_types=1);

namespace Convoy\Console;

use Closure;
use Convoy\Scope;
use Convoy\Task\Dispatchable;

/**
 * CLI command handler as an invokable with fn + config.
 *
 * Commands are defined with a closure that receives ExecutionScope at dispatch time.
 * File loading receives Scope; handler execution receives ExecutionScope.
 */
final readonly class Command implements Dispatchable
{
    public function __construct(
        public Closure $fn,
        public CommandConfig $config = new CommandConfig(),
    ) {
    }

    public function __invoke(Scope $scope): mixed
    {
        return ($this->fn)($scope);
    }
}
