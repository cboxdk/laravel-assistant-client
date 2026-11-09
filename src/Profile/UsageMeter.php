<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Profile;

/** How much of one metered allowance the customer uses ("sites: 3 of 5"). */
readonly class UsageMeter
{
    public function __construct(
        public string $meter,
        public int|float $used,
        public int|float|null $limit = null,
        public ?string $unit = null,
    ) {}

    /** Null when the meter name is empty or a number is not finite and non-negative. */
    public static function tryMake(mixed $meter, mixed $used, mixed $limit = null, mixed $unit = null): ?self
    {
        $meter = is_string($meter) ? Normalize::text($meter, 64) : '';
        $unit = is_string($unit) ? Normalize::text($unit, 32) : '';

        $usedAmount = self::amount($used);
        $limitAmount = $limit === null ? null : self::amount($limit);

        if ($meter === '' || $usedAmount === null || ($limit !== null && $limitAmount === null)) {
            return null;
        }

        return new self($meter, $usedAmount, $limitAmount, $unit === '' ? null : $unit);
    }

    /** @return array{meter: string, used: int|float, limit: int|float|null, unit: ?string} */
    public function toArray(): array
    {
        return ['meter' => $this->meter, 'used' => $this->used, 'limit' => $this->limit, 'unit' => $this->unit];
    }

    private static function amount(mixed $value): int|float|null
    {
        return (is_int($value) || (is_float($value) && is_finite($value))) && $value >= 0 ? $value : null;
    }
}
