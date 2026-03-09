<?php

declare(strict_types=1);

namespace Convoy\Runner;

use Convoy\AppHost;
use Convoy\Console\CommandConfig;
use Convoy\Console\CommandGroup;
use Convoy\Handler\HandlerGroup;
use Convoy\Task\Dispatchable;
use RuntimeException;

/**
 * CLI command runner.
 *
 * @migration convoy/console
 *
 * Will migrate to the convoy/console library along with command
 * configuration, argument parsing, and output handling.
 */
final readonly class ConsoleRunner
{
    /**
     * @param array<string, Dispatchable> $commands
     */
    public function __construct(
        private AppHost $app,
        private array $commands = [],
        private CommandGroup|HandlerGroup|null $handlers = null,
    ) {
    }

    /**
     * Create runner with a command group for command dispatch.
     */
    public static function withHandlers(AppHost $app, CommandGroup|HandlerGroup $handlers): self
    {
        return new self($app, [], $handlers);
    }

    /**
     * Create runner with a command group for command dispatch.
     */
    public static function withCommands(AppHost $app, CommandGroup $commands): self
    {
        return new self($app, [], $commands);
    }

    public function withCommand(string $name, Dispatchable $handler): self
    {
        return new self(
            $this->app,
            [...$this->commands, $name => $handler],
            $this->handlers,
        );
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        if ($command === 'help') {
            return $this->showHelp();
        }

        if ($this->handlers !== null) {
            return $this->runWithHandlers($command, $args);
        }

        return $this->doRun($command, $args);
    }

    private function runWithHandlers(string $command, array $args): int
    {
        $handlerGroup = $this->handlers instanceof CommandGroup
            ? $this->handlers->handlers()
            : $this->handlers;

        $handler = $handlerGroup->get($command);

        if ($handler === null) {
            echo "Unknown command: $command\n";
            $this->printAvailableCommands();
            return 1;
        }

        $this->app->startup();

        try {
            $scope = $this->app->createScope();
            $scope = $scope->withAttribute('command', $command);
            $scope = $scope->withAttribute('args', $args);

            $result = $scope->execute($this->handlers);

            $scope->dispose();

            return is_int($result) ? $result : 0;
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'Command not found')) {
                echo "Unknown command: $command\n";
                $this->printAvailableCommands();
                return 1;
            }

            echo "Error: " . $e->getMessage() . "\n";
            return 1;
        } catch (\Throwable $e) {
            echo "Error: " . $e->getMessage() . "\n";
            return 1;
        } finally {
            $this->app->shutdown();
        }
    }

    private function doRun(string $command, array $args): int
    {
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

    private function showHelp(): int
    {
        echo "Available commands:\n\n";
        $this->printAvailableCommands();
        return 0;
    }

    private function printAvailableCommands(): void
    {
        $commands = [];

        if ($this->handlers !== null) {
            $handlerGroup = $this->handlers instanceof CommandGroup
                ? $this->handlers->handlers()
                : $this->handlers;

            foreach ($handlerGroup->commands() as $name => $handler) {
                $desc = '';
                if ($handler->config instanceof CommandConfig) {
                    $desc = $handler->config->description;
                }
                $commands[$name] = $desc;
            }
        }

        foreach ($this->commands as $name => $_) {
            if (!isset($commands[$name])) {
                $commands[$name] = '';
            }
        }

        ksort($commands);

        $maxLen = max(array_map(strlen(...), array_keys($commands)) ?: [0]);

        foreach ($commands as $name => $desc) {
            $padding = str_repeat(' ', $maxLen - strlen($name) + 2);
            if ($desc !== '') {
                echo "  $name$padding$desc\n";
            } else {
                echo "  $name\n";
            }
        }
    }
}
