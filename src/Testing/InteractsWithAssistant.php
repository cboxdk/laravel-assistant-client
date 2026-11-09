<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Testing;

use Firebase\JWT\JWT;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Real Ed25519 assertions and a fake JWKS transport for host authorization tests.
 *
 * The profile helpers send HTTP requests, so the class using them must be a Laravel test case
 * (it provides `getJson`).
 */
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

    /**
     * Fetch the signed customer profile as `$principal`, exactly as Assistant would. Pass
     * `['resources' => 'sites_domains', 'filter' => ['q' => 'shop']]` as `$query` to narrow it,
     * and {@see assistantSupportAccess()} as `$supportAccess` to send a staff elevation.
     *
     * @param  array<string, mixed>  $principal  e.g. `['sub' => 'user_7', 'account_id' => 'acme', 'verified' => true]`
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $supportAccess
     * @return TestResponse<Response>
     */
    protected function getAssistantProfile(array $principal, array $query = [], ?array $supportAccess = null): TestResponse
    {
        if ($supportAccess !== null) {
            $principal['support_access'] = $supportAccess;
        }

        $prefix = trim(Config::string('ai-assistant-connector.route_prefix', 'ai-assistant'), '/');
        $uri = '/'.$prefix.'/profile'.($query === [] ? '' : '?'.http_build_query($query));

        return $this->getJson($uri, [
            'Authorization' => 'Bearer '.$this->assistantSignature(['purpose' => 'profile', 'principal' => $principal]),
        ]);
    }

    /**
     * A support elevation claim for {@see getAssistantProfile()} or a signed hook principal's
     * `support_access`. Defaults to a valid thirty-minute elevation; pass an empty reason or a
     * negative or too-large `$minutes` to test the rejections.
     *
     * @return array{staff_id: string, staff_name: string, reason: string, until: string}
     */
    protected function assistantSupportAccess(
        string $reason = 'Customer asked us to check why their domain is not resolving',
        int $minutes = 30,
        string $staffId = 'staff_1',
        string $staffName = 'Support Agent',
    ): array {
        return [
            'staff_id' => $staffId,
            'staff_name' => $staffName,
            'reason' => $reason,
            'until' => Carbon::now()->addMinutes($minutes)->toIso8601String(),
        ];
    }
}
