<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Embedded;

/**
 * The result of an embedded run: the assistant's final message, the conversation it
 * belongs to (pass it back to continue the thread), and how many tool rounds it took.
 */
readonly class EmbeddedReply
{
    public function __construct(
        public string $content = '',
        public string $conversationId = '',
        public int $toolRounds = 0,
    ) {}

    public function __toString(): string
    {
        return $this->content;
    }
}
