<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Help;

/**
 * The signed-in person a Help call is made for: their id, their organization, this service's key and
 * the optional tenant/project/environment references. The host decides who this is after its own
 * authentication; the client turns it into a short-lived signed assertion per call.
 *
 * `locale` is the language the person reads the service in. Search and topic lists return each
 * guide in it where the service published one; left out, the application's locale is used.
 */
readonly class SupportUser
{
    /**
     * @param  array<string, string>  $context
     */
    public function __construct(
        public string $subject,
        public string $account,
        public string $service,
        public array $context = [],
        public ?string $name = null,
        public ?string $email = null,
        public ?string $locale = null,
    ) {}
}
