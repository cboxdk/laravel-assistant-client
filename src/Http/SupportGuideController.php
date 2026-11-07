<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Http;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/** Public citations expose only the service's reviewed knowledge manifest. */
class SupportGuideController
{
    public function __invoke(string $key): View
    {
        $manifest = resource_path('assistant/manifest.php');
        abort_unless(is_file($manifest), 404);
        $documents = require $manifest;
        $document = is_array($documents) ? ($documents[$key] ?? null) : null;
        abort_unless(is_array($document) && is_string($document['file'] ?? null), 404);
        $root = realpath(resource_path('assistant'));
        $file = realpath(resource_path('assistant/'.$document['file']));
        abort_unless($root !== false && $file !== false && str_starts_with($file, $root.DIRECTORY_SEPARATOR), 404);

        return view('assistant-connector::guide', [
            'title' => $document['title'], 'locale' => $document['locale'] ?? 'en',
            'html' => Str::markdown((string) file_get_contents($file), ['html_input' => 'strip', 'allow_unsafe_links' => false]),
        ]);
    }
}
