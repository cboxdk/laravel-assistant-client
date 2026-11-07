<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector;

use Illuminate\Support\Facades\Config;

/**
 * The set of tools this app exposes. A singleton — `AiAssistant::tool()` registers into
 * it, the hook resolves calls against it, and the manifest lists it.
 */
class ToolRegistry
{
    /** @var array<string, ToolBuilder> */
    private array $tools = [];

    /** @var list<array<string, mixed>> opt-in procedure (guided step-flow) exports */
    private array $procedures = [];

    /** @var list<array<string, mixed>> opt-in audience-group exports */
    private array $groups = [];

    /** @var array<string, InboxComponentBuilder> opt-in support-inbox surface exports */
    private array $inboxComponents = [];

    public function register(ToolBuilder $tool): void
    {
        $this->tools[$tool->name] = $tool;
    }

    /**
     * Register a support-inbox surface element (a read-only card or a confirm-gated action) so it
     * appears in discovery and the signed inbox endpoint can resolve calls against it.
     */
    public function registerInboxComponent(InboxComponentBuilder $component): void
    {
        $this->inboxComponents[$component->key] = $component;
    }

    public function inboxComponent(string $key): ?InboxComponentBuilder
    {
        return $this->inboxComponents[$key] ?? null;
    }

    /**
     * Advertise a guided step-flow ("procedure") in the manifest so the platform can adopt
     * it as a managed, locked entity. Advisory — omit it and only tools are synced.
     *
     * @param  array<string, mixed>  $procedure
     */
    public function registerProcedure(array $procedure): void
    {
        $this->procedures[] = $procedure;
    }

    /**
     * Advertise an audience group in the manifest (a segment tools/procedures target).
     *
     * @param  array<string, mixed>  $group
     */
    public function registerGroup(array $group): void
    {
        $this->groups[] = $group;
    }

    public function get(string $name): ?ToolBuilder
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return array<string, ToolBuilder>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function manifest(): array
    {
        return array_values(array_map(static fn (ToolBuilder $t): array => $t->toManifest(), $this->tools));
    }

    /**
     * The full discovery document the platform pulls: a version, the tools (each with its
     * `kind`), and the opt-in procedures/groups exports. `tools` keeps its historical shape
     * so an older importer still reads it.
     *
     * @return array{version: int, tools: list<array<string, mixed>>, procedures: list<array<string, mixed>>, groups: list<array<string, mixed>>, inbox_cards: list<array<string, mixed>>, inbox_actions: list<array<string, mixed>>}
     */
    public function document(): array
    {
        return [
            'version' => 1,
            'tools' => $this->manifest(),
            'procedures' => $this->procedures,
            'groups' => $this->groups,
            'inbox_cards' => $this->inboxManifest(InboxComponentBuilder::KIND_CARD),
            'inbox_actions' => $this->inboxManifest(InboxComponentBuilder::KIND_ACTION),
        ];
    }

    /**
     * The inbox surface of a given kind, each entry carrying the absolute signed endpoint the
     * platform host-pins and calls. The endpoint is this app's own inbox route, so the importer
     * pins the host it discovered the connector under.
     *
     * @return list<array<string, mixed>>
     */
    private function inboxManifest(string $kind): array
    {
        $base = rtrim(Config::string('app.url'), '/');
        $prefix = trim(Config::string('ai-assistant-connector.route_prefix', 'ai-assistant'), '/');

        $out = [];

        foreach ($this->inboxComponents as $component) {
            if ($component->kind !== $kind) {
                continue;
            }

            $endpoint = $base.'/'.$prefix.'/inbox/'.$component->key;
            $out[] = $component->toManifest($endpoint);
        }

        return $out;
    }
}
