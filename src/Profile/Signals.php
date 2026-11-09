<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Profile;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Account-level facts about the customer in a fixed, typed vocabulary: lifecycle, plan, MRR,
 * customer since, seats, usage, health flags, recent changes and a few free facts.
 *
 * Every value is validated on the way in and an invalid one is dropped rather than thrown, so a
 * slightly wrong signal never costs the whole profile. Build it fluently
 * (`Signals::make()->plan('Pro')->flag(SignalFlag::SiteDown)`) or from an array in the wire
 * shape (`Signals::fromArray([...])`), where unknown keys are ignored.
 */
class Signals
{
    public const MAX_USAGE = 10;

    public const MAX_CHANGES = 10;

    public const MAX_FACTS = 10;

    public const CHANGE_WINDOW_DAYS = 7;

    public private(set) ?Lifecycle $lifecycle = null;

    public private(set) ?string $plan = null;

    public private(set) ?Mrr $mrr = null;

    public private(set) ?DateTimeImmutable $customerSince = null;

    public private(set) ?int $seats = null;

    /** @var list<UsageMeter> */
    public private(set) array $usage = [];

    /** @var list<SignalFlag> */
    public private(set) array $flags = [];

    /** @var list<RecentChange> */
    public private(set) array $recentChanges = [];

    /** @var array<string, scalar> */
    public private(set) array $facts = [];

    public static function make(): self
    {
        return new self;
    }

    /**
     * Read signals in the wire shape. Unknown keys and invalid values are dropped.
     *
     * @param  array<array-key, mixed>  $signals
     */
    public static function fromArray(array $signals): self
    {
        $out = new self;

        if (is_string($signals['lifecycle'] ?? null) || ($signals['lifecycle'] ?? null) instanceof Lifecycle) {
            $out->lifecycle($signals['lifecycle']);
        }

        if (is_string($signals['plan'] ?? null)) {
            $out->plan($signals['plan']);
        }

        $mrr = $signals['mrr'] ?? null;
        if ($mrr instanceof Mrr) {
            $out->mrr = $mrr;
        } elseif (is_array($mrr)) {
            $out->mrr = Mrr::tryMake($mrr['amount_minor'] ?? null, $mrr['currency'] ?? null);
        }

        $since = $signals['customer_since'] ?? null;
        if ($since instanceof DateTimeInterface || is_string($since)) {
            $out->customerSince($since);
        }

        if (is_int($signals['seats'] ?? null)) {
            $out->seats($signals['seats']);
        }

        foreach (self::list($signals['usage'] ?? null) as $meter) {
            $out->addUsage(is_array($meter)
                ? UsageMeter::tryMake($meter['meter'] ?? null, $meter['used'] ?? null, $meter['limit'] ?? null, $meter['unit'] ?? null)
                : ($meter instanceof UsageMeter ? $meter : null));
        }

        foreach (self::list($signals['flags'] ?? null) as $flag) {
            if (is_string($flag) || $flag instanceof SignalFlag) {
                $out->flag($flag);
            }
        }

        foreach (self::list($signals['recent_changes'] ?? null) as $change) {
            $out->addChange(is_array($change)
                ? RecentChange::tryMake($change['what'] ?? null, $change['at'] ?? null, $change['url'] ?? null)
                : ($change instanceof RecentChange ? $change : null));
        }

        foreach (Normalize::scalars($signals['facts'] ?? null, self::MAX_FACTS, allowNull: false) as $key => $value) {
            if ($value !== null) {
                $out->fact($key, $value);
            }
        }

        return $out;
    }

    public function lifecycle(Lifecycle|string $lifecycle): self
    {
        $this->lifecycle = $lifecycle instanceof Lifecycle ? $lifecycle : (Lifecycle::tryFrom($lifecycle) ?? $this->lifecycle);

        return $this;
    }

    public function plan(string $plan): self
    {
        $plan = Normalize::text($plan, 120);
        $this->plan = $plan === '' ? $this->plan : $plan;

        return $this;
    }

    /** Monthly recurring revenue in minor units of an ISO 4217 currency: `mrr(4900, 'EUR')`. */
    public function mrr(int $amountMinor, string $currency): self
    {
        $this->mrr = Mrr::tryMake($amountMinor, $currency) ?? $this->mrr;

        return $this;
    }

