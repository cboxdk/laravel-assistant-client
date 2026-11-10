<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector;

use Cbox\AiAssistant\Connector\Embedded\EmbeddedRunner;
use Cbox\AiAssistant\Connector\Embedded\PendingRun;
use Cbox\AiAssistant\Connector\Help\HelpClient;
use Cbox\AiAssistant\Connector\Profile\Signals;
use Cbox\AiAssistant\Connector\Support\ToolContext;
use Closure;
use Illuminate\Container\Container;

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

    /**
     * Start a fluent definition of a customer profile RESOURCE: one kind of thing the customer
     * has in this service (projects, sites, domains, invoices…). Registered immediately; denied
     * until it has a guard returning true. Use a service-prefixed key such as `sites_domains`.
     */
    public function resource(string $key): ResourceBuilder
    {
        $resource = new ResourceBuilder($key);
        $this->registry->registerResource($resource);

        return $resource;
    }

    /**
     * Contribute account SIGNALS to the customer profile (lifecycle, plan, MRR, flags…). The
     * closure runs as the verified principal and must authorize itself; return an empty
     * {@see Signals} to share nothing. Several closures are merged.
     *
     * @param  Closure(ToolContext): (array<string, mixed>|Signals)  $signals
     */
    public function signals(Closure $signals): void
    {
        $this->registry->registerSignals($signals);
    }

    public function assistant(string $key): PendingRun
    {
        return new PendingRun($key, $this->runner);
    }

    public function registry(): ToolRegistry
    {
        return $this->registry;
    }

    /** Help search, page topic articles and support updates (resolved from the container, so fakeable). */
    public function help(): HelpClient
    {
        return Container::getInstance()->make(HelpClient::class);
    }
}
