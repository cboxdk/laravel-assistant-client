<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Http;

use Cbox\AiAssistant\Connector\Events\SupportAccessUsed;
use Cbox\AiAssistant\Connector\Profile\ProfileCollector;
use Cbox\AiAssistant\Connector\Security\RequestVerifier;
use Cbox\AiAssistant\Connector\Security\VerificationException;
use Cbox\AiAssistant\Connector\Support\Payload;
use Cbox\AiAssistant\Connector\Support\PrincipalResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The signed customer profile: what the verified principal has in this service and how their
 * account is doing. Verified like discovery — platform signature, audience, expiry and connector
 * handshake; being read-only, the request may omit `jti` (a present `jti` is still single-use).
 *
 *   GET /profile?resources=a,b&filter[q]=shop
 *
 * Guards and list closures run as the principal; the response never fails as a whole because one
 * resource does.
 */
class ProfileController
{
    public function __construct(
        private readonly RequestVerifier $verifier,
        private readonly PrincipalResolver $principals,
        private readonly ProfileCollector $collector,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $claims = $this->verifier->verify((string) $request->header('Authorization', ''), $request->getHost(), requireReplayId: false);
        } catch (VerificationException $e) {
            return response()->json(['error' => 'unauthorized', 'message' => $e->getMessage()], 401);
        }

        $ctx = $this->principals->context(Payload::object($claims['principal'] ?? []));
        $profile = $this->collector->collect($ctx, $this->only($request), $this->filters($request));

        SupportAccessUsed::dispatchFor($ctx, SupportAccessUsed::SURFACE_PROFILE, $profile->resourceKeys);

        return response()->json($profile->toArray());
    }

    /** @return list<string>|null */
    private function only(Request $request): ?array
    {
        $resources = $request->query('resources');

        if (! is_string($resources) || trim($resources) === '') {
            return null;
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $resources)), fn (string $key): bool => $key !== ''));
    }

    /** @return array<string, string> */
    private function filters(Request $request): array
    {
        $filters = $request->query('filter');
        $out = [];

        if (! is_array($filters)) {
            return $out;
        }

        foreach ($filters as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $out[$name] = Str::substr(trim((string) $value), 0, 200);
            }
        }

        return $out;
    }
}