    public function customerSince(DateTimeInterface|string $since): self
    {
        $this->customerSince = Normalize::date($since) ?? $this->customerSince;

        return $this;
    }

    public function seats(int $seats): self
    {
        $this->seats = $seats >= 0 ? $seats : $this->seats;

        return $this;
    }

    /** One metered allowance; at most ten are kept. */
    public function usage(string $meter, int|float $used, int|float|null $limit = null, ?string $unit = null): self
    {
        return $this->addUsage(UsageMeter::tryMake($meter, $used, $limit, $unit));
    }

    /** Health flags from the fixed vocabulary; unknown strings are dropped, duplicates ignored. */
    public function flag(SignalFlag|string ...$flags): self
    {
        foreach ($flags as $flag) {
            $flag = $flag instanceof SignalFlag ? $flag : SignalFlag::tryFrom($flag);

            if ($flag !== null && ! in_array($flag, $this->flags, true)) {
                $this->flags[] = $flag;
            }
        }

        return $this;
    }

    /**
     * Something that changed within the last seven days; older changes are dropped and at most
     * the ten newest are served.
     */
    public function change(string $what, DateTimeInterface|string $at, ?string $url = null): self
    {
        return $this->addChange(RecentChange::tryMake($what, $at, $url));
    }

    /** A free fact (`region: eu`); at most ten keys, strings cut to 200 characters. */
    public function fact(string $key, string|int|float|bool $value): self
    {
        $facts = Normalize::scalars([$key => $value], 1, allowNull: false);

        foreach ($facts as $name => $scalar) {
            if ($scalar !== null && (isset($this->facts[$name]) || count($this->facts) < self::MAX_FACTS)) {
                $this->facts[$name] = $scalar;
            }
        }

        return $this;
    }

    /**
     * Combine with signals from another closure. Single values set in `$other` win; usage,
     * flags, changes and facts are combined and capped again. Neither input is changed.
     */
    public function merge(self $other): self
    {
        $merged = clone $this;
        $merged->lifecycle = $other->lifecycle ?? $this->lifecycle;
        $merged->plan = $other->plan ?? $this->plan;
        $merged->mrr = $other->mrr ?? $this->mrr;
        $merged->customerSince = $other->customerSince ?? $this->customerSince;
        $merged->seats = $other->seats ?? $this->seats;

        foreach ($other->usage as $meter) {
            $merged->addUsage($meter);
        }

        $merged->flag(...$other->flags);

        foreach ($other->recentChanges as $change) {
            $merged->addChange($change);
        }

        foreach ($other->facts as $key => $value) {
            $merged->fact($key, $value);
        }

        return $merged;
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    /**
     * The wire shape Assistant receives. Only keys with a value are present.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $changes = array_values(array_filter(
            $this->recentChanges,
            fn (RecentChange $change): bool => $change->at >= Carbon::now()->subDays(self::CHANGE_WINDOW_DAYS),
        ));
        usort($changes, fn (RecentChange $a, RecentChange $b): int => $b->at <=> $a->at);

        return array_filter([
            'lifecycle' => $this->lifecycle?->value,
            'plan' => $this->plan,
            'mrr' => $this->mrr?->toArray(),
            'customer_since' => $this->customerSince?->format('Y-m-d'),
            'seats' => $this->seats,
            'usage' => array_map(fn (UsageMeter $meter): array => $meter->toArray(), $this->usage),
            'flags' => array_map(fn (SignalFlag $flag): string => $flag->value, $this->flags),
            'recent_changes' => array_map(
                fn (RecentChange $change): array => $change->toArray(),
                array_slice($changes, 0, self::MAX_CHANGES),
            ),
            'facts' => $this->facts,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function addUsage(?UsageMeter $meter): self
    {
        if ($meter !== null && count($this->usage) < self::MAX_USAGE) {
            $this->usage[] = $meter;
        }

        return $this;
    }

    private function addChange(?RecentChange $change): self
    {
        if ($change !== null && $change->at >= Carbon::now()->subDays(self::CHANGE_WINDOW_DAYS)) {
            $this->recentChanges[] = $change;
        }

        return $this;
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }
}
