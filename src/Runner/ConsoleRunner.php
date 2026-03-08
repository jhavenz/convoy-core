<?php

declare(strict_types=1);

namespace Convoy\Runner;

use Convoy\AppHost;
use Convoy\Task\Dispatchable;

final readonly class ConsoleRunner
{
    /**
     * @param array<string, Dispatchable> $commands
     */
    public function __construct(
        private AppHost $app,
        private array $commands = [],
    ) {
    }

    public function withCommand(string $name, Dispatchable $handler): self
    {
        return new self(
            $this->app,
            [...$this->commands, $name => $handler],
        );
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        if (!isset($this->commands[$command])) {
            echo "Unknown command: $command\n";
            echo "Available: " . implode(', ', array_keys($this->commands)) . "\n";
            return 1;
        }

        $this->app->startup();

        try {
            $scope = $this->app->createScope();
            $scope = $scope->withAttribute('args', $args);

            $result = $scope->execute($this->commands[$command]);

            $scope->dispose();

            return is_int($result) ? $result : 0;
        } catch (\Throwable $e) {
            echo "Error: " . $e->getMessage() . "\n";
            return 1;
        } finally {
            $this->app->shutdown();
        }
    }
}
