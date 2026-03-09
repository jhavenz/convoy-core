<?php

declare(strict_types=1);

namespace Convoy\Console;

/**
 * Definition of a command argument.
 *
 * @migration convoy/console
 *
 * Will migrate to the convoy/console library along with argument
 * parsing and validation infrastructure.
 */
final readonly class CommandArgument
{
    public function __construct(
        public string $name,
        public string $description = '',
        public bool $required = true,
        public mixed $default = null,
    ) {
    }
}
