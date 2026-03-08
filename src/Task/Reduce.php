<?php

declare(strict_types=1);

namespace Convoy\Task;

use Closure;
use Convoy\Scope;

final readonly class Reduce implements Dispatchable
{
    public function __construct(
        private LazySequence $sequence,
        private Closure $reducer,
        private mixed $initial,
    ) {
    }

    public function __invoke(Scope $scope): mixed
    {
        $accumulator = $this->initial;

        foreach ($this->sequence($scope) as $key => $value) {
            $scope->throwIfCancelled();
            $accumulator = ($this->reducer)($accumulator, $value, $key, $scope);
        }

        return $accumulator;
    }
}
