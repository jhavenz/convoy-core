<?php

declare(strict_types=1);

namespace Convoy\Service;

use Closure;
use Convoy\Lifecycle\LifecycleCallbacks;
use Convoy\Support\ClassNames;

final readonly class ServiceDefinition
{
    /**
     * @param class-string $type
     * @param list<string> $implements
     * @param list<string> $tags
     * @param list<string> $dependencies
     */
    public function __construct(
        public string $type,
        public array $implements = [],
        public array $tags = [],
        public array $dependencies = [],
        public ?Closure $factory = null,
        public bool $singleton = true,
        public bool $lazy = true,
        public LifecycleCallbacks $lifecycle = new LifecycleCallbacks(),
    ) {
    }

    public function withFactory(Closure $factory): self
    {
        return new self(
            $this->type,
            $this->implements,
            $this->tags,
            $this->dependencies,
            $factory,
            $this->singleton,
            $this->lazy,
            $this->lifecycle,
        );
    }

    public function withDependencies(string ...$deps): self
    {
        return new self(
            $this->type,
            $this->implements,
            $this->tags,
            array_values([...$this->dependencies, ...$deps]),
            $this->factory,
            $this->singleton,
            $this->lazy,
            $this->lifecycle,
        );
    }

    public function withTags(string ...$tags): self
    {
        return new self(
            $this->type,
            $this->implements,
            array_values([...$this->tags, ...$tags]),
            $this->dependencies,
            $this->factory,
            $this->singleton,
            $this->lazy,
            $this->lifecycle,
        );
    }

    public function withImplements(string ...$interfaces): self
    {
        return new self(
            $this->type,
            array_values([...$this->implements, ...$interfaces]),
            $this->tags,
            $this->dependencies,
            $this->factory,
            $this->singleton,
            $this->lazy,
            $this->lifecycle,
        );
    }

    public function asSingleton(): self
    {
        return new self(
            $this->type,
            $this->implements,
            $this->tags,
            $this->dependencies,
            $this->factory,
            true,
            $this->lazy,
            $this->lifecycle,
        );
    }

    public function asScoped(): self
    {
        return new self(
            $this->type,
            $this->implements,
            $this->tags,
            $this->dependencies,
            $this->factory,
            false,
            $this->lazy,
            $this->lifecycle,
        );
    }

    public function asLazy(): self
    {
        return new self(
            $this->type,
            $this->implements,
            $this->tags,
            $this->dependencies,
            $this->factory,
            $this->singleton,
            true,
            $this->lifecycle,
        );
    }

    public function asEager(): self
    {
        return new self(
            $this->type,
            $this->implements,
            $this->tags,
            $this->dependencies,
            $this->factory,
            $this->singleton,
            false,
            $this->lifecycle,
        );
    }

    public function withLifecycleHook(string $phase, Closure $hook): self
    {
        return new self(
            $this->type,
            $this->implements,
            $this->tags,
            $this->dependencies,
            $this->factory,
            $this->singleton,
            $this->lazy,
            $this->lifecycle->withHook($phase, $hook),
        );
    }

    public function withLifecycle(LifecycleCallbacks $lifecycle): self
    {
        return new self(
            $this->type,
            $this->implements,
            $this->tags,
            $this->dependencies,
            $this->factory,
            $this->singleton,
            $this->lazy,
            $lifecycle,
        );
    }

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    public function shortName(): string
    {
        return ClassNames::short($this->type);
    }
}
