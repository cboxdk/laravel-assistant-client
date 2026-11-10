<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Facades;

use Cbox\AiAssistant\Connector\ConnectorManager;
use Cbox\AiAssistant\Connector\Embedded\PendingRun;
use Cbox\AiAssistant\Connector\Help\HelpClient;
use Cbox\AiAssistant\Connector\InboxComponentBuilder;
use Cbox\AiAssistant\Connector\ResourceBuilder;
use Cbox\AiAssistant\Connector\Testing\FakeHelpClient;
use Cbox\AiAssistant\Connector\ToolBuilder;
use Cbox\AiAssistant\Connector\ToolRegistry;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ToolBuilder tool(string $name)
 * @method static InboxComponentBuilder inboxCard(string $key)
 * @method static InboxComponentBuilder inboxAction(string $key)
 * @method static ResourceBuilder resource(string $key)
 * @method static void signals(\Closure $signals)
 * @method static PendingRun assistant(string $key)
 * @method static void procedure(array<string, mixed> $procedure)
 * @method static void group(array<string, mixed> $group)
 * @method static ToolRegistry registry()
 * @method static HelpClient help()
 *
 * @see ConnectorManager
 */
class AiAssistant extends Facade
{
    /** Swap the Help client for an in-memory fake and return it for seeding and assertions. */
    public static function fakeHelp(): FakeHelpClient
    {
        $fake = new FakeHelpClient;
        static::getFacadeApplication()?->instance(HelpClient::class, $fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return ConnectorManager::class;
    }
}
