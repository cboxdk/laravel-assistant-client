<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Profile;

/** Where the customer's account is in its commercial lifecycle. */
enum Lifecycle: string
{
    case Trial = 'trial';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceling = 'canceling';
    case Canceled = 'canceled';
}
