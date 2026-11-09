<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Profile;

use DateTimeImmutable;

/** Something that changed on the account recently ("Upgraded to Pro", "Deployed main"). */
readonly class RecentChange
{
    public const MAX_WHAT = 120;

    public function __construct(
        public string $what,
        public DateTimeImmutable $at,
        public ?string $url = null,
    ) {}

    /** Null when the text is empty or the time cannot be read. Non-HTTPS links are dropped. */
    public static function tryMake(mixed $what, mixed $at, mixed $url = null): ?self
    {
        $what = is_string($what) ? Normalize::text($what, self::MAX_WHAT) : '';
        $at = Normalize::date($at);

        return $what === '' || $at === null ? null : new self($what, $at, Normalize::httpsUrl($url));
    }

    /** @return array{what: string, at: string, url: ?string} */
    public function toArray(): array
    {
        return ['what' => $this->what, 'at' => $this->at->format(DATE_ATOM), 'url' => $this->url];
    }
}
