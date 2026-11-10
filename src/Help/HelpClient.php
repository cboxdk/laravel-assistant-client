<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Help;

use DateTimeInterface;

/**
 * Server-side access to the Help API the shared shell uses: docs/help-centre/knowledge search, the
 * articles for a page's help topic, and a person's support updates.
 *
 * The shell itself calls these endpoints from the browser after paint, with the session payload's
 * `identity` (see SupportSession::forUser()). Use this client from jobs, MCP tools, a mobile API or
 * tests — never from a page render, which must not wait on another service.
 */
interface HelpClient
{
    public function helpSearch(SupportUser $user, string $query, ?string $topic = null, int $limit = 5): HelpSearchResult;

    /** @return list<HelpArticle> */
    public function topicArticles(SupportUser $user, string $topic): array;

    /**
     * One article with its body (`bodyHtml`), by an id that search or a topic list returned, so the
     * Help column opens it in place. An id outside the person's scope raises a 404 HelpException.
     */
    public function article(SupportUser $user, string $id): HelpArticle;

    public function supportUpdates(SupportUser $user, ?DateTimeInterface $since = null, ?string $cursor = null, int $limit = 25, bool $unreadOnly = false): SupportUpdatePage;

    public function markRead(SupportUser $user, string $updateId): SupportUpdate;
}
