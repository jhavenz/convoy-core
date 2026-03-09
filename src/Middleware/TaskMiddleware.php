<?php

declare(strict_types=1);

namespace Convoy\Middleware;

use Convoy\ExecutionScope;
use Convoy\Task\Executable;
use Convoy\Task\Scopeable;

interface TaskMiddleware
{
    public function process(Scopeable|Executable $task, ExecutionScope $scope, callable $next): mixed;
}
