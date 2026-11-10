<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Help;

use RuntimeException;
use Throwable;

/**
 * Assistant answered a Help call with an error (its problem+json `detail`), could not be reached, or
 * the support connection is not configured (status 0).
 */
class HelpException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }
}
