# Changelog

## 0.2.4 — 2026-11-25

- Help in the reader's language: `SupportSession::forUser()` adds `locale` to `search_url` and `topics_url`, and `HelpClient::helpSearch()` and `topicArticles()` send it. Cbox Assistant then returns each guide in that language where the service published it in that language, and in English otherwise. Nothing changes for a service whose guides exist in one language.
- The language is the application's locale. A host whose interface language lives elsewhere passes it: `forUser(..., locale: 'da')`, or `new SupportUser(..., locale: 'da')` for the Help client. A value that is not a language tag is left out.
- Docs: how to publish a guide in several languages (one manifest document per language, with the same `path` and `topics`).
- Needs Cbox Assistant's Help API to know `locale` (live since 2026-11-25); an older Assistant ignores it.

## 0.2.3 — 2026-11-23

- `<cbox-support>`: `ask(question, page?)` opens the chat and sends the question as the person's own message once the chat is ready; `handover()` asks for a person. Both resolve `false` when the chat could not be opened, so a host keeps what was typed. `open()` resolves the same way, and `close()` hides the chat with its conversation kept.
- `inline` attribute: the chat fills the element instead of opening a dialog over the page, and Assistant's chat page is asked (`?inline=column`, or `sheet` for `inline="sheet"` on a phone) to wear the shell's surfaces, field and send button. The element emits `cbox-support:open` (`detail.inline`) and `cbox-support:close` (`detail.reason`, `"unavailable"` when the chat was lost).
- A product link in an answer raises a cancelable `cbox-support:navigate` (`detail.url`). A single-page host visits the URL itself and the chat stays open. Unhandled, the page loads as before and the chat now opens again on the next page.
- The shared shell's `intent` (JSON attribute or property) is read as the page intent; before, the chat was never told which page the shell was on.
- Needs Cbox Assistant's chat page to understand the `ask` and `handover` messages; an older page ignores them and the chat simply opens.

## 0.2.2 — 2026-11-17

- Retry knowledge publishing after connection failures, HTTP 408/429 and 5xx responses, with three attempts and 10/30-second delays. Authentication errors, revision conflicts and missing indexing acknowledgements still fail; exhausted retries still fail the sync command.
- Bound connection establishment to 10 seconds and log retry metadata without credentials or document contents. Document the deployment timeout budget and support-only recovery procedure.

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
