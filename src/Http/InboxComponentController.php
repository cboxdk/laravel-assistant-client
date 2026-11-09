<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Http;

use Cbox\AiAssistant\Connector\Events\SupportAccessUsed;
use Cbox\AiAssistant\Connector\InboxComponentBuilder;
use Cbox\AiAssistant\Connector\Security\RequestVerifier;
use Cbox\AiAssistant\Connector\Security\VerificationException;
use Cbox\AiAssistant\Connector\Support\Payload;
use Cbox\AiAssistant\Connector\Support\PrincipalResolver;
use Cbox\AiAssistant\Connector\ToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Serves the support-inbox surface an agent works with. Verified exactly like the tool hook (a
 * platform-signed EdDSA JWT with `aud` = us + a single-use `jti`), so only your assistant platform
 * — acting on behalf of a verified end-user — can hydrate a card or fire an action.
 *
 *   GET  /inbox/{key} → hydrate a read-only CARD: run its handler and return `{data: …}` the inbox
 *                       renders in the sidebar.
 *   POST /inbox/{key} → fire a confirm-gated ACTION: run its handler with the submitted arguments
 *                       and return `{result: …}`.
 *
 * Your guard + handler own authorization and business logic, scoped by the on-behalf principal
 * (`$ctx->accountId()` for B2B2B).
 */
class InboxComponentController
{
    public function __construct(
        private readonly RequestVerifier $verifier,
        private readonly ToolRegistry $registry,
        private readonly PrincipalResolver $principals,
    ) {}

    public function __invoke(Request $request, string $key): JsonResponse
    {
        try {
            $claims = $this->verifier->verify((string) $request->header('Authorization', ''), $request->getHost());
        } catch (VerificationException $e) {
            return response()->json(['error' => 'unauthorized', 'message' => $e->getMessage()], 401);
        }

        $component = $this->registry->inboxComponent($key);

        if ($component === null) {
            return response()->json(['error' => 'unknown_component', 'message' => "No inbox component [{$key}]."], 404);
        }

        // A GET may only reach a card; a POST may only reach an action — so a read can never fire a
        // write, and a write can never masquerade as a hydrate.
        $expected = $request->isMethod('GET') ? InboxComponentBuilder::KIND_CARD : InboxComponentBuilder::KIND_ACTION;

        if ($component->kind !== $expected) {
            return response()->json(['error' => 'method_not_allowed', 'message' => 'This component does not support that method.'], 405);
        }

        $ctx = $this->principals->context(Payload::object($claims['principal'] ?? []));

        if (! $component->passesGuard($ctx)) {
            return response()->json(['error' => 'forbidden', 'message' => 'You are not allowed to do that.'], 403);
        }

        SupportAccessUsed::dispatchFor($ctx, SupportAccessUsed::SURFACE_INBOX, [$component->key]);

        $arguments = $request->isMethod('GET')
            ? $this->scalarArgs(Payload::object($request->query()))
            : $this->scalarArgs(Payload::object($request->all()));

        try {
            $result = $component->run($arguments, $ctx);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['error' => 'component_failed', 'message' => 'The action could not be completed.'], 422);
        }

        return response()->json([$expected === InboxComponentBuilder::KIND_CARD ? 'data' : 'result' => $result]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, scalar>
     */
    private function scalarArgs(array $args): array
    {
        $out = [];

        foreach ($args as $name => $value) {
            if (is_scalar($value)) {
                $out[$name] = $value;
            }
        }

        return $out;
    }
}
