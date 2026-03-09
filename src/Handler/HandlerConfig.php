<?php

declare(strict_types=1);

namespace Convoy\Handler;

/**
 * Base configuration for handlers.
 *
 * Extended by RouteConfig and CommandConfig for protocol-specific metadata.
 *
 * @migration convoy/http, convoy/console
 *
 * Base class shared by both HTTP and console handlers. May remain in
 * convoy-core as shared infrastructure, or be duplicated into each
 * protocol library if they diverge significantly.
 */
readonly class HandlerConfig
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public array $tags = [],
        public int $priority = 0,
    ) {
    }

    public function withTags(string ...$tags): self
    {
        return new self(array_values([...$this->tags, ...$tags]), $this->priority);
    }

    public function withPriority(int $priority): self
    {
        return new self($this->tags, $priority);
    }
}
