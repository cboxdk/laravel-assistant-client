---
title: Knowledge publishing
weight: 20
description: Publish only approved customer-safe documentation.
---

# Knowledge publishing

Maintain an explicit manifest at `resources/assistant/manifest.php`:

```php
return ['getting-started' => [
    'file' => 'getting-started.en.md',
    'title' => 'Getting started',
    'path' => '/ai-assistant/guides/getting-started',
    'revision' => 1,
    'locale' => 'en',
]];
```

Add `'topics' => ['invoices.index']` to show a document under "For this page" in the shell's Help column; see [Shell Help column](shell-help.md).

Keep approved Markdown files in that directory. The SDK serves only named manifest entries at `/ai-assistant/guides/{key}`. Paths outside the directory are refused and raw HTML is stripped. These guides are public: do not include customer data, credentials or internal runbooks.

Configure `AI_ASSISTANT_SUPPORT_ASSISTANT`, `AI_ASSISTANT_SUPPORT_PRODUCT` and a server API key authorized for knowledge publishing. Run `php artisan assistant:sync-support` after deployment. The command skips disabled support and fails if an import does not acknowledge indexing.

Each document gets at most three attempts for connection failures, HTTP 408/429 and 5xx responses (including proxy errors such as 530), with 10 and 30 seconds between attempts. Each attempt has a 10-second connection timeout and a 120-second request timeout. Retries log the document key, next attempt, delay and HTTP status without credentials or document contents. Allow up to 400 seconds per document when sizing the deployment job deadline; a longer outage still fails the command.

Other 4xx responses, including authentication failures and revision conflicts (409), fail immediately. A successful HTTP response without `data.status: indexed` also fails without retrying. Exhausted retries remain errors: do not suppress the command's nonzero exit status. Once the cause is fixed, rerun the support command rather than repeating migrations or rolling the application again.

The Assistant API owns document indexing and retrieval. The same content and revision are idempotent; changed content needs an increased integer revision. A failed import retains the last successful generation. Stable document keys preserve citations across deployments.

Omitted entries are **not deleted automatically**. Explicitly remove a withdrawn source in Assistant Studio and verify it no longer appears in answers before removing its manifest entry. This release does not reconcile deletions.

For generated guides, use a reviewed source allowlist and a CI drift check. Do not crawl the whole repository. Repair relative links to published guide routes, and publish the generated content with the service release.
