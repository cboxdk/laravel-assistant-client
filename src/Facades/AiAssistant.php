<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Facades;

use Cbox\AiAssistant\Connector\ConnectorManager;
use Cbox\AiAssistant\Connector\Embedded\PendingRun;
use Cbox\AiAssistant\Connector\InboxComponentBuilder;
use Cbox\AiAssistant\Connector\ToolBuilder;
use Cbox\AiAssistant\Connector\ToolRegistry;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ToolBuilder tool(string $name)
 * @method static InboxComponentBuilder inboxCard(string $key)
 * @method static InboxComponentBuilder inboxAction(string $key)
 * @method static PendingRun assistant(string $key)
 * @method static void procedure(array<string, mixed> $procedure)
 * @method static void group(array<string, mixed> $group)
 * @method static ToolRegistry registry()
 *
 * @see ConnectorManager
 */
class AiAssistant extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ConnectorManager::class;
    }
}
