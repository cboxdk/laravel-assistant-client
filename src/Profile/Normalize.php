<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Profile;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;
use Throwable;

/**
 * Shared normalisation rules for profile values. Everything that reaches Assistant passes
 * through here: text is cut, only HTTPS links survive and dates become immutable.
 *
 * @internal
 */
class Normalize
{
    public static function text(string $value, int $max): string
    {
        return Str::substr(trim($value), 0, $max);
    }

    public static function httpsUrl(mixed $value): ?string
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https'
            && is_string(parse_url($value, PHP_URL_HOST))
            ? $value
            : null;
    }

    public static function date(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Keep scalar (or null) values under string keys, at most `$max`, strings cut to 200.
     *
     * @return array<string, scalar|null>
     */
    public static function scalars(mixed $values, int $max, bool $allowNull): array
    {
        if (! is_array($values)) {
            return [];
        }

        $out = [];

        foreach ($values as $key => $value) {
            if (count($out) >= $max) {
                break;
            }

            $name = is_string($key) ? self::text($key, 64) : '';

            if ($name === '' || ! (is_scalar($value) || ($allowNull && $value === null))) {
                continue;
            }

            $out[$name] = is_string($value) ? self::text($value, 200) : $value;
        }

        return $out;
    }
}
