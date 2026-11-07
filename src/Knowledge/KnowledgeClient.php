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

    public function put(string $assistant, string $product, string $key, string $title, string $text, string $url, int $revision, string $locale = 'en'): void
    {
        $response = Http::withToken(Configuration::string($this->config, 'ai-assistant-connector.api_key'))
            ->acceptJson()->timeout(120)
            ->put(rtrim(Configuration::string($this->config, 'ai-assistant-connector.platform.url'), '/').'/api/v1/assistants/'.rawurlencode($assistant).'/knowledge/'.rawurlencode($key), [
                'product_id' => $product, 'title' => $title, 'content' => $text, 'url' => $url,
                'revision' => $revision, 'locale' => $locale,
            ]);

        if ($response->failed() || $response->json('data.status') !== 'indexed') {
            throw new EmbeddedException('Knowledge sync failed with status '.$response->status().'.');
        }
    }
}
