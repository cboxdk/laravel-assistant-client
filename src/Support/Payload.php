<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Support;

use UnexpectedValueException;

/** Reject malformed JSON at the transport boundary instead of coercing it into tool inputs. */
class Payload
{
    /** @return array<string, mixed> */
    public static function object(mixed $value): array
    {
        if (! is_array($value)) {
            throw new UnexpectedValueException('Expected a JSON object.');
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new UnexpectedValueException('Expected named JSON object fields.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public static function objects(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new UnexpectedValueException('Expected a JSON list.');
        }

        return array_map(self::object(...), $value);
    }

    public static function string(mixed $value): string
    {
        if (! is_string($value)) {
            throw new UnexpectedValueException('Expected a JSON string.');
        }

        return $value;
    }

    public static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : self::string($value);
    }
}
