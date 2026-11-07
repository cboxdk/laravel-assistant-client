<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Support;

use Firebase\JWT\JWT;
use Illuminate\Contracts\Config\Repository;
use LogicException;

/** The host authenticates/authorizes first. Only a short-lived widget assertion reaches its UI. */
readonly class SupportSession
{
    public function __construct(private Repository $config) {}

    /** Exact origin for the host's frame-src allowlist; never permits a wildcard. */
    public function frameOrigin(): string
    {
        $url = $this->config->get('ai-assistant-connector.platform.url');
        $parts = is_string($url) ? parse_url($url) : false;
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || ($parts['scheme'] !== 'https' && ! ($parts['scheme'] === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1'], true)))) {
            throw new LogicException('Support requires an HTTPS platform URL.');
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * @param  array<string, string>  $context  Service-owned tenant/project/environment references.
     * @return array{url: string, identity: string, expires_at: int}
     */
    public function forUser(string $subject, string $account, string $service, array $context = [], ?string $name = null, ?string $email = null): array
    {
        $widget = $this->config->get('ai-assistant-connector.support.widget');
        $secret = $this->config->get('ai-assistant-connector.handshake_secret');
        $url = $this->frameOrigin();
        if ($this->config->get('ai-assistant-connector.support.enabled') !== true
            || ! is_string($widget) || $widget === '' || ! is_string($secret) || strlen($secret) < 32
            || $subject === '' || $account === '' || $service === '') {
            throw new LogicException('Support is not configured for this service.');
        }

        $expires = time() + 180;
        $claims = [
            'aud' => 'widget-identity', 'iat' => time(), 'exp' => $expires,
            'widget' => $widget, 'sub' => $subject, 'account_id' => $account,
            'identity_namespace' => $this->config->get('ai-assistant-connector.support.identity_namespace'), 'name' => $name, 'email' => $email,
            'service_context' => ['service' => $service, ...array_intersect_key($context, array_flip(['tenant', 'project', 'environment']))],
        ];

        return ['url' => $url.'/embed/'.rawurlencode($widget), 'identity' => JWT::encode($claims, $secret, 'HS256'), 'expires_at' => $expires];
    }
}
