<?php

declare(strict_types=1);

namespace Convoy\Console;

use Convoy\ExecutionScope;
use Convoy\Handler\Handler;
use Convoy\Handler\HandlerGroup;
use Convoy\Task\Executable;

/**
 * Typed collection of CLI commands.
 *
 * Keys are command names.
 * Wraps HandlerGroup for dispatch mechanics.
 */
final readonly class CommandGroup implements Executable
{
    private HandlerGroup $inner;

    /** @param array<string, Command> $commands */
    private function __construct(array $commands)
    {
        $handlers = [];
        foreach ($commands as $name => $command) {
            $handlers[$name] = new Handler($command, $command->config);
        }
        $this->inner = HandlerGroup::of($handlers);
    }

    /** @param array<string, Command> $commands */
    public static function of(array $commands): self
    {
        return new self($commands);
    }

    public static function create(): self
    {
        return new self([]);
    }

    private static function fromInner(HandlerGroup $inner): self
    {
        $instance = new self([]);
        $reflection = new \ReflectionClass($instance);
        $property = $reflection->getProperty('inner');
        $property->setValue($instance, $inner);

        return $instance;
    }

    public function merge(self $other): self
    {
        $newInner = $this->inner->merge($other->inner);

        return self::fromInner($newInner);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return $this->inner->keys();
    }

    /**
     * Get the underlying HandlerGroup for dispatch.
     */
    public function handlers(): HandlerGroup
    {
        return $this->inner;
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        return ($this->inner)($scope);
    }
}
