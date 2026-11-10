<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Knowledge;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/** Run on deployment. Only the service's explicit customer-safe manifest is published. */
class SyncSupportKnowledge extends Command
{
    protected $signature = 'assistant:sync-support';

    protected $description = 'Synchronize the service support documentation with Cbox Assistant';

    public function handle(KnowledgeClient $client): int
    {
        if (config('ai-assistant-connector.support.enabled') !== true) {
            $this->components->info('Support is disabled; no documents were published.');

            return self::SUCCESS;
        }

        $path = resource_path('assistant/manifest.php');
        if (! is_file($path)) {
            $this->components->error('The service support manifest is missing.');

            return self::FAILURE;
        }
        $documents = require $path;
        $assistant = config('ai-assistant-connector.support.assistant');
        $product = config('ai-assistant-connector.support.product');
        if (! is_array($documents) || $documents === [] || ! is_string($assistant) || $assistant === '' || ! is_string($product) || $product === '') {
            $this->components->error('Configure the support assistant, product and approved documents.');

            return self::FAILURE;
        }
        $index = 0;
        foreach ($documents as $key => $document) {
            $index++;
            if (! is_string($key) || ! is_array($document) || ! is_string($document['file'] ?? null)
                || ! is_string($document['title'] ?? null) || ! is_string($document['path'] ?? null)
                || ! is_int($document['revision'] ?? null) || ! is_string($document['locale'] ?? 'en')) {
                throw new \LogicException('Invalid support document manifest.');
            }
            $root = realpath(resource_path('assistant'));
            $file = realpath(resource_path('assistant/'.$document['file']));
            if ($root === false || $file === false || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR)) {
                throw new \LogicException('Support documents must be inside resources/assistant.');
            }
            $client->put($assistant, $product, $key, $document['title'], (string) file_get_contents($file),
                rtrim(Config::string('app.url'), '/').'/'.ltrim($document['path'], '/'), $document['revision'], $document['locale'] ?? 'en',
                self::topics($document['topics'] ?? [], $index));
            $this->components->info($key.' indexed at revision '.$document['revision'].'.');
        }

        return self::SUCCESS;
    }

    /**
     * A document's page help topics. A list (`['invoices.index']`) ranks the document by its place in
     * the manifest; a map (`['invoices.index' => 1]`) sets the position per topic.
     *
     * @return array<string, int>
     */
    public static function topics(mixed $topics, int $manifestPosition): array
    {
        if (! is_array($topics)) {
            throw new \LogicException('Support document topics must be a list or a map of topic => position.');
        }
        $result = [];
        foreach ($topics as $key => $value) {
            [$topic, $position] = is_int($key) ? [$value, $manifestPosition] : [$key, $value];
            if (! is_string($topic) || preg_match('/^[a-z0-9][a-z0-9._:-]{0,119}$/D', $topic) !== 1 || ! is_int($position) || $position < 1 || $position > 1000) {
                throw new \LogicException('Invalid support document topic.');
            }
            $result[$topic] = $position;
        }
        if (count($result) > 20) {
            throw new \LogicException('A support document can answer at most 20 topics.');
        }

        return $result;
    }
}
