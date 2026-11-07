<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Security;

use Cbox\AiAssistant\Connector\Support\Configuration;
use Cbox\AiAssistant\Connector\Support\Payload;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifies that an incoming request is a genuine, fresh call from the assistant platform.
 *
 *   1. the Bearer is an EdDSA JWT signed by the platform (verified against its JWKS),
 *   2. its `aud` is us (a token minted for another host is refused),
 *   3. it isn't expired (with a little leeway),
 *   4. its `jti` hasn't been seen (replay protection).
 *
 * Signing uses the platform public key; a connector handshake binds requests to its workspace.
 */
class RequestVerifier
{
    private const LEEWAY = 60;

    /**
     * @return array<string, mixed> the verified claims
     *
     * @throws VerificationException
     */
    public function verify(string $bearer, string $requestHost, bool $requireReplayId = true): array
    {
        $jwt = trim(preg_replace('/^Bearer\s+/i', '', $bearer) ?? '');

        if ($jwt === '') {
            throw new VerificationException('Missing signature.');
        }

        $kid = $this->kid($jwt);
        try {
            $publicKey = $this->publicKeyForKid($kid);
        } catch (Throwable $e) {
            throw new VerificationException('Platform signing keys are unavailable.', previous: $e);
        }

        $previousLeeway = JWT::$leeway;
        JWT::$leeway = self::LEEWAY;

        try {
            // Firebase returns nested stdClass objects. The SDK contract is associative
            // arrays, including principal.context and structured tool arguments.
            $verified = JWT::decode($jwt, new Key($publicKey, 'EdDSA'));
            $claims = Payload::object(json_decode(json_encode($verified, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
            $issuedAt = $claims['iat'] ?? null;
            $expiresAt = $claims['exp'] ?? null;
            if (! is_int($issuedAt) || ! is_int($expiresAt) || $expiresAt <= $issuedAt || $expiresAt - $issuedAt > 300) {
                throw new VerificationException('Tokens require an issued time and a maximum five-minute lifetime.');
            }
            Payload::object($claims['principal'] ?? []);
            Payload::object($claims['arguments'] ?? []);
        } catch (Throwable $e) {
            throw new VerificationException('Signature verification failed: '.$e->getMessage());
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        $expectedAudience = config('ai-assistant-connector.audience') ?: $requestHost;

        if (($claims['aud'] ?? null) !== $expectedAudience) {
            throw new VerificationException('Token audience mismatch.');
        }

        $this->guardHandshake($claims);
        $this->guardReplay($claims, $requireReplayId);

        return $claims;
    }

    /**
     * The discovery/hook token must carry the
     * matching `cnt` (connector-token) claim. The platform signature already proves the
     * request is genuine; this second factor binds it to the ONE registered connector, so a
     * different legitimate tenant hitting the same endpoint can't enumerate our tools.
     * Missing configuration fails closed; backend-driven mode needs no handshake.
     *
     * @param  array<string, mixed>  $claims
     */
    private function guardHandshake(array $claims): void
    {
        $expected = config('ai-assistant-connector.handshake_secret');

        if (! is_string($expected) || strlen($expected) < 32) {
            throw new VerificationException('Configure a connector handshake secret of at least 32 bytes.');
        }

        $presented = is_string($claims['cnt'] ?? null) ? $claims['cnt'] : '';

        if (! hash_equals($expected, $presented)) {
            throw new VerificationException('Connector handshake mismatch.');
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function guardReplay(array $claims, bool $required): void
    {
        $jti = $claims['jti'] ?? null;

        if (! is_string($jti) || $jti === '') {
            if ($required) {
                throw new VerificationException('A single-use request id is required.');
            }

            return; // Read-only discovery may omit jti.
        }

        $key = 'ai-connector:jti:'.sha1($jti);
        $expiresAt = $claims['exp'] ?? null;
        $lifetime = is_int($expiresAt) ? max(1, $expiresAt - time() + self::LEEWAY) : 360;
        $ttl = max($lifetime, Configuration::positiveInteger(app(Repository::class), 'ai-assistant-connector.replay_ttl', 300));

        if (! Cache::add($key, true, $ttl)) {
            throw new VerificationException('Replayed request rejected.');
        }
    }

    private function kid(string $jwt): string
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw new VerificationException('Malformed token.');
        }

        $header = json_decode($this->base64UrlDecode($parts[0]), true);
        $kid = is_array($header) ? ($header['kid'] ?? null) : null;

        if (! is_string($kid) || $kid === '') {
            throw new VerificationException('Token is missing a key id.');
        }

        return $kid;
    }

    /**
     * Fetch the platform JWKS (cached) and return the Ed25519 public key for `kid`, as the
     * base64 firebase/php-jwt expects for EdDSA. Refreshes once on a cache miss so key
     * rotation is picked up without a restart.
     */
    private function publicKeyForKid(string $kid): string
    {
        $key = $this->lookupKey($kid, false);

        return $key ?? $this->lookupKey($kid, true)
            ?? throw new VerificationException('No platform key matches this token.');
    }

    private function lookupKey(string $kid, bool $fresh): ?string
    {
        $jwks = $this->jwks($fresh);

        foreach (Payload::objects($jwks['keys'] ?? []) as $jwk) {
            if (($jwk['kid'] ?? null) === $kid && ($jwk['kty'] ?? null) === 'OKP' && ($jwk['crv'] ?? null) === 'Ed25519') {
                $x = $jwk['x'] ?? null;
                if (! is_string($x)) {
                    continue;
                }
                $raw = $this->base64UrlDecode($x);

                if (strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                    return base64_encode($raw);
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function jwks(bool $fresh): array
    {
        $url = Configuration::string(app(Repository::class), 'ai-assistant-connector.jwks_url');
        $cacheKey = 'ai-connector:jwks:'.hash('sha256', $url);

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Payload::object(Cache::remember($cacheKey, Configuration::positiveInteger(app(Repository::class), 'ai-assistant-connector.jwks_cache_ttl', 3600), function () use ($url): array {
            $response = Http::timeout(8)->get($url);

            return $response->successful() ? Payload::object($response->json()) : ['keys' => []];
        }));
    }

    private function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }
}
