<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Help;

use Cbox\AiAssistant\Connector\Support\Payload;

/** One cursor page of support updates. `new SupportUpdatePage` is a valid empty page. */
readonly class SupportUpdatePage
{
    /**
     * @param  list<SupportUpdate>  $data
     */
    public function __construct(
        public array $data = [],
        public bool $hasMore = false,
        public ?string $nextCursor = null,
        public int $unreadCount = 0,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $unread = $data['unread_count'] ?? 0;

        return new self(
            data: array_map(SupportUpdate::fromArray(...), Payload::objects($data['data'] ?? [])),
            hasMore: ($data['has_more'] ?? false) === true,
            nextCursor: Payload::nullableString($data['next_cursor'] ?? null),
            unreadCount: is_int($unread) ? $unread : 0,
        );
    }
}
