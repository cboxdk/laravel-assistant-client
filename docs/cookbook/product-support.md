---
title: Product support
weight: 10
description: Embed a themed, localized conversation in the product shell.
---

# Product support

Register a distinct SDK bridge connection in Assistant. Configure an Assistant widget in that same workspace with `identity_connector_id`, the service knowledge `product_ids` and exact `allowed_origins`. Configure the host's support widget/profile/product IDs and identity namespace to match that workspace.

Expose an authenticated, rate-limited support-session endpoint. Derive every value from the current server session and resource permissions:

```php
return response()->json(app(\Cbox\AiAssistant\Connector\Support\SupportSession::class)
    ->forUser($subjectId, $accountId, 'your-service', [
        'tenant' => $localTenantId,
        'project' => $projectId,
        'environment' => $environmentKey,
    ], $displayName, $email))->header('Cache-Control', 'no-store');
```

Only a three-minute identity assertion reaches the browser. Load `/ai-assistant/support.js` once and mount `<cbox-support session-url="/your-authorized-session-route"></cbox-support>` in the global product shell. Keep it available on small screens.

Allow the exact origin returned by `SupportSession::frameOrigin()` in the host CSP's `frame-src`; `script-src` and `style-src` must allow the host's own SDK assets. The element follows the root document's `lang`, `data-theme` or dark class. Update `session-url` when subject, organization or environment changes; this closes the previous chat. Include a scope identifier in that URL if the route path stays unchanged.

To tell the chat which page the person is on, set `page-route`, `page-title` and `page-context`; see [Shell Help column](shell-help.md).

The parent accepts messages only from the configured iframe origin and window. Same-origin product links navigate in the existing window. Service assertions refresh through the host endpoint; an authorization failure closes the frame.

Before calling a rollout complete, verify light/dark themes, language changes, mobile overflow, internal navigation, organization changes, an authorized tool, a cited guide and a human reply in the same browser conversation.
