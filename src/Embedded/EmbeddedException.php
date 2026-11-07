<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Embedded;

use RuntimeException;

/**
 * A failure driving an embedded run — the reasoning API was unreachable or errored, or the
 * loop ran away. Tool failures do NOT raise this; they are returned to the model as an
 * error result so it can recover.
 */
class EmbeddedException extends RuntimeException {}
