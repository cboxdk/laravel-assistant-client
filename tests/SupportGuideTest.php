<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Tests;

use Cbox\AiAssistant\Connector\Support\SupportSession;

final class SupportGuideTest extends TestCase
{
    public function test_only_manifest_documents_are_public_and_raw_html_is_removed(): void
    {
        $root = sys_get_temp_dir().'/assistant-guides-'.bin2hex(random_bytes(8));
        mkdir($root.'/resources/assistant', 0700, true);
        $this->app->setBasePath($root);
        $root .= '/resources';
        file_put_contents($root.'/assistant/manifest.php', '<?php return '.var_export([
            'start' => ['file' => 'start.md', 'title' => 'Getting started', 'locale' => 'en'],
            'escape' => ['file' => '../private.md', 'title' => 'Private'],
        ], true).';');
        file_put_contents($root.'/assistant/start.md', '# Getting started'."\n\n".'<script>unsafe()</script>');
        file_put_contents($root.'/private.md', 'PRIVATE');
        try {
            $this->get('/ai-assistant/guides/start')->assertOk()->assertSee('Getting started')->assertDontSee('<script>', false);
            $this->get('/ai-assistant/guides/escape')->assertNotFound();
            $this->get('/ai-assistant/guides/unknown')->assertNotFound();
            $this->get('/ai-assistant/support.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');
        } finally {
            unlink($root.'/assistant/manifest.php');
            unlink($root.'/assistant/start.md');
            unlink($root.'/private.md');
            rmdir($root.'/assistant');
            rmdir($root);
            rmdir(dirname($root));
        }
    }

    public function test_frame_origin_does_not_include_paths_or_broaden_the_allowlist(): void
    {
        config(['ai-assistant-connector.platform.url' => 'https://assistant.example:8443/path']);
        self::assertSame('https://assistant.example:8443', app(SupportSession::class)->frameOrigin());
        config(['ai-assistant-connector.platform.url' => 'https://user:secret@assistant.example']);
        $this->expectException(\LogicException::class);
        app(SupportSession::class)->frameOrigin();
    }
}
