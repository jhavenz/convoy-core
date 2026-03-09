<?php

declare(strict_types=1);

namespace Convoy\Task;

use Convoy\Scope;

final readonly class First implements Scopeable
{
    public function __construct(
        private LazySequence $sequence,
    ) {
    }

    public function __invoke(Scope $scope): mixed
    {
        foreach ($this->sequence($scope) as $value) {
            return $value;
        }

        return null;
    }
}
