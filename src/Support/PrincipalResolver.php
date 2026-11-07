<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Support;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Turns a principal (as asserted by the platform hook, or built from your own auth in
 * embedded mode) into a {@see ToolContext}: it resolves the subject to your user model
 * and packages both for a tool's guard and handler. Shared by the signed hook and the
 * embedded runner so resolution behaves identically in both modes.
 */
class PrincipalResolver
{
    /**
     * @param  array<string, mixed>  $principal
     */
    public function context(array $principal, ?object $user = null): ToolContext
    {
        return new ToolContext(
            principal: $principal,
            user: $user ?? $this->resolve($principal),
            verified: ($principal['verified'] ?? false) === true,
        );
    }

    /**
     * Resolve the principal's subject to your user model. Fails soft — a missing or
     * erroring lookup returns null (the principal is still available to the tool), never a
     * 500.
     *
     * @param  array<string, mixed>  $principal
     */
    public function resolve(array $principal): ?object
    {
        $userId = $principal['sub'] ?? null;

        if (! is_scalar($userId) || (string) $userId === '') {
            return null;
        }

        $resolver = config('ai-assistant-connector.resolve_user');

        if (is_callable($resolver)) {
            $user = $resolver((string) $userId);

            return is_object($user) ? $user : null;
        }

        $model = config('auth.providers.users.model');

        if (is_string($model) && is_subclass_of($model, Model::class)) {
            try {
                $user = $model::query()->find($userId);

                return is_object($user) ? $user : null;
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
