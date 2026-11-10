# Changelog

## 0.2.1 — 2026-11-10

- Shell Help column: `AiAssistant::help()` returns a `HelpClient` with `helpSearch()`, `topicArticles()`, `article()` (with sanitized `bodyHtml`), `supportUpdates()` and `markRead()` over the Assistant `/v1/help` and `/v1/support` API, typed results, and `HelpException` for problem+json errors. `AiAssistant::fakeHelp()` swaps in `FakeHelpClient` for tests.
- `SupportSession::forUser()` also returns `api_url`, `search_url`, `topics_url`, `article_url`, `updates_url` and `portal_url` (the configured `AI_ASSISTANT_SUPPORT_PORTAL_URL`; the live value arrives later on `cbox-support:session`). New config: `api_url` (`AI_ASSISTANT_API_URL`), `help_timeout`.
- Knowledge manifest documents accept `topics`; `KnowledgeClient::put()` sends them, so a page's help topic lists its articles.
- `<cbox-support>` forwards a page intent (route, title, context) when the chat starts and on every change, from `page-*` attributes, `setPage()` or a `cbox:page` window event, and emits `cbox-support:session` with the Help URLs, and again with Assistant's live `portal_url` once the chat has started.
- Missing support config raises `HelpException` (status 0) from the Help client, and the `service` key is lower-cased or left out when Assistant would reject it.

## 0.2.0 — 2026-11-09

- Customer profile: `AiAssistant::resource()` declares what a customer has in the service (sites, domains, invoices…) with deny-by-default guards, normalised `ResourceItem`s and a 50-item cap; `pickable()` resources can be offered on support request forms.
- Account signals: `AiAssistant::signals()` with a typed vocabulary (`Signals`, `Lifecycle`, `SignalFlag`, `Mrr`, `UsageMeter`, `RecentChange`); invalid values and unknown keys are dropped and several closures are merged.
- New signed endpoint `GET /ai-assistant/profile`. One failing resource is reported and served as unavailable; the others still return.
- The manifest adds `resources` and `signals`. Existing keys are unchanged.
- Support access elevation: `ToolContext::supportAccess()` exposes a valid, reasoned, time-limited staff elevation from the signed principal, and `SupportAccessUsed` is dispatched when an elevated request reaches the profile, a tool or an inbox component. The SDK never widens access by itself.
- Testing: `getAssistantProfile()` and `assistantSupportAccess()` in `InteractsWithAssistant`.

## 0.1.1 — 2026-11-07

- Follow the operating system's color scheme when the host has no explicit theme preference.
- Update an open support chat when the system theme changes, without reloading its conversation.

## 0.1.0 — 2026-11-07

- First public MIT release of the Cbox Assistant client.
- Signed SDK bridge discovery and tools, deny-by-default guards and support inbox components.
- Product-wide support drawer with authenticated session refresh, locale and theme propagation, and scoped navigation.
- Curated public guides and versioned knowledge synchronization on deployment.
- Backend-driven conversations with account/product/environment context and bounded local tool execution.
- PHP 8.4/8.5 and Laravel 12/13 compatibility gates, authorization test helpers and deterministic dependency inventory.
