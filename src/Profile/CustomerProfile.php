<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Profile;

use stdClass;

/**
 * One collected customer profile: the resources this request was allowed to see and the merged
 * account signals. `resourceKeys` lists every resource whose guard passed (served or failed),
 * which is what an elevation audit records.
 */
readonly class CustomerProfile
{
    /**
     * @param  array<string, array{label: string, items: list<ResourceItem>, truncated: bool}|array{label: string, error: string}>  $resources
     * @param  list<string>  $resourceKeys
     */
    public function __construct(
        public array $resources = [],
        public ?Signals $signals = null,
        public array $resourceKeys = [],
    ) {}

    /**
     * The `/profile` response body. `signals` is null when the service registers none, and an
     * object (possibly empty) when it does.
     *
     * @return array{resources: array<string, mixed>|stdClass, signals: array<string, mixed>|stdClass|null}
     */
    public function toArray(): array
    {
        $resources = [];

        foreach ($this->resources as $key => $resource) {
            $resources[$key] = isset($resource['items'])
                ? [
                    'label' => $resource['label'],
                    'items' => array_map(fn (ResourceItem $item): array => $item->toArray(), $resource['items']),
                    'truncated' => $resource['truncated'],
                ]
                : $resource;
        }

        $signals = $this->signals?->toArray();

        return [
            'resources' => $resources === [] ? new stdClass : $resources,
            'signals' => $signals === null ? null : ($signals === [] ? new stdClass : $signals),
        ];
    }
}
