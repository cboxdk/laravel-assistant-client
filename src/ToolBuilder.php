<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector;

use Cbox\AiAssistant\Connector\Support\ToolContext;
use Closure;

/**
 * A fluent definition of one tool your app exposes to the assistant. Registered the
 * moment it is created, so it appears in the discovery manifest even before `handle()`
 * is set. `guard` runs first (deny → the assistant is told it can't); `handle` receives
 * the declared arguments plus the {@see ToolContext}.
 */
class ToolBuilder
{
    /**
     * The delivery topologies a tool can be exposed under. `sdk-bridge` (the default) is
     * the security-first path: the platform never calls the tool's endpoint directly — it
     * POSTs a signed request to this app's `/hook`, and the handler runs in-process. The
     * others declare a target the platform calls itself: a plain HTTP `api`, a remote
     * `mcp` server, or a `db` query. Omitting the kind means `sdk-bridge`.
     */
    public const KINDS = ['sdk-bridge', 'api', 'http', 'mcp', 'db'];

    private string $description = '';

    /** @var array<string, mixed> the JSON Schema for arguments */
    private array $schema = ['type' => 'object', 'properties' => [], 'required' => []];

    private ?Closure $guard = null;

    private ?Closure $handler = null;

    private string $kind = 'sdk-bridge';

    private ?string $endpoint = null;

    /** @var array<string, mixed>|null server coordinates when kind is `mcp` */
    private ?array $mcp = null;

    public function __construct(public readonly string $name) {}

    /**
     * Declare how the platform reaches this tool. `sdk-bridge` (default) keeps execution in
     * this process behind the signed `/hook`; `api`/`http` and `mcp` hand the platform a
     * target it calls itself (see {@see endpoint()} / {@see mcpServer()}). An unknown kind
     * is ignored, so a typo can never silently downgrade the security posture.
     */
    public function kind(string $kind): self
    {
        if (in_array($kind, self::KINDS, true)) {
            $this->kind = $kind;
        }

        return $this;
    }

    /**
     * The HTTPS endpoint the platform calls directly for an `api`/`http` tool. Only used
     * when the kind is one of those — a bridge tool travels signed through `/hook` instead.
     */
    public function endpoint(string $url): self
    {
        $this->endpoint = $url;

        return $this;
    }

    /**
     * Server coordinates for an `mcp` tool (e.g. `['url' => 'https://…']`). Advisory: the
     * platform pins the host it registers, never a value the model supplies.
     *
     * @param  array<string, mixed>  $server
     */
    public function mcpServer(array $server): self
    {
        $this->mcp = $server;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Declare arguments. Either a simple map (`['order_number' => 'string']`, all required)
     * or a full JSON Schema array (detected by a top-level `type` key). Append `?` to a
     * type to mark it optional: `['note' => 'string?']`.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function parameters(array $parameters): self
    {
        if (($parameters['type'] ?? null) === 'object') {
            $this->schema = $parameters;

            return $this;
        }

        $properties = [];
        $required = [];

        foreach ($parameters as $name => $type) {
            if (! is_string($type)) {
                throw new \InvalidArgumentException('Use string type names or a JSON object schema.');
            }
            $optional = str_ends_with($type, '?');
            $base = $optional ? substr($type, 0, -1) : $type;
            $properties[$name] = ['type' => in_array($base, ['string', 'integer', 'number', 'boolean'], true) ? $base : 'string'];

            if (! $optional) {
                $required[] = $name;
            }
        }

        $this->schema = ['type' => 'object', 'properties' => $properties, 'required' => $required];

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

    public function passesGuard(ToolContext $ctx): bool
    {
        return $this->guard !== null && ($this->guard)($ctx) === true;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function run(array $arguments, ToolContext $ctx): mixed
    {
        if ($this->handler === null) {
            throw new \RuntimeException("Tool [{$this->name}] has no handler.");
        }

        return ($this->handler)($arguments, $ctx);
    }

    /**
     * The public shape advertised in the discovery manifest — never the closures.
     *
     * @return array<string, mixed>
     */
    public function toManifest(): array
    {
        $entry = [
            'name' => $this->name,
            'description' => $this->description,
            'parameters' => $this->schema,
            'kind' => $this->kind,
        ];

        // Only the kinds the platform calls directly advertise a target; a bridge tool has
        // none (it is reached through the signed /hook), so we never leak an unused field.
        if (in_array($this->kind, ['api', 'http'], true) && $this->endpoint !== null) {
            $entry['endpoint'] = $this->endpoint;
        }

        if ($this->kind === 'mcp' && $this->mcp !== null) {
            $entry['mcp'] = $this->mcp;
        }

        return $entry;
    }
}
