<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Help;

use RuntimeException;

/** Assistant answered a Help call with an error (its problem+json `detail`), or could not be reached. */
class HelpException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message, $status);
    }
}
