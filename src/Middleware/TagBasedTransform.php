<?php

declare(strict_types=1);

namespace Convoy\Middleware;

use Convoy\Service\ServiceDefinition;

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
