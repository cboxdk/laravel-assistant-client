<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Tests;

use Cbox\AiAssistant\Connector\Embedded\EmbeddedException;
use Cbox\AiAssistant\Connector\Facades\AiAssistant;
use Cbox\AiAssistant\Connector\Knowledge\KnowledgeClient;
use Cbox\AiAssistant\Connector\Support\ToolContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The embedded runner: this app drives the loop against the platform's reasoning API and
 * executes its own registered tools in-process. The reasoning API is faked; what matters
 * is that the local tool actually runs, with the principal, and its result is posted back.
 */
final class EmbeddedRunnerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('ai-assistant-connector.platform.url', 'https://reasoning.test');
        $app['config']->set('ai-assistant-connector.api_key', 'tenant-key-abc');
    }

    public function test_success_status_with_non_protocol_body_is_rejected(): void
    {
        Http::fake(['reasoning.test/*' => Http::response('<html>Proxy error</html>', 200)]);
        $this->expectException(EmbeddedException::class);
        AiAssistant::assistant('billing')->send('Status?');
    }

    public function test_knowledge_sync_requires_an_indexed_acknowledgement(): void
    {
        Http::fake(['reasoning.test/*' => Http::response('<html>Proxy error</html>', 200)]);
        $this->expectException(EmbeddedException::class);
        (new KnowledgeClient(app('config')))->put('billing', 'product', 'guide', 'Title', 'Text', 'https://example.test/guide', 1);
    }

    public function test_context_survives_as_and_is_sent_on_both_sides_of_a_tool_round(): void
    {
        AiAssistant::tool('status')->guard(fn (): bool => true)->handle(fn (): string => 'paid');
        Http::fake([
            'reasoning.test/*/turn' => Http::response(['data' => ['type' => 'tool_calls', 'run_id' => 'run', 'calls' => [['id' => 'call', 'name' => 'status']]]]),
            'reasoning.test/*/tool_outputs' => Http::response(['data' => ['type' => 'message', 'conversation_id' => 'chat', 'content' => 'Betalt']]),
        ]);
        AiAssistant::assistant('billing')->locale('da')->forProduct('billing')->inEnvironment('sandbox')->forAccount('acme')->as(['id' => 'alice'])->send('Status?');
        $expected = ['locale' => 'da', 'product_id' => 'billing', 'environment' => 'sandbox', 'account_id' => 'acme', 'id' => 'alice'];
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/turn') && $request['principal'] === $expected);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/tool_outputs') && $request['principal'] === $expected);
    }

    public function test_it_executes_a_local_tool_and_returns_the_reply(): void
    {
        $seen = null;

        AiAssistant::tool('lookup_order')
            ->parameters(['order_number' => 'string'])
            ->guard(fn (): bool => true)->handle(function (array $args, ToolContext $ctx) use (&$seen): array {
                $seen = ['args' => $args, 'user' => $ctx->userId(), 'verified' => $ctx->verified];

                return ['status' => 'shipped', 'eta' => 'Friday'];
            });

        Http::fake([
            'reasoning.test/*/turn' => Http::response(['data' => [
                'type' => 'tool_calls', 'run_id' => 'run_1', 'conversation_id' => 'cnv_1',
                'calls' => [['id' => 'call_1', 'name' => 'lookup_order', 'arguments' => ['order_number' => '10493']]],
            ]]),
            'reasoning.test/*/tool_outputs' => Http::response(['data' => [
                'type' => 'message', 'conversation_id' => 'cnv_1', 'content' => 'Your order shipped and arrives Friday.',
            ]]),
        ]);

        $reply = AiAssistant::assistant('support')
            ->as(['id' => 'u_42', 'email' => 'a@shop.test'])
            ->send('Where is order 10493?');

        self::assertSame('Your order shipped and arrives Friday.', $reply->content);
        self::assertSame('cnv_1', $reply->conversationId);
        self::assertSame(1, $reply->toolRounds);

        // The tool actually ran, in-process, with the caller's principal.
        self::assertSame(['order_number' => '10493'], $seen['args']);
        self::assertSame('u_42', $seen['user']);
        self::assertTrue($seen['verified']);

        // The turn carried our manifest + API key; the outputs carried the tool result.
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/turn')
            && $r->hasHeader('Authorization', 'Bearer tenant-key-abc')
            && $r['tools'][0]['name'] === 'lookup_order'
            && $r['principal']['id'] === 'u_42');

        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/tool_outputs')
            && $r['outputs'][0]['tool_call_id'] === 'call_1'
            && $r['outputs'][0]['result']['status'] === 'shipped');
    }

    public function test_the_account_scopes_the_turn_and_reaches_the_tool(): void
    {
        $seenAccount = null;

        AiAssistant::tool('lookup_order')
            ->guard(fn (): bool => true)->handle(function (array $args, ToolContext $ctx) use (&$seenAccount): array {
                $seenAccount = $ctx->accountId();

                return ['ok' => true];
            });

        Http::fake([
            'reasoning.test/*/turn' => Http::response(['data' => [
                'type' => 'tool_calls', 'run_id' => 'run_a', 'conversation_id' => 'cnv_a',
                'calls' => [['id' => 'call_1', 'name' => 'lookup_order', 'arguments' => []]],
            ]]),
            'reasoning.test/*/tool_outputs' => Http::response(['data' => [
                'type' => 'message', 'conversation_id' => 'cnv_a', 'content' => 'done',
            ]]),
        ]);

        AiAssistant::assistant('support')
            ->as(['id' => 'u_1'])
            ->forAccount('acme')
            ->send('go');

        // The account is sent to the reasoning API (so it scopes tools there)...
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/turn') && $r['principal']['account_id'] === 'acme');
        // ...and reaches the in-process tool as the boundary to scope its own query by.
        self::assertSame('acme', $seenAccount);
    }

    public function test_a_message_turn_returns_without_a_tool_round(): void
    {
        Http::fake([
            'reasoning.test/*/turn' => Http::response(['data' => [
                'type' => 'message', 'conversation_id' => 'cnv_9', 'content' => 'Hello!',
            ]]),
        ]);

        $reply = AiAssistant::assistant('support')->as(['id' => 'u_1'])->send('Hi');

        self::assertSame('Hello!', $reply->content);
        self::assertSame(0, $reply->toolRounds);
        Http::assertSentCount(1);
    }

    public function test_an_unknown_tool_returns_an_error_result_not_a_crash(): void
    {
        Http::fake([
            'reasoning.test/*/turn' => Http::response(['data' => [
                'type' => 'tool_calls', 'run_id' => 'run_2', 'conversation_id' => 'cnv_2',
                'calls' => [['id' => 'call_x', 'name' => 'ghost_tool', 'arguments' => []]],
            ]]),
            'reasoning.test/*/tool_outputs' => Http::response(['data' => [
                'type' => 'message', 'conversation_id' => 'cnv_2', 'content' => 'ok',
            ]]),
        ]);

        AiAssistant::assistant('support')->as(['id' => 'u_1'])->send('go');

        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/tool_outputs')
            && $r['outputs'][0]['result']['error'] === 'unknown_tool');
    }

    public function test_a_denying_guard_is_reported_to_the_model(): void
    {
        AiAssistant::tool('refund')
            ->guard(fn (ToolContext $ctx): bool => false)->handle(fn (): string => 'should not run');

        Http::fake([
            'reasoning.test/*/turn' => Http::response(['data' => [
                'type' => 'tool_calls', 'run_id' => 'run_3', 'conversation_id' => 'cnv_3',
                'calls' => [['id' => 'call_r', 'name' => 'refund', 'arguments' => []]],
            ]]),
            'reasoning.test/*/tool_outputs' => Http::response(['data' => [
                'type' => 'message', 'conversation_id' => 'cnv_3', 'content' => 'ok',
            ]]),
        ]);

        AiAssistant::assistant('support')->as(['id' => 'u_1'])->send('refund me');

        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/tool_outputs')
            && $r['outputs'][0]['result']['error'] === 'forbidden');
    }

    public function test_a_failing_reasoning_api_raises(): void
    {
        Http::fake(['reasoning.test/*' => Http::response(['error' => 'boom'], 500)]);

        $this->expectException(EmbeddedException::class);

        AiAssistant::assistant('support')->as(['id' => 'u_1'])->send('Hi');
    }
}
