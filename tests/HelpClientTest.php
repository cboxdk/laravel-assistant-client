<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Tests;

use Cbox\AiAssistant\Connector\Facades\AiAssistant;
use Cbox\AiAssistant\Connector\Help\HelpArticle;
use Cbox\AiAssistant\Connector\Help\HelpClient;
use Cbox\AiAssistant\Connector\Help\HelpException;
use Cbox\AiAssistant\Connector\Help\HttpHelpClient;
use Cbox\AiAssistant\Connector\Help\SupportUpdate;
use Cbox\AiAssistant\Connector\Help\SupportUser;
use Cbox\AiAssistant\Connector\Knowledge\KnowledgeClient;
use Cbox\AiAssistant\Connector\Knowledge\SyncSupportKnowledge;
use Cbox\AiAssistant\Connector\Support\SupportSession;
use DateTimeImmutable;
use DateTimeZone;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LogicException;

final class HelpClientTest extends TestCase
{
    private const SECRET = 'service-secret-0123456789abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai-assistant-connector.support.enabled' => true,
            'ai-assistant-connector.support.widget' => 'widget-token',
            'ai-assistant-connector.support.identity_namespace' => 'cbox-production',
            'ai-assistant-connector.platform.url' => 'https://assistant.test',
            'ai-assistant-connector.handshake_secret' => self::SECRET,
        ]);
    }

    public function test_session_payload_exposes_the_help_urls_and_portal_for_the_shell(): void
    {
        $session = app(SupportSession::class)->forUser('person', 'org', 'billing');
        self::assertSame('https://assistant.test/api/v1', $session['api_url']);
        self::assertSame('https://assistant.test/api/v1/help/search', $session['search_url']);
        self::assertSame('https://assistant.test/api/v1/help/topics/{topic}/articles', $session['topics_url']);
        self::assertSame('https://assistant.test/api/v1/support/updates', $session['updates_url']);
        self::assertNull($session['portal_url']);

        config(['ai-assistant-connector.support.portal_url' => 'https://support.cbox.dk/', 'ai-assistant-connector.api_url' => 'https://api.cboxassistant.com/v1/']);
        $session = app(SupportSession::class)->forUser('person', 'org', 'billing');
        self::assertSame('https://support.cbox.dk', $session['portal_url']);
        self::assertSame('https://api.cboxassistant.com/v1/support/updates', $session['updates_url']);

        config(['ai-assistant-connector.support.portal_url' => 'http://insecure.test']);
        self::assertNull(app(SupportSession::class)->forUser('person', 'org', 'billing')['portal_url']);
    }

    public function test_help_search_sends_a_signed_identity_and_maps_the_grouped_response(): void
    {
        Http::fake(['assistant.test/api/v1/help/search*' => Http::response(['object' => 'help_search', 'query' => 'refund', 'groups' => [
            ['object' => 'help_result_group', 'source' => 'docs', 'label' => 'Docs', 'data' => [], 'has_more' => false],
            ['object' => 'help_result_group', 'source' => 'help_centre', 'label' => 'Help centre', 'has_more' => true, 'data' => [
                ['id' => 'hart_1', 'object' => 'help_article', 'source' => 'help_centre', 'title' => 'Refund an invoice', 'excerpt' => null, 'url' => 'https://help.test/a', 'product' => 'billing', 'updated_at' => '2026-11-10T10:00:00Z'],
            ]],
            ['object' => 'help_result_group', 'source' => 'knowledge_base', 'label' => 'Knowledge base', 'data' => [], 'has_more' => false],
        ]])]);

        $result = AiAssistant::help()->helpSearch($this->user(), 'refund', 'invoices.index', 3);

        self::assertSame('Refund an invoice', $result->group('help_centre')?->data[0]->title);
        self::assertTrue($result->group('help_centre')?->hasMore);
        self::assertSame([], $result->group('docs')?->data);
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $claims = (array) JWT::decode(substr($request->header('Authorization')[0], 7), new Key(self::SECRET, 'HS256'));

            return $query === ['q' => 'refund', 'service' => 'billing', 'topic' => 'invoices.index', 'limit' => '3']
                && $claims['sub'] === 'person-1' && $claims['account_id'] === 'org-1' && $claims['aud'] === 'widget-identity'
                && (array) $claims['service_context'] === ['service' => 'billing', 'environment' => 'live'];
        });
    }

    public function test_topic_articles_updates_and_mark_read_call_the_standard_paths(): void
    {
        Http::fake([
            'assistant.test/api/v1/help/topics/invoices.index/articles*' => Http::response(['object' => 'list', 'data' => [
                ['id' => 'kart_1', 'source' => 'knowledge_base', 'title' => 'Refunding', 'excerpt' => 'x', 'url' => 'https://billing.test/r'],
            ], 'has_more' => false, 'next_cursor' => null]),
            'assistant.test/api/v1/support/updates/supd_1/read' => Http::response(['id' => 'supd_1', 'object' => 'support_update', 'kind' => 'ticket', 'title' => 'T', 'excerpt' => 'E', 'ticket_number' => 7, 'url' => 'https://support.test/requests/7', 'is_read' => true, 'read_at' => '2026-11-10T10:00:00Z']),
            'assistant.test/api/v1/support/updates*' => Http::response(['object' => 'list', 'data' => [
                ['id' => 'supd_1', 'object' => 'support_update', 'kind' => 'chat', 'title' => 'Support chat', 'excerpt' => 'Done', 'author' => 'Ana', 'conversation_id' => 'c1', 'is_read' => false],
            ], 'has_more' => true, 'next_cursor' => 'abc', 'unread_count' => 4]),
        ]);
        $help = app(HelpClient::class);

        self::assertSame(['Refunding'], array_map(fn (HelpArticle $a): string => $a->title, $help->topicArticles($this->user(), 'invoices.index')));
        $page = $help->supportUpdates($this->user(), new DateTimeImmutable('2026-11-10 12:00', new DateTimeZone('Europe/Copenhagen')), 'cur', 10, true);
        self::assertSame(4, $page->unreadCount);
        self::assertSame('abc', $page->nextCursor);
        self::assertSame('c1', $page->data[0]->conversationId);
        $read = $help->markRead($this->user(), 'supd_1');
        self::assertTrue($read->isRead);
        self::assertSame(7, $read->ticketNumber);

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/support/updates?')
            && str_contains($r->url(), 'since=2026-11-10T11%3A00%3A00Z') && str_contains($r->url(), 'unread=1') && str_contains($r->url(), 'cursor=cur'));
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && $r->url() === 'https://assistant.test/api/v1/support/updates/supd_1/read');
    }

    public function test_problem_json_errors_and_outages_raise_a_help_exception(): void
    {
        Http::fake(['assistant.test/*' => Http::response(['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404, 'detail' => 'Support update not found.'], 404, ['Content-Type' => 'application/problem+json'])]);
        try {
            app(HelpClient::class)->markRead($this->user(), 'supd_x');
            self::fail('Expected a HelpException.');
        } catch (HelpException $e) {
            self::assertSame(404, $e->status);
            self::assertSame('Support update not found.', $e->getMessage());
        }

        Http::fake(['assistant.test/*' => Http::failedConnection()]);
        $this->expectException(HelpException::class);
        app(HelpClient::class)->helpSearch($this->user(), 'x');
    }

    public function test_an_article_opens_with_its_body(): void
    {
        Http::fake(['assistant.test/api/v1/help/articles/hart_01ABC' => Http::response([
            'id' => 'hart_01ABC', 'object' => 'help_article', 'source' => 'help_centre', 'title' => 'Refund an invoice',
            'excerpt' => null, 'url' => 'https://help.test/a', 'product' => 'billing', 'updated_at' => null, 'body_html' => '<p>Open the invoice.</p>',
        ])]);

        $article = AiAssistant::help()->article($this->user(), 'hart_01ABC');

        self::assertSame('<p>Open the invoice.</p>', $article->bodyHtml);
        self::assertSame('<p>Open the invoice.</p>', $article->toArray()['body_html']);
        self::assertArrayNotHasKey('body_html', (new HelpArticle(id: 'kart_1'))->toArray());
        self::assertSame('https://assistant.test/api/v1/help/articles/{id}', app(SupportSession::class)->forUser('person', 'org', 'billing')['article_url']);
    }

    public function test_the_service_key_is_normalized_or_left_out(): void
    {
        Http::fake(['assistant.test/*' => Http::response(['object' => 'list', 'data' => [], 'has_more' => false, 'next_cursor' => null])]);
        $help = app(HelpClient::class);

        $help->topicArticles(new SupportUser('person-1', 'org-1', ' Cbox_Billing '), 'invoices.index');
        $help->topicArticles(new SupportUser('person-1', 'org-1', 'billing/admin'), 'invoices.index');

        $sent = Http::recorded()->map(fn (array $pair): string => (string) parse_url($pair[0]->url(), PHP_URL_QUERY))->all();
        self::assertSame(['service=cbox-billing', ''], $sent);
    }

    public function test_missing_support_config_is_a_help_exception(): void
    {
        config(['ai-assistant-connector.handshake_secret' => null]);
        try {
            app(HelpClient::class)->helpSearch($this->user(), 'x');
            self::fail('Expected a HelpException.');
        } catch (HelpException $e) {
            self::assertSame(0, $e->status);
            self::assertInstanceOf(LogicException::class, $e->getPrevious());
        }

        config(['ai-assistant-connector.handshake_secret' => self::SECRET, 'ai-assistant-connector.platform.url' => 'http://assistant.test']);
        $this->expectException(HelpException::class);
        app(HelpClient::class)->supportUpdates($this->user());
    }

    public function test_the_fake_scopes_updates_per_person_and_organization_and_records_calls(): void
    {
        $help = AiAssistant::fakeHelp();
        self::assertSame($help, app(HelpClient::class));
        $help->addArticle(new HelpArticle(id: 'kart_1', title: 'Refunds', url: 'https://x.test'), topics: ['invoices.index'])
            ->addArticle(new HelpArticle(id: 'hart_1', source: 'help_centre', title: 'Refund policy'))
            ->addUpdate($this->user(), new SupportUpdate(id: 'supd_1', title: 'Refund done'));

        self::assertSame('Refund policy', AiAssistant::help()->helpSearch($this->user(), 'refund')->group('help_centre')?->data[0]->title);
        self::assertSame(['kart_1'], array_map(fn (HelpArticle $a): string => $a->id, AiAssistant::help()->topicArticles($this->user(), 'invoices.index')));
        self::assertSame(1, AiAssistant::help()->supportUpdates($this->user())->unreadCount);
        self::assertSame(0, AiAssistant::help()->supportUpdates(new SupportUser('person-1', 'org-2', 'billing'))->unreadCount);
        self::assertTrue(AiAssistant::help()->markRead($this->user(), 'supd_1')->isRead);
        self::assertSame([], AiAssistant::help()->supportUpdates($this->user(), unreadOnly: true)->data);
        self::assertSame('Refunds', AiAssistant::help()->article($this->user(), 'kart_1')->title);
        try {
            AiAssistant::help()->article($this->user(), 'kart_missing');
            self::fail('Expected a HelpException.');
        } catch (HelpException $e) {
            self::assertSame(404, $e->status);
        }
        $help->assertCalled('article', fn (array $args): bool => $args['id'] === 'kart_1');
        $help->assertCalled('helpSearch', fn (array $args, SupportUser $user): bool => $args['query'] === 'refund' && $user->account === 'org-1');

        $this->expectException(HelpException::class);
        AiAssistant::help()->markRead(new SupportUser('person-1', 'org-2', 'billing'), 'supd_1');
    }

    public function test_manifest_topics_are_sent_with_the_document(): void
    {
        self::assertSame(['invoices.index' => 3, 'invoices.show' => 3], SyncSupportKnowledge::topics(['invoices.index', 'invoices.show'], 3));
        self::assertSame(['invoices.index' => 1], SyncSupportKnowledge::topics(['invoices.index' => 1], 9));
        foreach ([['Not A Topic'], ['x' => 0], 'invoices.index'] as $bad) {
            try {
                SyncSupportKnowledge::topics($bad, 1);
                self::fail('Expected a LogicException.');
            } catch (LogicException) {
                self::addToAssertionCount(1);
            }
        }

        config(['ai-assistant-connector.api_key' => 'key']);
        Http::fake(['assistant.test/*' => Http::response(['data' => ['status' => 'indexed']])]);
        app(KnowledgeClient::class)->put('support', 'prod', 'refunds', 'Refunds', 'Text', 'https://billing.test/r', 2, 'en', ['invoices.index' => 1]);
        app(KnowledgeClient::class)->put('support', 'prod', 'vat', 'VAT', 'Text', 'https://billing.test/v', 1);
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/knowledge/refunds') && $r['topics'] === [['topic' => 'invoices.index', 'position' => 1]]);
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/knowledge/vat') && ! isset($r['topics']));
    }

    public function test_the_http_client_is_the_default_binding(): void
    {
        self::assertInstanceOf(HttpHelpClient::class, app(HelpClient::class));
    }

    private function user(): SupportUser
    {
        return new SupportUser('person-1', 'org-1', 'billing', ['environment' => 'live']);
    }
}
