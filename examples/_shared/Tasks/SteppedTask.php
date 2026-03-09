<?php

declare(strict_types=1);

namespace Convoy\Examples\Tasks;

use Convoy\ExecutionScope;
use Convoy\Task\Executable;
use Convoy\Task\Traceable;

final class SteppedTask implements Executable, Traceable
{
    public function __construct(
        private int $steps,
        private float $delaySeconds = 0.05,
    ) {
    }

    public string $traceName {
        get => "SteppedTask({$this->steps}x{$this->delaySeconds}s)";
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        for ($i = 1; $i <= $this->steps; $i++) {
            $scope->throwIfCancelled();
            $scope->delay($this->delaySeconds);
        }

        return ['completed' => true, 'steps' => $this->steps];
    }
}
