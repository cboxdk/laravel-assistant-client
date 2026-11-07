<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Testing;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Real Ed25519 assertions and a fake JWKS transport for host authorization tests. */
trait InteractsWithAssistant
{
    protected string $secretKeyB64;

    protected string $kid;

    protected function fakeAssistantSigning(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($keypair);
        $this->secretKeyB64 = base64_encode(sodium_crypto_sign_secretkey($keypair));
        $this->kid = substr(hash('sha256', $public), 0, 16);
        config()->set('ai-assistant-connector.handshake_secret', 'test-connector-secret-at-least-32-bytes');
        config()->set('ai-assistant-connector.jwks_url', 'https://platform.test/.well-known/jwks.json');
        config()->set('ai-assistant-connector.audience', 'localhost');
        Http::fake([
            'platform.test/*' => Http::response(['keys' => [[
                'kty' => 'OKP', 'crv' => 'Ed25519', 'alg' => 'EdDSA', 'use' => 'sig',
                'kid' => $this->kid,
                'x' => rtrim(strtr(base64_encode($public), '+/', '-_'), '='),
            ]]], 200),
        ]);
    }

    /** @param array<string, mixed> $claims */
    protected function assistantSignature(array $claims): string
    {
        return JWT::encode([
            'aud' => 'localhost', 'iat' => time(), 'exp' => time() + 120,
            'jti' => (string) Str::uuid(),
            'cnt' => config('ai-assistant-connector.handshake_secret'),
            ...$claims,
        ], $this->secretKeyB64, 'EdDSA', $this->kid);
    }
}
