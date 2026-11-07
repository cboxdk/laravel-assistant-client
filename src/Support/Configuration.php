<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Support;

use Illuminate\Contracts\Config\Repository;
use LogicException;

/** Validate deployment configuration before making a network request. */
class Configuration
{
    public static function string(Repository $config, string $key): string
    {
        $value = $config->get($key);
        if (! is_string($value) || $value === '') {
            throw new LogicException("Configure {$key} with a non-empty string.");
        }

        return $value;
    }

    public static function positiveInteger(Repository $config, string $key, int $default): int
    {
        $value = $config->get($key, $default);
        $integer = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
        if ($integer === false || $integer < 1) {
            throw new LogicException("Configure {$key} with a positive integer.");
        }

        return $integer;
    }
}
