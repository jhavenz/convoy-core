<?php

declare(strict_types=1);

namespace Convoy\Middleware;

use Convoy\Scope;

interface TaskInterceptor
{
    public function process(object $task, Scope $scope, callable $next): mixed;
}
