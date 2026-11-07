<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Http;

use Cbox\AiAssistant\Connector\Security\RequestVerifier;
use Cbox\AiAssistant\Connector\Security\VerificationException;
use Cbox\AiAssistant\Connector\ToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Auto-discovery: lists the tools this app exposes (name, description, argument schema) so
 * the platform can import them without hand-wiring each one. The request is platform-signed
 * and verified, so only your assistant platform can enumerate your tools.
 */
class ManifestController
{
    public function __construct(
        private readonly RequestVerifier $verifier,
        private readonly ToolRegistry $registry,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $this->verifier->verify((string) $request->header('Authorization', ''), $request->getHost(), requireReplayId: false);
        } catch (VerificationException $e) {
            return response()->json(['error' => 'unauthorized', 'message' => $e->getMessage()], 401);
        }

        // The full document (version + tools-with-kind + procedures/groups). `tools` keeps
        // its historical shape at the top level, so an older one-shot importer still works.
        return response()->json($this->registry->document());
    }
}
