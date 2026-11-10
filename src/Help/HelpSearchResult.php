<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Help;

use Cbox\AiAssistant\Connector\Support\Payload;

/** A grouped Help search. `new HelpSearchResult` is a valid empty result. */
readonly class HelpSearchResult
{
    /**
     * @param  list<HelpResultGroup>  $groups
     */
    public function __construct(
        public string $query = '',
        public array $groups = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            query: Payload::string($data['query'] ?? ''),
            groups: array_map(HelpResultGroup::fromArray(...), Payload::objects($data['groups'] ?? [])),
        );
    }

    public function group(string $source): ?HelpResultGroup
    {
        foreach ($this->groups as $group) {
            if ($group->source === $source) {
                return $group;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['object' => 'help_search', 'query' => $this->query, 'groups' => array_map(fn (HelpResultGroup $g): array => $g->toArray(), $this->groups)];
    }
}
