<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Help;

use Cbox\AiAssistant\Connector\Support\Payload;
use Cbox\AiAssistant\Connector\Support\SupportSession;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

/** {@see HelpClient} over HTTP, authenticated per call with a fresh signed identity for the person. */
class HttpHelpClient implements HelpClient
{
    public function __construct(
        private readonly SupportSession $sessions,
        private readonly int $timeout = 5,
    ) {}

    public function helpSearch(SupportUser $user, string $query, ?string $topic = null, int $limit = 5): HelpSearchResult
    {
        return HelpSearchResult::fromArray($this->get($user, '/help/search', array_filter([
            'q' => $query, 'service' => $user->service, 'topic' => $topic, 'limit' => $limit,
        ], fn (mixed $v): bool => $v !== null)));
    }

    public function topicArticles(SupportUser $user, string $topic): array
    {
        $body = $this->get($user, '/help/topics/'.rawurlencode($topic).'/articles', ['service' => $user->service]);

        return array_map(HelpArticle::fromArray(...), Payload::objects($body['data'] ?? []));
    }

    public function supportUpdates(SupportUser $user, ?DateTimeInterface $since = null, ?string $cursor = null, int $limit = 25, bool $unreadOnly = false): SupportUpdatePage
    {
        return SupportUpdatePage::fromArray($this->get($user, '/support/updates', array_filter([
            'since' => $since === null ? null : DateTimeImmutable::createFromInterface($since)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'cursor' => $cursor,
            'limit' => $limit,
            'unread' => $unreadOnly ? 1 : null,
        ], fn (mixed $v): bool => $v !== null)));
    }

    public function markRead(SupportUser $user, string $updateId): SupportUpdate
    {
        [$token, $api] = $this->credentials($user);

        return SupportUpdate::fromArray($this->decode(fn (): Response => Http::withToken($token)->acceptJson()->timeout($this->timeout)
            ->post($api.'/support/updates/'.rawurlencode($updateId).'/read')));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(SupportUser $user, string $path, array $query): array
    {
        [$token, $api] = $this->credentials($user);

        return $this->decode(fn (): Response => Http::withToken($token)->acceptJson()->timeout($this->timeout)->get($api.$path, $query));
    }

    /** @return array{0: string, 1: string} */
    private function credentials(SupportUser $user): array
    {
        $session = $this->sessions->forUser($user->subject, $user->account, $user->service, $user->context, $user->name, $user->email);

        return [$session['identity'], $session['api_url']];
    }

    /**
     * @param  callable(): Response  $send
     * @return array<string, mixed>
     */
    private function decode(callable $send): array
    {
        try {
            $response = $send();
        } catch (ConnectionException $e) {
            throw new HelpException('Cbox Assistant could not be reached.', 0);
        }
        if ($response->failed()) {
            $detail = $response->json('detail');
            throw new HelpException(is_string($detail) && $detail !== '' ? $detail : 'Cbox Assistant answered '.$response->status().'.', $response->status());
        }
        try {
            return Payload::object($response->json());
        } catch (UnexpectedValueException) {
            throw new HelpException('Cbox Assistant returned an unexpected response.', $response->status());
        }
    }
}
