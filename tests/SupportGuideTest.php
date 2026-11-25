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

    public function test_the_guide_page_answers_in_the_readers_language_where_the_guide_is_translated(): void
    {
        $root = sys_get_temp_dir().'/assistant-guides-'.bin2hex(random_bytes(8));
        mkdir($root.'/resources/assistant/da', 0700, true);
        $this->app->setBasePath($root);
        $assistant = $root.'/resources/assistant';
        file_put_contents($assistant.'/manifest.php', '<?php return '.var_export([
            'refunds' => ['file' => 'refunds.md', 'title' => 'Refund an invoice', 'path' => '/ai-assistant/guides/refunds', 'locale' => 'en'],
            'plans' => ['file' => 'plans.md', 'title' => 'Plans', 'path' => 'ai-assistant/guides/plans', 'locale' => 'en'],
            'refunds-da' => ['file' => 'da/refunds.md', 'title' => 'Refundér en faktura', 'path' => 'ai-assistant/guides/refunds', 'locale' => 'da'],
            // A translation whose text is outside the reviewed directory is never shown.
            'plans-da' => ['file' => '../private.md', 'title' => 'Planer', 'path' => 'ai-assistant/guides/plans', 'locale' => 'da'],
        ], true).';');
        file_put_contents($assistant.'/refunds.md', 'English refund text');
        file_put_contents($assistant.'/plans.md', 'English plans text');
        file_put_contents($assistant.'/da/refunds.md', 'Dansk tekst om refundering');
        file_put_contents($root.'/resources/private.md', 'PRIVATE');
        $page = fn (string $key, ?string $accept) => $this->get('/ai-assistant/guides/'.$key, $accept === null ? [] : ['Accept-Language' => $accept]);
        try {
            $page('refunds', 'da-DK,da;q=0.9,en;q=0.8')->assertOk()->assertSee('Dansk tekst om refundering')
                ->assertSee('<html lang="da">', false)->assertSee('Refundér en faktura')->assertHeader('Vary', 'Accept-Language');
            // The browser's order decides: Danish further down the list does not beat English.
            $page('refunds', 'en-US,en;q=0.9,da;q=0.8')->assertSee('English refund text')->assertSee('<html lang="en">', false);
            $page('refunds', 'sv,de;q=0.9')->assertSee('English refund text');
            $page('refunds', 'sv,da;q=0.9,en;q=0.8')->assertSee('Dansk tekst om refundering');
            $page('refunds', null)->assertSee('English refund text');
            $page('plans', 'da')->assertOk()->assertSee('English plans text')->assertDontSee('PRIVATE');
            // A translation asked for by its own key is shown as it is.
            $page('refunds-da', 'en')->assertOk()->assertSee('Dansk tekst om refundering');
        } finally {
            foreach (['/assistant/manifest.php', '/assistant/refunds.md', '/assistant/plans.md', '/assistant/da/refunds.md', '/private.md'] as $file) {
                unlink($root.'/resources'.$file);
            }
            rmdir($assistant.'/da');
            rmdir($assistant);
            rmdir($root.'/resources');
            rmdir($root);
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
