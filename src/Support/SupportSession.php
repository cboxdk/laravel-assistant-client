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
        return $this->origin($this->config->get('ai-assistant-connector.platform.url'));
    }

    /**
     * The Assistant public API base (no trailing slash). Defaults to `<platform origin>/api/v1`;
     * `ai-assistant-connector.api_url` moves it to the dedicated API host.
     */
    public function apiUrl(): string
    {
        $configured = $this->config->get('ai-assistant-connector.api_url');
        if (is_string($configured) && $configured !== '') {
            $origin = $this->origin($configured);
            $path = parse_url($configured, PHP_URL_PATH);

            return $origin.rtrim(is_string($path) ? $path : '', '/');
        }

        return $this->frameOrigin().'/api/v1';
    }

    /**
     * The support session for one signed-in person. Besides the chat frame URL and the identity
     * assertion, it carries what the shell's Help column loads in the browser after paint, all
     * authenticated with the same `identity` as a bearer token:
     *  - `search_url`: GET ?q=&service=&topic= for docs, help centre and knowledge base;
     *  - `topics_url`: GET with `{topic}` replaced, the articles for a page's help topic;
     *  - `article_url`: GET with `{id}` replaced, one article with its body for the Help column;
     *  - `updates_url`: GET the person's support updates; POST `{updates_url}/{id}/read`;
     *  - `portal_url`: the configured support portal, or null. The live value (null while the portal
     *    is offline) comes later from the Assistant chat session; the shell must prefer that one.
     *
     * @param  array<string, string>  $context  Service-owned tenant/project/environment references.
     * @return array{url: string, identity: string, expires_at: int, api_url: string, search_url: string, topics_url: string, article_url: string, updates_url: string, portal_url: string|null}
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
        $api = $this->apiUrl();

        return [
            'url' => $url.'/embed/'.rawurlencode($widget),
            'identity' => JWT::encode($claims, $secret, 'HS256'),
            'expires_at' => $expires,
            'api_url' => $api,
            'search_url' => $api.'/help/search',
            'topics_url' => $api.'/help/topics/{topic}/articles',
            'article_url' => $api.'/help/articles/{id}',
            'updates_url' => $api.'/support/updates',
            'portal_url' => $this->portalUrl(),
        ];
    }

    private function portalUrl(): ?string
    {
        $url = $this->config->get('ai-assistant-connector.support.portal_url');

        return is_string($url) && str_starts_with($url, 'https://') ? rtrim($url, '/') : null;
    }

    private function origin(mixed $url): string
    {
        $parts = is_string($url) ? parse_url($url) : false;
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || ($parts['scheme'] !== 'https' && ! ($parts['scheme'] === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1'], true)))) {
            throw new LogicException('Support requires an HTTPS platform URL.');
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
