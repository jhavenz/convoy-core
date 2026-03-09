<?php

declare(strict_types=1);

namespace Convoy\Worker\Protocol;

enum MessageType: string
{
    case TaskRequest = 'task';
    case TaskResponse = 'task_response';
    case ServiceCall = 'service_call';
    case ServiceResponse = 'service_response';
}
