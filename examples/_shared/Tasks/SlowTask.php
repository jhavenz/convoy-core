<?php

declare(strict_types=1);

namespace Convoy\Examples\Tasks;

use Convoy\ExecutionScope;
use Convoy\Task\Executable;
use Convoy\Task\HasTimeout;
use Convoy\Task\Traceable;

final class SlowTask implements Executable, HasTimeout, Traceable
{
    public function __construct(
        private float $sleepSeconds = 0.5,
        private float $timeoutSeconds = 0.1,
    ) {
    }

    public string $traceName {
        get => 'SlowTask';
    }

    public float $timeout {
        get => $this->timeoutSeconds;
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        $scope->delay($this->sleepSeconds);

        return ['completed' => true, 'slept' => $this->sleepSeconds];
    }
}
