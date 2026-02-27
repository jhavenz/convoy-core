<?php

declare(strict_types=1);

namespace Convoy\Middleware;

use Convoy\Service\ServiceDefinition;

interface ServiceTransform
{
    public function __invoke(ServiceDefinition $def): ServiceDefinition;
}

interface ConditionalTransform extends ServiceTransform
{
    public function applies(ServiceDefinition $def): bool;
}

abstract class TagBasedTransform implements ConditionalTransform
{
    public function __construct(
        private readonly string $tag,
    ) {
    }

    public function applies(ServiceDefinition $def): bool
    {
        return $def->hasTag($this->tag);
    }
}
