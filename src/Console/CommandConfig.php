<?php

declare(strict_types=1);

namespace Convoy\Console;

use Convoy\Handler\HandlerConfig;

/**
 * Console command configuration.
 *
 * @migration convoy/console
 *
 * Will migrate to the convoy/console library along with argument
 * parsing, validation, and help generation.
 */
final readonly class CommandConfig extends HandlerConfig
{
    /**
     * @param list<CommandArgument> $arguments
     * @param list<string> $tags
     */
    public function __construct(
        public string $description = '',
        public array $arguments = [],
        array $tags = [],
        int $priority = 0,
    ) {
        parent::__construct($tags, $priority);
    }

    public function withDescription(string $description): self
    {
        return new self(
            $description,
            $this->arguments,
            $this->tags,
            $this->priority,
        );
    }

    public function withArgument(
        string $name,
        string $description = '',
        bool $required = true,
        mixed $default = null,
    ): self {
        return new self(
            $this->description,
            [...$this->arguments, new CommandArgument($name, $description, $required, $default)],
            $this->tags,
            $this->priority,
        );
    }

    #[\Override]
    public function withTags(string ...$tags): self
    {
        return new self(
            $this->description,
            $this->arguments,
            array_values([...$this->tags, ...$tags]),
            $this->priority,
        );
    }

    #[\Override]
    public function withPriority(int $priority): self
    {
        return new self(
            $this->description,
            $this->arguments,
            $this->tags,
            $priority,
        );
    }
}
