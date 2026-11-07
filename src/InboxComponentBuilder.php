<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector;

use Closure;

/**
 * A fluent definition of one support-inbox surface element this app exposes to the platform: a
 * read-only `card` (customer context an agent sees in the inbox sidebar — plan, usage, billing…)
 * or a confirm-gated `action` (a button/form an agent can fire — "Retry job", "Extend trial"…).
 *
 * Like {@see ToolBuilder}, it registers on creation so it appears in discovery even before its
 * handler is set. The platform reaches it through the signed inbox endpoint (verified exactly like
 * a tool hook): a `card` is hydrated with a GET carrying the on-behalf principal; an `action` is
 * fired with a POST once the agent confirms. Business logic + authorization stay in your app.
 */
class InboxComponentBuilder
{
    public const KIND_CARD = 'card';

    public const KIND_ACTION = 'action';

    private string $title = '';

    private string $label = '';

    private string $confirm = '';

    /** @var array<string, mixed> the declarative field/column schema the inbox renders */
    private array $schema = [];

    private ?Closure $guard = null;

    private ?Closure $handler = null;

    public function __construct(
        public readonly string $key,
        public readonly string $kind,
    ) {}

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /** The button label for an action (defaults to the title). */
    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * The in-app confirmation prompt shown before an action fires ("Retry this job?"). Empty
     * means the platform still requires a generic confirm — an action never fires silently.
     */
    public function confirm(string $confirm): self
    {
        $this->confirm = $confirm;

        return $this;
    }

    /**
     * The declarative schema the inbox renders: for a card, the display fields
     * (`['fields' => [['key' => 'plan', 'label' => 'Plan']]]`); for an action, the form inputs.
     *
     * @param  array<string, mixed>  $schema
     */
    public function schema(array $schema): self
    {
        $this->schema = $schema;

        return $this;
    }

    public function guard(Closure $guard): self
    {
        $this->guard = $guard;

        return $this;
    }

    public function handle(Closure $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    public function passesGuard(Support\ToolContext $ctx): bool
    {
        return $this->guard !== null && ($this->guard)($ctx) === true;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function run(array $arguments, Support\ToolContext $ctx): mixed
    {
        if ($this->handler === null) {
            throw new \RuntimeException("Inbox component [{$this->key}] has no handler.");
        }

        return ($this->handler)($arguments, $ctx);
    }

    /**
     * The public shape advertised in the discovery manifest — never the closures. `endpoint` is
     * the signed inbox route the platform host-pins and calls; the platform re-derives it from the
     * connector base, but we advertise the absolute URL so the importer can pin the host.
     *
     * @return array<string, mixed>
     */
    public function toManifest(string $endpoint): array
    {
        $entry = [
            'key' => $this->key,
            'title' => $this->title !== '' ? $this->title : $this->key,
            'schema' => $this->schema,
            'endpoint' => $endpoint,
        ];

        if ($this->kind === self::KIND_ACTION) {
            $entry['label'] = $this->label !== '' ? $this->label : $entry['title'];
            $entry['confirm'] = $this->confirm;
        }

        return $entry;
    }
}
