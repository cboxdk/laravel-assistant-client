<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Profile;

use Cbox\AiAssistant\Connector\Support\ToolContext;
use Cbox\AiAssistant\Connector\ToolRegistry;
use Throwable;
use UnexpectedValueException;

/**
 * Collects the customer profile for one verified principal. Each resource is isolated: a denying
 * guard leaves it out, and a failing list is reported and served as `{"error": "unavailable"}`
 * while the others still return. A failing signals closure is reported and skipped.
 */
class ProfileCollector
{
    public function __construct(private readonly ToolRegistry $registry) {}

    /**
     * @param  list<string>|null  $only  resource keys to include; null means all
     * @param  array<string, string>  $filters  passed to every list closure (e.g. `q`)
     */
    public function collect(ToolContext $ctx, ?array $only = null, array $filters = []): CustomerProfile
    {
        $resources = [];
        $keys = [];

        foreach ($this->registry->resources() as $key => $resource) {
            if ($only !== null && ! in_array($key, $only, true)) {
                continue;
            }

            try {
                if (! $resource->passesGuard($ctx)) {
                    continue;
                }
            } catch (Throwable $e) {
                report($e);

                continue; // A guard that fails denies.
            }

            $keys[] = $key;

            try {
                $resources[$key] = ['label' => $resource->displayLabel(), ...$resource->items($ctx, $filters)];
            } catch (Throwable $e) {
                report($e);
                $resources[$key] = ['label' => $resource->displayLabel(), 'error' => 'unavailable'];
            }
        }

        return new CustomerProfile($resources, $this->signals($ctx), $keys);
    }

    private function signals(ToolContext $ctx): ?Signals
    {
        $providers = $this->registry->signalProviders();

        if ($providers === []) {
            return null;
        }

        $signals = Signals::make();

        foreach ($providers as $provider) {
            try {
                $result = $provider($ctx);
                $signals = $signals->merge(match (true) {
                    $result instanceof Signals => $result,
                    is_array($result) => Signals::fromArray($result),
                    default => throw new UnexpectedValueException('A signals closure must return Signals or an array.'),
                });
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $signals;
    }
}
