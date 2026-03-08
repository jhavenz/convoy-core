<?php

declare(strict_types=1);

namespace Convoy\Task;

use Convoy\Scope;

final readonly class Collect implements Dispatchable
{
    public function __construct(
        private LazySequence $sequence,
    ) {
    }

    public function __invoke(Scope $scope): array
    {
        return iterator_to_array($this->sequence($scope));
    }
}
