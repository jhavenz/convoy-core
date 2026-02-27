<?php

declare(strict_types=1);

namespace Convoy\Runner;

use Convoy\AppHost;
use Convoy\Scope;

final readonly class ConsoleRunner
{
    /**
     * @param array<string, callable(Scope, list<string>): int> $commands
     */
    public function __construct(
        private AppHost $app,
        private array $commands = [],
    ) {
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $this->app->startup();

        $scope = $this->app->createScope();

        try {
            $command = $argv[1] ?? 'default';
            $args = array_slice($argv, 2);

            $handler = $this->commands[$command]
                ?? throw new \InvalidArgumentException("Unknown command: $command");

            return $handler($scope, $args);
        } finally {
            $scope->dispose();
            $this->app->shutdown();
        }
    }

    public function withCommand(string $name, callable $handler): self
    {
        return new self(
            $this->app,
            [...$this->commands, $name => $handler],
        );
    }
}
