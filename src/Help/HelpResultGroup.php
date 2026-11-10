<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Help;

use Cbox\AiAssistant\Connector\Support\Payload;

/** The results from one source: `docs`, `help_centre` or `knowledge_base`. */
readonly class HelpResultGroup
{
    /**
     * @param  list<HelpArticle>  $data
     */
    public function __construct(
        public string $source = 'knowledge_base',
        public string $label = '',
        public array $data = [],
        public bool $hasMore = false,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            source: Payload::string($data['source'] ?? ''),
            label: Payload::string($data['label'] ?? ''),
            data: array_map(HelpArticle::fromArray(...), Payload::objects($data['data'] ?? [])),
            hasMore: ($data['has_more'] ?? false) === true,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'object' => 'help_result_group', 'source' => $this->source, 'label' => $this->label,
            'data' => array_map(fn (HelpArticle $a): array => $a->toArray(), $this->data), 'has_more' => $this->hasMore,
        ];
    }
}
