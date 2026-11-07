<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Tests;

use Cbox\AiAssistant\Connector\Facades\AiAssistant;
use Cbox\AiAssistant\Connector\Support\ToolContext;
use Firebase\JWT\JWT;
use Illuminate\Support\Str;

final class ConnectorTest extends TestCase
{
    public function test_a_signed_call_runs_the_handler_with_the_verified_principal(): void
    {
        AiAssistant::tool('lookup_order')
            ->description('Look up an order')
            ->parameters(['order_number' => 'string'])
            ->guard(fn (): bool => true)->handle(fn (array $args, ToolContext $ctx): array => [
                'order' => $args['order_number'],
                'for_user' => $ctx->userId(),
                'verified' => $ctx->verified,
                'scope_is_array' => is_array($ctx->principal['context']),
                'scope' => $ctx->principal['context'],
            ]);

        $jwt = $this->sign([
            'jti' => (string) Str::uuid(),
            'tool' => 'lookup_order',
            'arguments' => ['order_number' => '10493'],
            'principal' => ['sub' => 'user_7', 'verified' => true, 'context' => ['service' => 'billing', 'tenant' => 'company']],
        ]);

        $this->postJson('/ai-assistant/hook', [], ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('result.order', '10493')
            ->assertJsonPath('result.for_user', 'user_7')
            ->assertJsonPath('result.verified', true)
            ->assertJsonPath('result.scope_is_array', true)
            ->assertJsonPath('result.scope.tenant', 'company');
    }

    public function test_a_denying_guard_forbids_the_call(): void
    {
        AiAssistant::tool('refund_order')
            ->guard(fn (ToolContext $ctx): bool => false)->handle(fn (): string => 'should not run');

        $jwt = $this->sign([
            'jti' => (string) Str::uuid(),
            'tool' => 'refund_order',
            'arguments' => [],
            'principal' => ['sub' => 'user_7', 'verified' => true],
        ]);

        $this->postJson('/ai-assistant/hook', [], ['Authorization' => 'Bearer '.$jwt])
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');
    }

    public function test_a_foreign_signed_request_is_rejected(): void
    {
        AiAssistant::tool('lookup_order')->guard(fn (): bool => true)->handle(fn (): string => 'ok');

        // Signed with a different key than the published JWKS.
        $otherKeypair = sodium_crypto_sign_keypair();
        $forged = JWT::encode(
            ['aud' => 'localhost', 'exp' => time() + 120, 'kidless' => true, 'tool' => 'lookup_order'],
            base64_encode(sodium_crypto_sign_secretkey($otherKeypair)),
            'EdDSA',
            $this->kid, // claims the real kid, but the signature won't verify against it
        );

        $this->postJson('/ai-assistant/hook', [], ['Authorization' => 'Bearer '.$forged])
            ->assertStatus(401);
    }

    public function test_a_replayed_request_is_rejected(): void
    {
        AiAssistant::tool('lookup_order')->guard(fn (): bool => true)->handle(fn (): string => 'ok');

        $jwt = $this->sign([
            'jti' => 'fixed-jti-123',
            'tool' => 'lookup_order',
            'arguments' => [],
            'principal' => ['sub' => 'user_7', 'verified' => true],
        ]);

        $this->postJson('/ai-assistant/hook', [], ['Authorization' => 'Bearer '.$jwt])->assertOk();
        // Same jti again → replay.
        $this->postJson('/ai-assistant/hook', [], ['Authorization' => 'Bearer '.$jwt])->assertStatus(401);
    }

    public function test_a_token_for_another_audience_is_rejected(): void
    {
        AiAssistant::tool('lookup_order')->guard(fn (): bool => true)->handle(fn (): string => 'ok');

        $jwt = JWT::encode(
            ['aud' => 'someone-else.com', 'exp' => time() + 120, 'jti' => 'x', 'tool' => 'lookup_order'],
            $this->secretKeyB64, 'EdDSA', $this->kid,
        );

        $this->postJson('/ai-assistant/hook', [], ['Authorization' => 'Bearer '.$jwt])->assertStatus(401);
    }

    public function test_the_manifest_lists_registered_tools_for_a_verified_platform(): void
    {
        AiAssistant::tool('lookup_order')
            ->description('Look up an order')
            ->parameters(['order_number' => 'string', 'note' => 'string?']);

        $jwt = $this->sign(['tool' => 'discovery']);

        $this->getJson('/ai-assistant/manifest', ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('tools.0.name', 'lookup_order')
            ->assertJsonPath('tools.0.parameters.required', ['order_number']); // note is optional
    }

    public function test_the_manifest_refuses_an_unsigned_request(): void
    {
        $this->getJson('/ai-assistant/manifest')->assertStatus(401);
    }

    public function test_a_tool_defaults_to_the_sdk_bridge_kind(): void
    {
        AiAssistant::tool('lookup_order')->description('Look up an order');

        $jwt = $this->sign(['tool' => 'discovery']);

        $this->getJson('/ai-assistant/manifest', ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('tools.0.kind', 'sdk-bridge');
    }

    public function test_the_manifest_declares_kind_endpoint_and_exports(): void
    {
        AiAssistant::tool('fetch_weather')
            ->kind('api')
            ->endpoint('https://api.example.com/weather')
            ->parameters(['city' => 'string']);
        AiAssistant::procedure(['procedure_id' => 'refund_flow', 'name' => 'Refund flow', 'version' => 1, 'graph' => ['nodes' => []]]);
        AiAssistant::group(['key' => 'vip', 'label' => 'VIP customers']);

        $jwt = $this->sign(['tool' => 'discovery']);

        $this->getJson('/ai-assistant/manifest', ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('tools.0.kind', 'api')
            ->assertJsonPath('tools.0.endpoint', 'https://api.example.com/weather')
            ->assertJsonPath('procedures.0.procedure_id', 'refund_flow')
            ->assertJsonPath('groups.0.key', 'vip');
    }

    public function test_an_unknown_kind_falls_back_to_the_secure_bridge(): void
    {
        AiAssistant::tool('lookup_order')->kind('totally-made-up');

        $jwt = $this->sign(['tool' => 'discovery']);

        $this->getJson('/ai-assistant/manifest', ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('tools.0.kind', 'sdk-bridge');
    }

    public function test_a_configured_handshake_secret_gates_discovery(): void
    {
        config()->set('ai-assistant-connector.handshake_secret', 'test-connector-secret-at-least-32-bytes');
        AiAssistant::tool('lookup_order');

        // No cnt claim → refused even though the platform signature is valid.
        $this->getJson('/ai-assistant/manifest', ['Authorization' => 'Bearer '.$this->sign(['tool' => 'discovery', 'cnt' => null])])
            ->assertStatus(401);

        // Wrong cnt → refused.
        $this->getJson('/ai-assistant/manifest', ['Authorization' => 'Bearer '.$this->sign(['tool' => 'discovery', 'cnt' => 'wrong'])])
            ->assertStatus(401);

        // Matching cnt → allowed.
        $this->getJson('/ai-assistant/manifest', ['Authorization' => 'Bearer '.$this->sign(['tool' => 'discovery', 'cnt' => 'test-connector-secret-at-least-32-bytes'])])
            ->assertOk();
    }

    public function test_the_manifest_declares_inbox_cards_and_actions(): void
    {
        AiAssistant::inboxCard('billing_plan')
            ->title('Plan & usage')
            ->schema(['fields' => [['key' => 'plan', 'label' => 'Plan']]]);
        AiAssistant::inboxAction('retry_job')
            ->title('Retry job')
            ->label('Retry')
            ->confirm('Retry the last failed job for this customer?');

        $jwt = $this->sign(['tool' => 'discovery']);

        $this->getJson('/ai-assistant/manifest', ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('inbox_cards.0.key', 'billing_plan')
            ->assertJsonPath('inbox_cards.0.title', 'Plan & usage')
            ->assertJsonPath('inbox_cards.0.endpoint', 'http://localhost/ai-assistant/inbox/billing_plan')
            ->assertJsonPath('inbox_actions.0.key', 'retry_job')
            ->assertJsonPath('inbox_actions.0.label', 'Retry')
            ->assertJsonPath('inbox_actions.0.confirm', 'Retry the last failed job for this customer?');
    }

    public function test_a_signed_get_hydrates_an_inbox_card_with_the_principal(): void
    {
        AiAssistant::inboxCard('billing_plan')
            ->guard(fn (): bool => true)->handle(fn (array $args, ToolContext $ctx): array => [
                'plan' => 'Enterprise',
                'for_user' => $ctx->userId(),
                'account' => $ctx->accountId(),
            ]);

        $jwt = $this->sign([
            'jti' => (string) Str::uuid(),
            'purpose' => 'inbox_component',
            'principal' => ['sub' => 'user_7', 'account_id' => 'acme', 'verified' => true],
        ]);

        $this->getJson('/ai-assistant/inbox/billing_plan', ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('data.plan', 'Enterprise')
            ->assertJsonPath('data.for_user', 'user_7')
            ->assertJsonPath('data.account', 'acme');
    }

    public function test_a_signed_post_fires_an_inbox_action(): void
    {
        AiAssistant::inboxAction('retry_job')
            ->guard(fn (): bool => true)->handle(fn (array $args, ToolContext $ctx): array => [
                'retried' => $args['job_id'] ?? null,
                'by' => $ctx->userId(),
            ]);

        $jwt = $this->sign([
            'jti' => (string) Str::uuid(),
            'purpose' => 'inbox_component',
            'principal' => ['sub' => 'agent_1', 'verified' => true],
        ]);

        $this->postJson('/ai-assistant/inbox/retry_job', ['job_id' => 'job_42'], ['Authorization' => 'Bearer '.$jwt])
            ->assertOk()
            ->assertJsonPath('result.retried', 'job_42')
            ->assertJsonPath('result.by', 'agent_1');
    }

    public function test_a_card_cannot_be_fired_as_an_action_and_vice_versa(): void
    {
        AiAssistant::inboxCard('billing_plan')->guard(fn (): bool => true)->handle(fn (): array => ['plan' => 'x']);
        AiAssistant::inboxAction('retry_job')->guard(fn (): bool => true)->handle(fn (): array => ['ok' => true]);

        // POST to a card (write to a read) → 405.
        $this->postJson('/ai-assistant/inbox/billing_plan', [], ['Authorization' => 'Bearer '.$this->sign(['jti' => (string) Str::uuid(), 'principal' => ['sub' => 'u', 'verified' => true]])])
            ->assertStatus(405);

        // GET to an action (read a write) → 405.
        $this->getJson('/ai-assistant/inbox/retry_job', ['Authorization' => 'Bearer '.$this->sign(['jti' => (string) Str::uuid(), 'principal' => ['sub' => 'u', 'verified' => true]])])
            ->assertStatus(405);
    }

    public function test_an_inbox_component_guard_can_forbid_the_call(): void
    {
        AiAssistant::inboxAction('retry_job')
            ->guard(fn (ToolContext $ctx): bool => false)->handle(fn (): string => 'should not run');

        $this->postJson('/ai-assistant/inbox/retry_job', [], ['Authorization' => 'Bearer '.$this->sign(['jti' => (string) Str::uuid(), 'principal' => ['sub' => 'u', 'verified' => true]])])
            ->assertStatus(403);
    }

    public function test_an_unsigned_inbox_request_is_rejected(): void
    {
        AiAssistant::inboxCard('billing_plan')->guard(fn (): bool => true)->handle(fn (): array => ['plan' => 'x']);

        $this->getJson('/ai-assistant/inbox/billing_plan')->assertStatus(401);
    }
}
