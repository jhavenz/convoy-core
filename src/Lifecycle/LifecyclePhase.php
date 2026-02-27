<?php

declare(strict_types=1);

namespace Convoy\Lifecycle;

enum LifecyclePhase: string
{
    case Starting = 'starting';
    case Startup = 'startup';
    case Shutdown = 'shutdown';
}
