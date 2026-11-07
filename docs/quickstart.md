---
title: Quickstart
weight: 2
description: Register one authorized tool and connect its signed manifest.
---

# Quickstart

```bash
composer require cboxdk/laravel-assistant-client
php artisan vendor:publish --tag=ai-assistant-connector-config
```

In Assistant, create an SDK bridge connection for your product. Configure its distinct handshake secret and exact audience on the product server:

```dotenv
AI_ASSISTANT_PLATFORM_URL=https://app.cboxassistant.com
AI_ASSISTANT_JWKS_URL=https://app.cboxassistant.com/.well-known/ai-assistant-jwks.json
AI_ASSISTANT_AUDIENCE=your-product.example
AI_ASSISTANT_HANDSHAKE_SECRET=replace-with-at-least-32-random-bytes
```

Register a tool from a service provider:

```php
use Cbox\AiAssistant\Connector\Facades\AiAssistant;
use Cbox\AiAssistant\Connector\Support\ToolContext;

AiAssistant::tool('workspace_status')
    ->description('Read the current workspace status')
    ->parameters([])
    ->guard(fn (ToolContext $ctx): bool => $ctx->verified
        && app(WorkspaceAccess::class)->canRead($ctx->userId(), $ctx->accountId()))
    ->handle(fn (array $arguments, ToolContext $ctx): array =>
        app(WorkspaceStatus::class)->forAccount($ctx->accountId()));
```

`WorkspaceAccess` and `WorkspaceStatus` are your application services. Recheck membership on every call. Resolve the subject by Cbox ID identity where local primary keys differ. An omitted guard denies execution.

Point the Assistant connection at `https://your-product.example/ai-assistant/manifest`, then synchronize. A signed call goes to `/ai-assistant/hook`; unsigned requests receive 401. Select the imported tool in the Assistant profile.

Next, [embed product support](cookbook/product-support.md) and [publish approved guides](cookbook/knowledge.md). Installing the package alone does not provision the Assistant workspace, product, widget or API key.
