<?php

declare(strict_types=1);

namespace Convoy\Task;

use Convoy\Concurrency\RetryPolicy;
use UnitEnum;

final readonly class TaskConfig
{
    public function __construct(
        public string $name = '',
        public int $priority = 0,
        public ?UnitEnum $pool = null,
        public ?RetryPolicy $retry = null,
        public ?float $timeout = null,
        public ?int $concurrencyLimit = null,
        public bool $trace = true,
        public array $tags = [],
    ) {
    }

    public function with(
        ?string $name = null,
        ?int $priority = null,
        ?UnitEnum $pool = null,
        ?RetryPolicy $retry = null,
        ?float $timeout = null,
        ?int $concurrencyLimit = null,
        ?bool $trace = null,
        ?array $tags = null,
    ): self {
        return new self(
            name: $name ?? $this->name,
            priority: $priority ?? $this->priority,
            pool: $pool ?? $this->pool,
            retry: $retry ?? $this->retry,
            timeout: $timeout ?? $this->timeout,
            concurrencyLimit: $concurrencyLimit ?? $this->concurrencyLimit,
            trace: $trace ?? $this->trace,
            tags: $tags ?? $this->tags,
        );
    }
}
