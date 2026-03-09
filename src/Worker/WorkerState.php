<?php

declare(strict_types=1);

namespace Convoy\Worker;

enum WorkerState
{
    case Idle;
    case Busy;
    case Crashed;
    case Draining;
}
