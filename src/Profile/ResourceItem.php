<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Profile;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * One thing a customer has in your service: a project, a site, a domain, a subscription, an
 * invoice. Assistant shows it in the staff customer card and, for a pickable resource, offers
 * it as a choice on the customer's support request form.
 *
 * The constructor normalises instead of trusting: labels and attribute strings are cut to 200
 * characters, a non-HTTPS `url` and a malformed `parent` reference are dropped, and at most 8
 * attributes and 8 metrics are kept. Only an empty `id` or `label` is refused, because an item
 * nobody can name or select is a bug in the list closure.
 */
readonly class ResourceItem
{
    public const MAX_ATTRIBUTES = 8;

    public const MAX_METRICS = 8;

    public string $id;

    public string $label;

    public ?string $statusLabel;

    public ?string $url;

    public ?string $parent;

    /** @var array<string, scalar|null> */
    public array $attributes;

    /** @var array<string, int|float> */
    public array $metrics;

    public ?DateTimeImmutable $updatedAt;

    /**
     * @param  ?string  $parent  a reference to another resource item, `resourcekey:id`
     * @param  array<string, mixed>  $attributes  scalar or null values; others are dropped
     * @param  array<string, mixed>  $metrics  int or float values; others are dropped
     */
    public function __construct(
        string $id,
        string $label,
        public ?ResourceStatus $status = null,
        ?string $statusLabel = null,
        ?string $url = null,
        ?string $parent = null,
        array $attributes = [],
        array $metrics = [],
        ?DateTimeInterface $updatedAt = null,
    ) {
        $this->id = Normalize::text($id, 200);
        $this->label = Normalize::text($label, 200);

        if ($this->id === '' || $this->label === '') {
            throw new InvalidArgumentException('A resource item needs a non-empty id and label.');
        }

        $statusLabel = $statusLabel === null ? '' : Normalize::text($statusLabel, 120);
        $this->statusLabel = $statusLabel === '' ? null : $statusLabel;
        $this->url = Normalize::httpsUrl($url);
        $this->parent = $parent !== null && preg_match('/^[a-z][a-z0-9_]{0,40}:\S{1,200}$/', $parent) === 1 ? $parent : null;
        $this->attributes = Normalize::scalars($attributes, self::MAX_ATTRIBUTES, allowNull: true);
        $this->metrics = self::numbers($metrics);
        $this->updatedAt = $updatedAt === null ? null : DateTimeImmutable::createFromInterface($updatedAt);
    }

    /**
     * Build an item from a plain array using the same keys as {@see toArray()} (snake_case or
     * camelCase). Unknown keys and invalid optional values are dropped; a missing id or label
     * throws.
     *
     * @param  array<array-key, mixed>  $item
     */
    public static function fromArray(array $item): self
    {
        $id = $item['id'] ?? null;
        $label = $item['label'] ?? null;

        if (! (is_string($id) || is_int($id)) || ! is_string($label)) {
            throw new InvalidArgumentException('A resource item needs a string id and label.');
        }

        $status = $item['status'] ?? null;
        $statusLabel = $item['status_label'] ?? $item['statusLabel'] ?? null;
        $url = $item['url'] ?? null;
        $parent = $item['parent'] ?? null;
        $attributes = $item['attributes'] ?? [];
        $metrics = $item['metrics'] ?? [];

        return new self(
            id: (string) $id,
            label: $label,
            status: $status instanceof ResourceStatus ? $status : (is_string($status) ? ResourceStatus::tryFrom($status) : null),
            statusLabel: is_string($statusLabel) ? $statusLabel : null,
            url: is_string($url) ? $url : null,
            parent: is_string($parent) ? $parent : null,
            attributes: is_array($attributes) ? self::named($attributes) : [],
            metrics: is_array($metrics) ? self::named($metrics) : [],
            updatedAt: Normalize::date($item['updated_at'] ?? $item['updatedAt'] ?? null),
        );
    }

    /**
     * The wire shape Assistant receives.
     *
     * @return array{id: string, label: string, status: ?string, status_label: ?string, url: ?string, parent: ?string, attributes: array<string, scalar|null>, metrics: array<string, int|float>, updated_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'status' => $this->status?->value,
            'status_label' => $this->statusLabel,
            'url' => $this->url,
            'parent' => $this->parent,
            'attributes' => $this->attributes,
            'metrics' => $this->metrics,
            'updated_at' => $this->updatedAt?->format(DATE_ATOM),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    private static function named(array $values): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, int|float>
     */
    private static function numbers(array $metrics): array
    {
        $out = [];

        foreach ($metrics as $key => $value) {
            if (count($out) >= self::MAX_METRICS) {
                break;
            }

            $name = Normalize::text($key, 64);

            if ($name === '' || ! (is_int($value) || (is_float($value) && is_finite($value)))) {
                continue;
            }

            $out[$name] = $value;
        }

        return $out;
    }
}
