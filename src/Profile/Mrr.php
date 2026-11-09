<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Profile;

/** Monthly recurring revenue in minor units (cents, øre) of an ISO 4217 currency. */
readonly class Mrr
{
    public function __construct(
        public int $amountMinor,
        public string $currency,
    ) {}

    /** Null when the amount is negative or the currency is not three letters. */
    public static function tryMake(mixed $amountMinor, mixed $currency): ?self
    {
        if (! is_int($amountMinor) || $amountMinor < 0 || ! is_string($currency)) {
            return null;
        }

        $currency = strtoupper(trim($currency));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? new self($amountMinor, $currency) : null;
    }

    /** @return array{amount_minor: int, currency: string} */
    public function toArray(): array
    {
        return ['amount_minor' => $this->amountMinor, 'currency' => $this->currency];
    }
}
