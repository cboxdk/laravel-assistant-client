<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Testing;

use Cbox\AiAssistant\Connector\Help\HelpArticle;
use Cbox\AiAssistant\Connector\Help\HelpClient;
use Cbox\AiAssistant\Connector\Help\HelpException;
use Cbox\AiAssistant\Connector\Help\HelpResultGroup;
use Cbox\AiAssistant\Connector\Help\HelpSearchResult;
use Cbox\AiAssistant\Connector\Help\SupportUpdate;
use Cbox\AiAssistant\Connector\Help\SupportUpdatePage;
use Cbox\AiAssistant\Connector\Help\SupportUser;
use DateTimeInterface;
use PHPUnit\Framework\Assert;

/**
 * An in-memory {@see HelpClient} for host tests. Seed articles per source and topic and updates per
 * person + organization; it filters, scopes and records calls the way Assistant does, so a host can
 * test its Help wiring without a network:
 *
 *     $help = AiAssistant::fakeHelp();
 *     $help->addArticle(new HelpArticle(id: 'kart_1', title: 'Refunds'), topics: ['invoices.index']);
 *     $help->addUpdate($user, new SupportUpdate(id: 'supd_1', title: 'Refund'));
 */
class FakeHelpClient implements HelpClient
{
    /** @var list<array{article: HelpArticle, topics: list<string>}> */
    private array $articles = [];

    /** @var array<string, list<SupportUpdate>> */
    private array $updates = [];

    /** @var list<array{method: string, user: SupportUser, arguments: array<string, mixed>}> */
    private array $calls = [];

    /**
     * @param  list<string>  $topics  page topics this article answers, in the order given per topic
     */
    public function addArticle(HelpArticle $article, array $topics = []): self
    {
        $this->articles[] = ['article' => $article, 'topics' => $topics];

        return $this;
    }

    public function addUpdate(SupportUser $user, SupportUpdate $update): self
    {
        $this->updates[$this->key($user)][] = $update;

        return $this;
    }

    public function helpSearch(SupportUser $user, string $query, ?string $topic = null, int $limit = 5): HelpSearchResult
    {
        $this->calls[] = ['method' => 'helpSearch', 'user' => $user, 'arguments' => ['query' => $query, 'topic' => $topic, 'limit' => $limit]];
        $groups = [];
        foreach (['docs' => 'Docs', 'help_centre' => 'Help centre', 'knowledge_base' => 'Knowledge base'] as $source => $label) {
            $hits = trim($query) === '' ? [] : array_values(array_filter(array_map(fn (array $a): HelpArticle => $a['article'], $this->articles),
                fn (HelpArticle $a): bool => $a->source === $source && (stripos($a->title, $query) !== false || stripos((string) $a->excerpt, $query) !== false)));
            $groups[] = new HelpResultGroup($source, $label, array_slice($hits, 0, $limit), count($hits) > $limit);
        }

        return new HelpSearchResult($query, $groups);
    }

    public function topicArticles(SupportUser $user, string $topic): array
    {
        $this->calls[] = ['method' => 'topicArticles', 'user' => $user, 'arguments' => ['topic' => $topic]];

        return array_slice(array_values(array_map(
            fn (array $a): HelpArticle => $a['article'],
            array_filter($this->articles, fn (array $a): bool => in_array($topic, $a['topics'], true)),
        )), 0, 3);
    }

    public function supportUpdates(SupportUser $user, ?DateTimeInterface $since = null, ?string $cursor = null, int $limit = 25, bool $unreadOnly = false): SupportUpdatePage
    {
        $this->calls[] = ['method' => 'supportUpdates', 'user' => $user, 'arguments' => ['since' => $since, 'cursor' => $cursor, 'limit' => $limit, 'unread' => $unreadOnly]];
        $all = $this->updates[$this->key($user)] ?? [];
        $visible = array_values(array_filter($all, fn (SupportUpdate $u): bool => ! $unreadOnly || ! $u->isRead));

        return new SupportUpdatePage(
            array_slice($visible, 0, $limit),
            count($visible) > $limit,
            null,
            count(array_filter($all, fn (SupportUpdate $u): bool => ! $u->isRead)),
        );
    }

    public function markRead(SupportUser $user, string $updateId): SupportUpdate
    {
        $this->calls[] = ['method' => 'markRead', 'user' => $user, 'arguments' => ['id' => $updateId]];
        $found = null;
        $this->updates[$this->key($user)] = array_map(function (SupportUpdate $update) use ($updateId, &$found): SupportUpdate {
            if ($update->id !== $updateId) {
                return $update;
            }

            return $found = $update->isRead ? $update : $update->read(gmdate('Y-m-d\TH:i:s\Z'));
        }, $this->updates[$this->key($user)] ?? []);

        return $found ?? throw new HelpException('Support update not found.', 404);
    }

    /** Assert a call was made, optionally matching its arguments and person. */
    public function assertCalled(string $method, ?callable $match = null): void
    {
        $found = array_filter($this->calls, fn (array $c): bool => $c['method'] === $method && ($match === null || $match($c['arguments'], $c['user']) === true));
        Assert::assertNotEmpty($found, "Expected Help call [{$method}] was not made.");
    }

    public function assertNothingCalled(): void
    {
        Assert::assertSame([], array_column($this->calls, 'method'), 'Expected no Help calls.');
    }

    private function key(SupportUser $user): string
    {
        return $user->subject."\0".$user->account;
    }
}
