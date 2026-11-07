<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Embedded;

/**
 * A fluent embedded run:
 *
 *     AiAssistant::assistant('support')
 *         ->as($currentUser)              // your authenticated user — becomes the principal
 *         ->conversation($conversationId) // optional; omit to start a new thread
 *         ->send('Where is my order #10493?');
 *
 * The reasoning is hosted; the tools run in your process. The reply carries the assistant's
 * message and the conversation id to continue with.
 */
class PendingRun
{
    /** @var array<string, mixed> */
    private array $principal = [];

    private ?object $user = null;

    private ?string $conversationId = null;

    public function __construct(
        private readonly string $assistantKey,
        private readonly EmbeddedRunner $runner,
    ) {}

    /**
     * Who the run acts as. Pass your authenticated user model (its key + email become the
     * principal and it is handed to guards/handlers as $ctx->user), or a raw principal
     * array ({id, email, ...}) when there is no model.
     *
     * @param  object|array<string, mixed>  $user
     */
    public function as(object|array $user): self
    {
        // Preserve an account set before as(), so ->forAccount()->as() and
        // ->as()->forAccount() are equivalent.
        $context = array_intersect_key($this->principal, array_flip(['account_id', 'product_id', 'environment', 'locale']));

        if (is_array($user)) {
            $this->principal = $user;
            $this->user = null;
        } else {
            $this->user = $user;
            $this->principal = array_filter([
                'id' => $this->identifier($user),
                'email' => isset($user->email) && is_string($user->email) ? $user->email : null,
                'name' => isset($user->name) && is_string($user->name) ? $user->name : null,
            ], static fn ($v): bool => $v !== null && $v !== '');
        }

        $this->principal = array_replace($context, $this->principal);

        return $this;
    }

    public function conversation(?string $conversationId): self
    {
        $this->conversationId = $conversationId;

        return $this;
    }

    /**
     * Scope the run to an end-customer account (sub-tenant). It scopes which tools the
     * assistant sees, stamps the conversation, and reaches your tools as
     * `$ctx->accountId()` — the boundary for a B2B2B customer serving its own customers.
     */
    public function forAccount(?string $accountId): self
    {
        if ($accountId !== null && $accountId !== '') {
            $this->principal['account_id'] = $accountId;
        }

        return $this;
    }

    /** The language of the reply, independent of the source documents' language. */
    public function locale(string $locale): self
    {
        if (! preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $locale)) {
            throw new \InvalidArgumentException('Invalid reply locale.');
        }

        $this->principal['locale'] = $locale;

        return $this;
    }

    public function forProduct(string $productId): self
    {
        $this->principal['product_id'] = $productId;

        return $this;
    }

    public function inEnvironment(string $environment): self
    {
        $this->principal['environment'] = $environment;

        return $this;
    }

    public function send(string $message): EmbeddedReply
    {
        return $this->runner->run($this->assistantKey, $message, $this->principal, $this->user, $this->conversationId);
    }

    private function identifier(object $user): ?string
    {
        if (method_exists($user, 'getAuthIdentifier')) {
            $id = $user->getAuthIdentifier();
        } elseif (method_exists($user, 'getKey')) {
            $id = $user->getKey();
        } else {
            $id = $user->id ?? null;
        }

        return is_scalar($id) ? (string) $id : null;
    }
}
