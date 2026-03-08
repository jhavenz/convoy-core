<?php

declare(strict_types=1);

namespace Convoy\Task;

use Convoy\Scope;

interface Dispatchable
{
    public function __invoke(Scope $scope): mixed;
}
