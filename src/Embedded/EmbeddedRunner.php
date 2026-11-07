<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Embedded;

use Cbox\AiAssistant\Connector\Support\Configuration;
use Cbox\AiAssistant\Connector\Support\Payload;
use Cbox\AiAssistant\Connector\Support\PrincipalResolver;
use Cbox\AiAssistant\Connector\Support\ToolContext;
use Cbox\AiAssistant\Connector\ToolRegistry;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Http;
use Throwable;
use UnexpectedValueException;

/**
 * Drives a backend-driven ("embedded") run against the platform's reasoning API. We keep
 * the loop: send a turn with our tool manifest, and whenever the model asks for tools we
 * resolve them from the local registry and execute them IN-PROCESS — same guard, same
 * handler, same {@see ToolContext} as the signed hook,
 * only without the network round-trip or signing. The platform only ever reasons; it never
 * touches your systems.
 */
readonly class EmbeddedRunner
{
    public function __construct(
        private ToolRegistry $registry,
        private PrincipalResolver $principals,
        private Config $config,
    ) {}

    /**
     * @param  array<string, mixed>  $platformPrincipal  identity sent to the reasoning API ({id, email, ...})
     * @param  object|null  $user  the resolved local user, handed to guards/handlers as $ctx->user
     */
    public function run(string $assistantKey, string $message, array $platformPrincipal, ?object $user, ?string $conversationId): EmbeddedReply
    {
        $base = rtrim(Configuration::string($this->config, 'ai-assistant-connector.platform.url'), '/');
        $maxRounds = Configuration::positiveInteger($this->config, 'ai-assistant-connector.max_tool_rounds', 10);

        $data = $this->call($base.'/api/v1/assistants/'.rawurlencode($assistantKey).'/turn', array_filter([
            'input' => $message,
            'conversation_id' => $conversationId,
            'principal' => $platformPrincipal === [] ? null : $platformPrincipal,
            'tools' => $this->registry->manifest() ?: null,
        ], static fn ($v): bool => $v !== null));

        $rounds = 0;

        while (($data['type'] ?? null) === 'tool_calls') {
            if (++$rounds > $maxRounds) {
                throw new EmbeddedException("Embedded run exceeded {$maxRounds} tool rounds — check for a tool that never satisfies the model.");
            }

            $runId = Payload::string($data['run_id'] ?? null);
            $outputs = $this->execute(Payload::objects($data['calls'] ?? null), $platformPrincipal, $user);

            $data = $this->call($base.'/api/v1/runs/'.rawurlencode($runId).'/tool_outputs', ['outputs' => $outputs, 'principal' => $platformPrincipal]);
        }

        return new EmbeddedReply(
            content: Payload::string($data['content'] ?? ''),
            conversationId: Payload::string($data['conversation_id'] ?? ($conversationId ?? '')),
            toolRounds: $rounds,
        );
    }

    /**
     * Execute each requested call against the local registry, in-process. A tool that is
     * unknown, forbidden by its guard, or throws returns a structured error to the model
     * (so it can adapt) rather than failing the whole run.
     *
     * @param  list<array<string, mixed>>  $calls
     * @param  array<string, mixed>  $platformPrincipal
     * @return list<array{tool_call_id: string, result: mixed}>
     */
    private function execute(array $calls, array $platformPrincipal, ?object $user): array
    {
        $ctx = $this->principals->context($this->localPrincipal($platformPrincipal), $user);
        $outputs = [];

        foreach ($calls as $call) {
            $id = Payload::string($call['id'] ?? null);
            $name = Payload::string($call['name'] ?? null);
            $arguments = Payload::object($call['arguments'] ?? []);
            $tool = $this->registry->get($name);

            if ($tool === null) {
                $outputs[] = ['tool_call_id' => $id, 'result' => ['error' => 'unknown_tool', 'tool' => $name]];

                continue;
            }

            if (! $tool->passesGuard($ctx)) {
                $outputs[] = ['tool_call_id' => $id, 'result' => ['error' => 'forbidden']];

                continue;
            }

            try {
                $result = $tool->run($arguments, $ctx);
            } catch (Throwable $e) {
                report($e);
                $result = ['error' => 'tool_failed'];
            }

            $outputs[] = ['tool_call_id' => $id, 'result' => $result];
        }

        return $outputs;
    }

    /**
     * Map the platform principal ({id, email}) to the connector's convention ({sub, ...})
     * used by ToolContext. Embedded callers are backend-trusted, so the principal is
     * verified by construction.
     *
     * @param  array<string, mixed>  $platformPrincipal
     * @return array<string, mixed>
     */
    private function localPrincipal(array $platformPrincipal): array
    {
        return array_filter([
            'sub' => $platformPrincipal['id'] ?? null,
            'email' => $platformPrincipal['email'] ?? null,
            'name' => $platformPrincipal['name'] ?? null,
            'account_id' => $platformPrincipal['account_id'] ?? null,
            'session_type' => 'api',
            'verified' => true,
        ], static fn ($v): bool => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $url, array $payload): array
    {
        $apiKey = Configuration::string($this->config, 'ai-assistant-connector.api_key');
        $timeout = Configuration::positiveInteger($this->config, 'ai-assistant-connector.timeout', 30);

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout($timeout)
            ->post($url, $payload);

        if ($response->failed()) {
            throw new EmbeddedException("Reasoning API returned {$response->status()} for {$url}.");
        }

        try {
            $body = Payload::object($response->json());
            // The turn/tool_outputs endpoints wrap their payload in `data`.
            $data = Payload::object($body['data'] ?? $body);
        } catch (UnexpectedValueException $e) {
            throw new EmbeddedException('Reasoning API returned an invalid response envelope.', previous: $e);
        }
        if (! in_array($data['type'] ?? null, ['message', 'tool_calls'], true)) {
            throw new EmbeddedException('Reasoning API returned an invalid response envelope.');
        }

        return $data;
    }
}
