<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector;

use Cbox\AiAssistant\Connector\Profile\ResourceItem;
use Cbox\AiAssistant\Connector\Support\ToolContext;
use Closure;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * A fluent definition of one kind of thing a customer has in your service (projects, sites,
 * domains, subscriptions, invoices…), served through the signed customer profile endpoint.
 *
 * Like {@see ToolBuilder}, it registers on creation and is deny-by-default: without a guard
 * returning true, the resource is left out of every profile response. `list` receives the
 * {@see ToolContext} and the request filters and returns {@see ResourceItem}s or arrays in the
 * same shape. At most {@see MAX_ITEMS} items are served; more marks the result truncated.
 */
class ResourceBuilder
{
    public const MAX_ITEMS = 50;

    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,40}$/';

    private string $label = '';

    private string $singular = '';

    private bool $pickable = false;

    private ?Closure $guard = null;

    private ?Closure $lister = null;

    public function __construct(public readonly string $key)
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Resource key [{$key}] must match [a-z][a-z0-9_]{0,40}, e.g. sites_domains.");
        }
    }

    /** The plural label shown to staff and customers ("Domains"). Defaults to the key. */
    public function label(string $plural): self
    {
        $this->label = trim($plural);

        return $this;
    }

    /** The singular label ("Domain"). Defaults to the plural label. */
    public function singular(string $singular): self
    {
        $this->singular = trim($singular);

        return $this;
    }

    /**
     * Allow Assistant to offer this resource as a field on the customer's support request form
     * ("Which domain is this about?"). The list still runs as the customer, behind the guard.
     */
    public function pickable(bool $pickable = true): self
    {
        $this->pickable = $pickable;

        return $this;
    }

    /** @param  Closure(ToolContext): bool  $guard */
    public function guard(Closure $guard): self
    {
        $this->guard = $guard;

        return $this;
    }

    /** @param  Closure(ToolContext, array<string, string>): iterable<mixed>  $lister */
    public function list(Closure $lister): self
    {
        $this->lister = $lister;

        return $this;
    }

    public function passesGuard(ToolContext $ctx): bool
    {
        return $this->guard !== null && ($this->guard)($ctx) === true;
    }

    public function displayLabel(): string
    {
        return $this->label !== '' ? $this->label : $this->key;
    }

    public function displaySingular(): string
    {
        return $this->singular !== '' ? $this->singular : $this->displayLabel();
    }

    public function isPickable(): bool
    {
        return $this->pickable;
    }

    /**
     * Run the list closure and normalise its items. Stops reading after one item past the cap,
     * so a lazy collection or generator is never drained.
     *
     * @param  array<string, string>  $filters
     * @return array{items: list<ResourceItem>, truncated: bool}
     */
    public function items(ToolContext $ctx, array $filters = []): array
    {
        if ($this->lister === null) {
            throw new \RuntimeException("Resource [{$this->key}] has no list closure.");
        }

        $result = ($this->lister)($ctx, $filters);

        if (! is_iterable($result)) {
            throw new UnexpectedValueException("Resource [{$this->key}] must list an iterable.");
        }

        $items = [];
        $truncated = false;

        foreach ($result as $item) {
            if (count($items) >= self::MAX_ITEMS) {
                $truncated = true;
                break;
            }

            $items[] = match (true) {
                $item instanceof ResourceItem => $item,
                is_array($item) => ResourceItem::fromArray($item),
                default => throw new UnexpectedValueException("Resource [{$this->key}] listed an item that is not a ResourceItem or array."),
            };
        }

        return ['items' => $items, 'truncated' => $truncated];
    }

    /**
     * The public shape advertised in the discovery manifest — never the closures.
     *
     * @return array{key: string, label: string, singular: string, pickable: bool}
     */
    public function toManifest(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->displayLabel(),
            'singular' => $this->displaySingular(),
            'pickable' => $this->pickable,
        ];
    }
}
