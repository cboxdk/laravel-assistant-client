<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Knowledge;

use Cbox\AiAssistant\Connector\Embedded\EmbeddedException;
use Cbox\AiAssistant\Connector\Support\Configuration;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Http;

/** Push a curated document from the deployed service, without granting repository access. */
readonly class KnowledgeClient
{
    public function __construct(private Repository $config) {}

    /**
     * @param  array<string, int>  $topics  page help topic => position (lower first), shown under
     *                                      "For this page" in the shell's Help column
     */
    public function put(string $assistant, string $product, string $key, string $title, string $text, string $url, int $revision, string $locale = 'en', array $topics = []): void
    {
        $body = [
            'product_id' => $product, 'title' => $title, 'content' => $text, 'url' => $url,
            'revision' => $revision, 'locale' => $locale,
        ];
        if ($topics !== []) {
            $body['topics'] = array_map(fn (string $topic, int $position): array => ['topic' => $topic, 'position' => $position], array_keys($topics), $topics);
        }
        $response = Http::withToken(Configuration::string($this->config, 'ai-assistant-connector.api_key'))
            ->acceptJson()->timeout(120)
            ->put(rtrim(Configuration::string($this->config, 'ai-assistant-connector.platform.url'), '/').'/api/v1/assistants/'.rawurlencode($assistant).'/knowledge/'.rawurlencode($key), $body);

        if ($response->failed() || $response->json('data.status') !== 'indexed') {
            throw new EmbeddedException('Knowledge sync failed with status '.$response->status().'.');
        }
    }
}
