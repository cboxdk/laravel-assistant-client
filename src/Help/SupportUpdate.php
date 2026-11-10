<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Help;

use Cbox\AiAssistant\Connector\Support\Payload;

/**
 * A human reply on one of the person's tickets (`kind: ticket`, opens `url` in the portal) or chats
 * (`kind: chat`, resumes `conversationId` in the Help column).
 */
readonly class SupportUpdate
{
    public function __construct(
        public string $id = '',
        public string $kind = 'chat',
        public string $title = '',
        public string $excerpt = '',
        public ?string $author = null,
        public ?int $ticketNumber = null,
        public ?string $conversationId = null,
        public ?string $url = null,
        public bool $isRead = false,
        public ?string $readAt = null,
        public ?string $createdAt = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $number = $data['ticket_number'] ?? null;

        return new self(
            id: Payload::string($data['id'] ?? ''),
            kind: Payload::string($data['kind'] ?? 'chat'),
            title: Payload::string($data['title'] ?? ''),
            excerpt: Payload::string($data['excerpt'] ?? ''),
            author: Payload::nullableString($data['author'] ?? null),
            ticketNumber: is_int($number) ? $number : null,
            conversationId: Payload::nullableString($data['conversation_id'] ?? null),
            url: Payload::nullableString($data['url'] ?? null),
            isRead: ($data['is_read'] ?? false) === true,
            readAt: Payload::nullableString($data['read_at'] ?? null),
            createdAt: Payload::nullableString($data['created_at'] ?? null),
        );
    }

    public function read(string $at): self
    {
        return new self($this->id, $this->kind, $this->title, $this->excerpt, $this->author, $this->ticketNumber,
            $this->conversationId, $this->url, true, $at, $this->createdAt);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'object' => 'support_update', 'kind' => $this->kind, 'title' => $this->title,
            'excerpt' => $this->excerpt, 'author' => $this->author, 'ticket_number' => $this->ticketNumber,
            'conversation_id' => $this->conversationId, 'url' => $this->url, 'is_read' => $this->isRead,
            'read_at' => $this->readAt, 'created_at' => $this->createdAt,
        ];
    }
}
