<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Tests;

use Cbox\AiAssistant\Connector\Facades\AiAssistant;
use Firebase\JWT\JWT;

class SecurityBoundaryTest extends TestCase
{
    public function test_missing_guards_deny_tools_and_inbox_actions(): void
    {
        AiAssistant::tool('unconfigured')->handle(fn () => $this->fail('Unguarded tool ran.'));
        AiAssistant::inboxAction('unconfigured')->handle(fn () => $this->fail('Unguarded action ran.'));
        $this->postJson('/ai-assistant/hook', [], ['Authorization' => 'Bearer '.$this->sign(['tool' => 'unconfigured'])])->assertForbidden();
        $this->postJson('/ai-assistant/inbox/unconfigured', [], ['Authorization' => 'Bearer '.$this->sign([])])->assertForbidden();
    }

    public function test_malformed_or_unbounded_claims_fail_before_running_a_tool(): void
    {
        AiAssistant::tool('protected')->guard(fn () => true)->handle(fn () => $this->fail('Invalid claims reached the handler.'));
        $previous = JWT::$leeway;
        foreach ([['exp' => null], ['iat' => null], ['exp' => time() + 600], ['jti' => null], ['principal' => 'user'], ['arguments' => [1, 2]]] as $invalid) {
            $this->postJson('/ai-assistant/hook', [], ['Authorization' => 'Bearer '.$this->sign(['tool' => 'protected', ...$invalid])])->assertUnauthorized();
            $this->assertSame($previous, JWT::$leeway);
        }
    }

    public function test_discovery_requires_its_configured_connector_but_no_replay_id(): void
    {
        $this->getJson('/ai-assistant/manifest', ['Authorization' => 'Bearer '.$this->sign(['jti' => null])])->assertOk();
        config()->set('ai-assistant-connector.handshake_secret', null);
        $this->getJson('/ai-assistant/manifest', ['Authorization' => 'Bearer '.$this->sign([])])->assertUnauthorized();
    }
}
