<?php

declare(strict_types=1);

namespace Convoy\Lifecycle;

use Closure;

final readonly class LifecycleCallbacks
{
    public function __construct(
        /** @var list<Closure> */
        public array $onInit = [],
        /** @var list<Closure> */
        public array $onStartup = [],
        /** @var list<Closure> */
        public array $onDispose = [],
        /** @var list<Closure> */
        public array $onShutdown = [],
    ) {
    }

    public function withHook(string $phase, Closure $hook): self
    {
        return match ($phase) {
            'init', 'onInit' => new self(
                [...$this->onInit, $hook],
                $this->onStartup,
                $this->onDispose,
                $this->onShutdown,
            ),
            'startup', 'onStartup' => new self(
                $this->onInit,
                [...$this->onStartup, $hook],
                $this->onDispose,
                $this->onShutdown,
            ),
            'dispose', 'onDispose' => new self(
                $this->onInit,
                $this->onStartup,
                [...$this->onDispose, $hook],
                $this->onShutdown,
            ),
            'shutdown', 'onShutdown' => new self(
                $this->onInit,
                $this->onStartup,
                $this->onDispose,
                [...$this->onShutdown, $hook],
            ),
            default => throw new \InvalidArgumentException("Unknown lifecycle phase: $phase"),
        };
    }

    public function hasInit(): bool
    {
        return count($this->onInit) > 0;
    }

    public function hasStartup(): bool
    {
        return count($this->onStartup) > 0;
    }

    public function hasDispose(): bool
    {
        return count($this->onDispose) > 0;
    }

    public function hasShutdown(): bool
    {
        return count($this->onShutdown) > 0;
    }

    public function merge(self $other): self
    {
        return new self(
            [...$this->onInit, ...$other->onInit],
            [...$this->onStartup, ...$other->onStartup],
            [...$this->onDispose, ...$other->onDispose],
            [...$this->onShutdown, ...$other->onShutdown],
        );
    }
}
