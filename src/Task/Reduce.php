<?php

declare(strict_types=1);

namespace Convoy\Task;

use Closure;
use Convoy\ExecutionScope;
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
        if (!$scope instanceof ExecutionScope) {
            throw new \RuntimeException('Reduce requires ExecutionScope for cancellation checking');
        }

        $accumulator = $this->initial;

        foreach ($this->sequence($scope) as $key => $value) {
            $scope->throwIfCancelled();
            $accumulator = ($this->reducer)($accumulator, $value, $key, $scope);
        }

        return $accumulator;
    }
}
