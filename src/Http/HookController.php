<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Http;

use Cbox\AiAssistant\Connector\Events\SupportAccessUsed;
use Cbox\AiAssistant\Connector\Security\RequestVerifier;
use Cbox\AiAssistant\Connector\Security\VerificationException;
use Cbox\AiAssistant\Connector\Support\Payload;
use Cbox\AiAssistant\Connector\Support\PrincipalResolver;
use Cbox\AiAssistant\Connector\ToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Receives a signed tool call from the platform, verifies it, resolves the end-user to
 * your model, runs the tool's guard, and invokes its handler — so business logic and
 * authorization stay entirely in your app.
 */
class HookController
{
    public function __construct(
        private readonly RequestVerifier $verifier,
        private readonly ToolRegistry $registry,
        private readonly PrincipalResolver $principals,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $claims = $this->verifier->verify((string) $request->header('Authorization', ''), $request->getHost());
        } catch (VerificationException $e) {
            return response()->json(['error' => 'unauthorized', 'message' => $e->getMessage()], 401);
        }

        $name = is_string($claims['tool'] ?? null) ? $claims['tool'] : '';
        $tool = $this->registry->get($name);

        if ($tool === null) {
            return response()->json(['error' => 'unknown_tool', 'message' => "No tool [{$name}]."], 404);
        }

        $ctx = $this->principals->context(Payload::object($claims['principal'] ?? []));

        if (! $tool->passesGuard($ctx)) {
            return response()->json(['error' => 'forbidden', 'message' => 'You are not allowed to do that.'], 403);
        }

        SupportAccessUsed::dispatchFor($ctx, SupportAccessUsed::SURFACE_TOOL, [$tool->name]);

        try {
            $result = $tool->run($this->arguments($claims), $ctx);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['error' => 'tool_failed', 'message' => 'The action could not be completed.'], 422);
        }

        return response()->json(['result' => $result]);
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>
     */
    private function arguments(array $claims): array
    {
        $args = $claims['arguments'] ?? [];

        return Payload::object($args);
    }
}
