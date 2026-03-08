<?php

declare(strict_types=1);

namespace Convoy\Middleware;

use Convoy\Scope;
use Convoy\Task\Dispatchable;

interface TaskMiddleware
{
    public function process(Dispatchable $task, Scope $scope, callable $next): mixed;
}
