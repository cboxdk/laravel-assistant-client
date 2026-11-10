<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Help;

use Cbox\AiAssistant\Connector\Support\Payload;

/** One docs page, help-centre article or knowledge document. `new HelpArticle` is a valid empty article. */
readonly class HelpArticle
{
    public function __construct(
        public string $id = '',
        public string $source = 'knowledge_base',
        public string $title = '',
        public ?string $excerpt = null,
        public string $url = '',
        public ?string $product = null,
        public ?string $updatedAt = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Payload::string($data['id'] ?? ''),
            source: Payload::string($data['source'] ?? 'knowledge_base'),
            title: Payload::string($data['title'] ?? ''),
            excerpt: Payload::nullableString($data['excerpt'] ?? null),
            url: Payload::string($data['url'] ?? ''),
            product: Payload::nullableString($data['product'] ?? null),
            updatedAt: Payload::nullableString($data['updated_at'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'object' => 'help_article', 'source' => $this->source, 'title' => $this->title,
            'excerpt' => $this->excerpt, 'url' => $this->url, 'product' => $this->product, 'updated_at' => $this->updatedAt,
        ];
    }
}
