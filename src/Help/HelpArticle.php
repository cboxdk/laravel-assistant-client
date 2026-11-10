<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Help;

use Cbox\AiAssistant\Connector\Support\Payload;

/**
 * One docs page, help-centre article or knowledge document. `new HelpArticle` is a valid empty article.
 * `bodyHtml` is set only when the article was read on its own: sanitized HTML (raw HTML stripped,
 * unsafe links refused by Assistant) for showing inside the Help column.
 */
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
        public ?string $bodyHtml = null,
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
            bodyHtml: Payload::nullableString($data['body_html'] ?? null),
        );
    }

    /**
     * The API shape. `body_html` is present only on an article read with {@see HelpClient::article()}.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [
            'id' => $this->id, 'object' => 'help_article', 'source' => $this->source, 'title' => $this->title,
            'excerpt' => $this->excerpt, 'url' => $this->url, 'product' => $this->product, 'updated_at' => $this->updatedAt,
        ];

        return $this->bodyHtml === null ? $array : [...$array, 'body_html' => $this->bodyHtml];
    }
}
