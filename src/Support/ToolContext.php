<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Support;

/**
 * The context handed to a tool's guard and handler. `principal` is the verified end-user
 * as asserted by the platform (`sub`, `email`, `name`, `session_type`, `account_id`,
 * `verified`); `user` is that principal resolved to your own model (or null if
 * unknown/anonymous). Authorization is yours: read `user`/`principal` and decide.
 *
 * For B2B2B (you serve your own customers), `accountId()` is the end-customer this call
 * acts for — ALWAYS scope your query by it: `Order::where('account_id', $ctx->accountId())`.
 */
readonly class ToolContext
{
    /**
     * @param  array<string, mixed>  $principal
     */
    public function __construct(
        public array $principal = [],
        public ?object $user = null,
        public bool $verified = false,
    ) {}

    public function userId(): ?string
    {
        $sub = $this->principal['sub'] ?? null;

        return is_scalar($sub) ? (string) $sub : null;
    }

    public function email(): ?string
    {
        $email = $this->principal['email'] ?? null;

        return is_string($email) ? $email : null;
    }

    /**
     * The end-customer account (sub-tenant) this call acts on behalf of, or null when the
     * session isn't account-scoped. Scope every data access by it.
     */
    public function accountId(): ?string
    {
        $account = $this->principal['account_id'] ?? null;

        return is_scalar($account) && (string) $account !== '' ? (string) $account : null;
    }
}
