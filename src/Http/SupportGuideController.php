<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Public citations expose only the service's reviewed knowledge manifest.
 *
 * Manifest documents that share a `path` and differ in `locale` are versions of one page. When
 * the page asked for is that shared address, the reader gets the version in their language; a
 * document asked for by its own key at another address is shown as it is.
 */
class SupportGuideController
{
    public function __invoke(Request $request, string $key): Response
    {
        $manifest = resource_path('assistant/manifest.php');
        abort_unless(is_file($manifest), 404);
        $documents = require $manifest;
        $document = is_array($documents) ? ($documents[$key] ?? null) : null;
        abort_unless(is_array($document) && is_string($document['file'] ?? null), 404);
        $file = self::file($document);
        abort_unless($file !== null, 404);

        foreach (self::versions($documents, $document, $request) as $version) {
            if (($versionFile = self::file($version)) !== null) {
                [$document, $file] = [$version, $versionFile];
                break;
            }
        }

        return response()->view('assistant-connector::guide', [
            'title' => $document['title'], 'locale' => $document['locale'] ?? 'en',
            'html' => Str::markdown((string) file_get_contents($file), ['html_input' => 'strip', 'allow_unsafe_links' => false]),
        ])->header('Vary', 'Accept-Language');
    }

    /**
     * The other versions of the page the reader would rather have, best first. Empty when the
     * document asked for is already the one to show.
     *
     * @param  array<array-key, mixed>  $documents
     * @param  array<array-key, mixed>  $document
     * @return list<array<array-key, mixed>>
     */
    private static function versions(array $documents, array $document, Request $request): array
    {
        $path = is_string($document['path'] ?? null) ? trim($document['path'], '/') : null;
        if ($path === null || $path !== trim($request->path(), '/')) {
            return [];
        }
        $byLanguage = [];
        foreach ($documents as $candidate) {
            if (is_array($candidate) && is_string($candidate['file'] ?? null) && is_string($candidate['title'] ?? null)
                && is_string($candidate['path'] ?? null) && trim($candidate['path'], '/') === $path) {
                $language = self::language($candidate['locale'] ?? 'en');
                if ($language !== null) {
                    $byLanguage[$language] ??= $candidate;
                }
            }
        }
        $own = self::language($document['locale'] ?? 'en');
        $versions = [];
        foreach (self::readerLanguages($request) as $language) {
            if ($language === $own) {
                break;
            }
            if (isset($byLanguage[$language])) {
                $versions[] = $byLanguage[$language];
            }
        }

        return $versions;
    }

    /**
     * The languages the reader reads, most wanted first, as the browser sends them. The page is
     * public and stateless, so `Accept-Language` is the one thing known about its reader.
     *
     * @return list<string>
     */
    private static function readerLanguages(Request $request): array
    {
        return array_values(array_unique(array_filter(array_map(self::language(...), $request->getLanguages()))));
    }

    /** The language part of a tag, as Assistant compares versions: `da-DK` is `da`, Norwegian is Bokmål. */
    private static function language(mixed $tag): ?string
    {
        if (! is_string($tag) || preg_match('/^([A-Za-z]{2,3})(?:[-_][A-Za-z0-9]{1,8})*$/D', trim($tag), $parts) !== 1) {
            return null;
        }
        $language = strtolower($parts[1]);

        return $language === 'no' ? 'nb' : $language;
    }

    /**
     * Where a manifest document's text is, or null when it is not a file inside resources/assistant.
     *
     * @param  array<array-key, mixed>  $document
     */
    private static function file(array $document): ?string
    {
        $root = realpath(resource_path('assistant'));
        $file = is_string($document['file'] ?? null) ? realpath(resource_path('assistant/'.$document['file'])) : false;

        return $root !== false && $file !== false && str_starts_with($file, $root.DIRECTORY_SEPARATOR) ? $file : null;
    }
}
