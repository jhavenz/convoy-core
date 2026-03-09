<?php

declare(strict_types=1);

namespace Convoy\Task;

use Closure;
use Convoy\ExecutionScope;

final readonly class Reduce implements Executable
{
    public function __construct(
        private LazySequence $sequence,
        private Closure $reducer,
        private mixed $initial,
    ) {
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        $accumulator = $this->initial;

        foreach ($this->sequence($scope) as $key => $value) {
            $scope->throwIfCancelled();
            $accumulator = ($this->reducer)($accumulator, $value, $key, $scope);
        }

        return $accumulator;
    }
}
