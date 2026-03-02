<?php

declare(strict_types=1);

namespace Convoy\Middleware;

use Convoy\Service\ServiceDefinition;

interface ConditionalTransform extends ServiceTransform
{
    public function applies(ServiceDefinition $def): bool;
}
