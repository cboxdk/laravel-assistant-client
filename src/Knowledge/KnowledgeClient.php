<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Knowledge;

use Cbox\AiAssistant\Connector\Embedded\EmbeddedException;
use Cbox\AiAssistant\Connector\Support\Configuration;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

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
            ->acceptJson()->connectTimeout(10)->timeout(120)
            // Stable document keys and revisions make this PUT safe to repeat after a lost response.
            ->retry(3, function (int $attempt, mixed $exception) use ($key): int {
                $delay = $attempt === 1 ? 10000 : 30000;
                Log::warning('Retrying support knowledge sync after a transient failure.', [
                    'document' => $key,
                    'next_attempt' => $attempt + 1,
                    'delay_ms' => $delay,
                    'status' => $exception instanceof RequestException ? $exception->response->status() : null,
                ]);

                return $delay;
            }, when: fn (?Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && ($exception->response->serverError()
                    || in_array($exception->response->status(), [408, 429], true))), throw: false)
            ->put(rtrim(Configuration::string($this->config, 'ai-assistant-connector.platform.url'), '/').'/api/v1/assistants/'.rawurlencode($assistant).'/knowledge/'.rawurlencode($key), $body);

        if ($response->failed() || $response->json('data.status') !== 'indexed') {
            throw new EmbeddedException('Knowledge sync failed with status '.$response->status().'.');
        }
    }
}
