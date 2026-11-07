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
        foreach ($documents as $key => $document) {
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
                rtrim(Config::string('app.url'), '/').'/'.ltrim($document['path'], '/'), $document['revision'], $document['locale'] ?? 'en');
            $this->components->info($key.' indexed at revision '.$document['revision'].'.');
        }

        return self::SUCCESS;
    }
}
