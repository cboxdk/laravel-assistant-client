<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Security;

use RuntimeException;

/** A request could not be verified as a genuine, fresh call from the platform. */
class VerificationException extends RuntimeException {}
