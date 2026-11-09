<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Events;

use Cbox\AiAssistant\Connector\Support\SupportAccess;
use Cbox\AiAssistant\Connector\Support\ToolContext;
use Illuminate\Support\Facades\Event;

/**
 * A signed request carrying a valid support elevation reached your service. Listen to it to keep
 * your own record of which staff member looked at which customer, why, and through what — the
 * Assistant side logs the same elevation, so both sides can be compared.
 *
 * `surface` is `profile`, `tool` or `inbox`. `keys` are the resource keys the profile served,
 * the tool name, or the inbox component key.
 */
class SupportAccessUsed
{
    public const SURFACE_PROFILE = 'profile';

    public const SURFACE_TOOL = 'tool';

    public const SURFACE_INBOX = 'inbox';

    /**
     * @param  list<string>  $keys
     */
    public function __construct(
        public readonly SupportAccess $access,
        public readonly string $surface,
        public readonly array $keys,
        public readonly ?string $subject,
        public readonly ?string $accountId,
    ) {}

    /**
     * Dispatch the event when the context carries a valid elevation; otherwise do nothing.
     *
     * @param  list<string>  $keys
     */
    public static function dispatchFor(ToolContext $ctx, string $surface, array $keys): void
    {
        $access = $ctx->supportAccess();

        if ($access !== null) {
            Event::dispatch(new self($access, $surface, $keys, $ctx->userId(), $ctx->accountId()));
        }
    }
}
