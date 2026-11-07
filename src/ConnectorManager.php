<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector;

use Cbox\AiAssistant\Connector\Embedded\EmbeddedRunner;
use Cbox\AiAssistant\Connector\Embedded\PendingRun;

/**
 * The entry point behind the `AiAssistant` facade. `tool()` starts a fluent definition
 * and registers it immediately, so partially-defined tools still show up for discovery;
 * `assistant()` starts a backend-driven run where this app drives the loop and executes
 * its own registered tools in-process.
 */
class ConnectorManager
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly EmbeddedRunner $runner,
    ) {}

    public function tool(string $name): ToolBuilder
    {
        $tool = new ToolBuilder($name);
        $this->registry->register($tool);

        return $tool;
    }

    /**
     * Advertise a guided step-flow ("procedure") in the discovery manifest so the platform
     * can adopt it as a managed, locked entity alongside the tools. Advisory/opt-in.
     *
     * @param  array<string, mixed>  $procedure
     */
    public function procedure(array $procedure): void
    {
        $this->registry->registerProcedure($procedure);
    }

    /**
     * Advertise an audience group (a segment tools/procedures target) in the manifest.
     *
     * @param  array<string, mixed>  $group
     */
    public function group(array $group): void
    {
        $this->registry->registerGroup($group);
    }

    /**
     * Start a fluent definition of a read-only support-inbox CARD (customer context an agent sees
     * in the inbox sidebar: plan, usage, billing…). Registered immediately so it shows up for
     * discovery.
     */
    public function inboxCard(string $key): InboxComponentBuilder
    {
        $component = new InboxComponentBuilder($key, InboxComponentBuilder::KIND_CARD);
        $this->registry->registerInboxComponent($component);

        return $component;
    }

    /**
     * Start a fluent definition of a confirm-gated support-inbox ACTION (a button/form an agent
     * can fire on the conversation: "Retry job", "Extend trial"…). Registered immediately.
     */
    public function inboxAction(string $key): InboxComponentBuilder
    {
        $component = new InboxComponentBuilder($key, InboxComponentBuilder::KIND_ACTION);
        $this->registry->registerInboxComponent($component);

        return $component;
    }

    public function assistant(string $key): PendingRun
    {
        return new PendingRun($key, $this->runner);
    }

    public function registry(): ToolRegistry
    {
        return $this->registry;
    }
}
