---
title: Backend-driven conversations
weight: 30
description: Run tools locally while Assistant handles reasoning.
---

# Backend-driven conversations

```php
$reply = AiAssistant::assistant('support')
    ->as($authenticatedUser)
    ->forAccount($authorizedAccountId)
    ->forProduct($productId)
    ->inEnvironment($environmentKey)
    ->locale('da')
    ->conversation($conversationId)
    ->send($question);
```

The reply exposes `content`, `conversationId` and `toolRounds`. Tools run locally with their registered guard; unknown, denied or failed tools return structured errors to the reasoning API. A bounded round count prevents endless tool loops.

Set `AI_ASSISTANT_PLATFORM_URL` and `AI_ASSISTANT_API_KEY` on the backend. Never expose that key to a browser. Pass identity and scope from trusted application state on every turn, including continuation. Assistant's server enforces conversation ownership; this client sends the same principal during tool-result continuation.

A backend-driven feature on one screen does not embed product support elsewhere. Use the [shared support element](product-support.md) for the product-wide entry point.
