<?php

declare(strict_types=1);

namespace Convoy\Examples\Tasks;

use Convoy\Scope;
use Convoy\Task\Scopeable;

final class Greeter implements Scopeable
{
    public function __construct(
        private string $name,
        private string $greeting = 'Hello',
    ) {
    }

    public function __invoke(Scope $scope): mixed
    {
        return [
            'message' => "{$this->greeting}, {$this->name}!",
            'time' => date('Y-m-d H:i:s'),
        ];
    }
}
