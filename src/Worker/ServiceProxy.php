<?php

declare(strict_types=1);

namespace Convoy\Worker;

final class ServiceProxy
{
    /** @var resource */
    private $stdin;

    /** @var resource */
    private $stdout;

    public function __construct(
        private readonly string $serviceClass,
        $stdin,
        $stdout,
        private readonly WorkerScope $scope,
    ) {
        $this->stdin = $stdin;
        $this->stdout = $stdout;
    }

    public function __call(string $method, array $args): mixed
    {
        return $this->scope->callService($this->serviceClass, $method, $args);
    }
}
