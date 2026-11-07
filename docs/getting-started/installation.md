---
title: Installation
weight: 10
description: Install the MIT client and configure its transport.
---

# Installation

Install `cboxdk/laravel-assistant-client` through Composer. Laravel discovers its provider automatically. Publish `ai-assistant-connector-config` to override routes, user resolution and transport settings.

The PHP namespace remains `Cbox\AiAssistant\Connector` to let existing connector consumers migrate without renaming imports. Replace the legacy `cbox/ai-assistant-connector` dependency; do not install both packages together.

The first public release requires PHP 8.4+, Laravel 12 or 13, a connector handshake of at least 32 bytes, explicit tool guards and short-lived signed hook tokens. Legacy calls without guards, timestamps or single-use request IDs must be updated.

See the [quickstart](../quickstart.md) and [configuration reference](../configuration/reference.md).
