<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Profile;

/** The coarse state of one customer resource, rendered as a status dot by Assistant. */
enum ResourceStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';
    case Inactive = 'inactive';
}
