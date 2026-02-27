<?php

declare(strict_types=1);

namespace Convoy\Service;

use Closure;
use Convoy\Lifecycle\LifecycleCallbacks;

final readonly class CompiledService
{
    /**
     * @param class-string $type
     */
    public function __construct(
        public string $type,
        /** @var list<string> Topologically sorted dependencies */
        public array $dependencyOrder,
        public Closure $factory,
        public bool $singleton,
        public bool $lazy,
        public LifecycleCallbacks $lifecycle,
    ) {
    }

    public function shortName(): string
    {
        $parts = explode('\\', $this->type);
        return end($parts);
    }
}
