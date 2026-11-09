<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Support;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * A support staff member's time-limited, reasoned elevation, carried in the signed principal as
 * `support_access: {staff_id, staff_name, reason, until}`.
 *
 * By default staff see only what the customer sees. An elevation is the verified claim that a
 * named staff member asked for more, with a written reason, until a moment at most an hour ahead.
 * The SDK never widens anything because of it: your guard and list closures decide what, if
 * anything, an elevated request may see beyond the customer's own view.
 */
readonly class SupportAccess
{
    public const MIN_REASON_LENGTH = 10;

    public const MAX_WINDOW_SECONDS = 3600;

    public function __construct(
        public string $staffId,
        public string $staffName,
        public string $reason,
        public DateTimeImmutable $until,
    ) {}

    /**
     * The claim as a valid elevation, or null: staff id and name must be present, the reason at
     * least ten characters, and `until` (ISO 8601 or a Unix timestamp) in the future and no more
     * than sixty minutes ahead.
     */
    public static function fromClaim(mixed $claim): ?self
    {
        if (! is_array($claim)) {
            return null;
        }

        $staffId = $claim['staff_id'] ?? null;
        $staffName = $claim['staff_name'] ?? null;
        $reason = is_string($claim['reason'] ?? null) ? trim($claim['reason']) : '';
        $until = self::moment($claim['until'] ?? null);

        if (! (is_string($staffId) || is_int($staffId)) || trim((string) $staffId) === ''
            || ! is_string($staffName) || trim($staffName) === ''
            || mb_strlen($reason) < self::MIN_REASON_LENGTH || $until === null) {
            return null;
        }

        $now = Carbon::now()->toDateTimeImmutable();

        if ($until <= $now || $until->getTimestamp() - $now->getTimestamp() > self::MAX_WINDOW_SECONDS) {
            return null;
        }

        return new self(trim((string) $staffId), trim($staffName), mb_substr($reason, 0, 1000), $until);
    }

    /** @return array{staff_id: string, staff_name: string, reason: string, until: string} */
    public function toArray(): array
    {
        return [
            'staff_id' => $this->staffId,
            'staff_name' => $this->staffName,
            'reason' => $this->reason,
            'until' => $this->until->format(DATE_ATOM),
        ];
    }

    private static function moment(mixed $value): ?DateTimeImmutable
    {
        try {
            return match (true) {
                is_int($value) => new DateTimeImmutable('@'.$value),
                is_string($value) && trim($value) !== '' => new DateTimeImmutable($value),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }
}
