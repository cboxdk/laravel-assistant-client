---
title: Shell Help column
weight: 15
description: Feed the shared shell's Help column and palette: search, page topics, support updates, page intent and the portal link.
---

# Shell Help column

The shared Cbox shell shows a Help column and a ⌘K palette. It loads everything in the browser
after the page has painted, so a page never waits on Cbox Assistant. This package gives the shell
what it needs.

## The session payload

`SupportSession::forUser()` (see [Product support](product-support.md)) returns, next to `url`
and `identity`:

| Field | Use |
|---|---|
| `search_url` | `GET ?q=&service=&topic=` for docs, help centre and knowledge base, grouped by source. |
| `topics_url` | Replace `{topic}` with the page's help topic for its "For this page" articles (up to 3). |
| `article_url` | Replace `{id}` with a result's id to open it inside the column: the article plus `body_html` (sanitized). |
| `updates_url` | `GET` the person's support updates; `POST {updates_url}/{id}/read` marks one read. |
| `api_url` | The Assistant API base. Set `AI_ASSISTANT_API_URL` once `api.cboxassistant.com/v1` is live. |
| `portal_url` | The configured `AI_ASSISTANT_SUPPORT_PORTAL_URL`, or `null`. A starting value only; see below. |

Send `identity` as `Authorization: Bearer …` on each call. It lasts three minutes; on a 401,
fetch the session endpoint again. The organization and person come from the token, never from
the URL. Errors are `application/problem+json`. The updates list also carries a `broadcast`
descriptor to hear `support.update.created` live instead of polling.

The service cannot know whether the portal is online without calling Assistant, and a page never
does that. The live value comes from the Assistant chat session instead: once the chat has started,
`<cbox-support>` emits `cbox-support:session` again with Assistant's `portal_url`, which is `null`
while the portal is offline. The shell must always use the latest event's `portal_url` and hide
the "Support portal" link when it is `null`:

```js
document.addEventListener('cbox-support:session', (event) => {
  supportLink.hidden = !event.detail.portal_url;
  if (event.detail.portal_url) supportLink.href = event.detail.portal_url;
});
```

## Page topics

Give a manifest document the page help topics it answers:

```php
'refunds' => [
    'file' => 'refunds.en.md', 'title' => 'Refund an invoice', 'path' => '/ai-assistant/guides/refunds',
    'revision' => 2, 'locale' => 'en',
    'topics' => ['invoices.index', 'invoices.show' => 1],
],
```

A plain entry ranks the document by its place in the manifest. A `topic => position` entry sets
the position. Topics are lowercase route-name-like keys. Changing them needs a new `revision`.

## Page intent

Tell the chat which page the person is on, at start and on every navigation:

```html
<cbox-support session-url="/support/session"
  page-route="invoices.index" page-title="Invoices" page-context="Invoices · Cbox / Webshop"></cbox-support>
```

Change the attributes, call `element.setPage({ route, title, context })`, or dispatch
`new CustomEvent('cbox:page', { detail: { route, title, context } })` on `window`. Changing the
page keeps the conversation; only `session-url` starts a new one. Assistant gives the page to the
model as context, never as instructions.

## Server-side calls

For jobs, MCP tools or a mobile API, use the client. Never call it from a page render.

```php
use Cbox\AiAssistant\Connector\Facades\AiAssistant;
use Cbox\AiAssistant\Connector\Help\SupportUser;

$user = new SupportUser($person->id, $organization->id, 'billing', ['environment' => 'live']);

AiAssistant::help()->helpSearch($user, 'refund', topic: 'invoices.index');
AiAssistant::help()->topicArticles($user, 'invoices.index');
AiAssistant::help()->article($user, 'kart_…')->bodyHtml;
AiAssistant::help()->supportUpdates($user, unreadOnly: true);
AiAssistant::help()->markRead($user, 'supd_…');
```

Errors raise `HelpException` with the HTTP `status`; missing support config raises it too, with
status 0. The service key is sent lower-cased (`_` becomes `-`) and left out when it still does not
match `[a-z0-9-]{1,40}`. In tests, `AiAssistant::fakeHelp()` swaps in
an in-memory `FakeHelpClient`; see [Testing](../getting-started/testing.md).
