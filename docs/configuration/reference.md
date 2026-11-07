---
title: Configuration reference
weight: 10
description: Server transport, routing and hosted support settings.
---

# Configuration reference

Publish `ai-assistant-connector-config` for the full annotated configuration. All secrets belong on the server.

| Environment variable | Purpose |
|---|---|
| `AI_ASSISTANT_PLATFORM_URL` | HTTPS Assistant origin; HTTP is allowed only on localhost for support development. |
| `AI_ASSISTANT_JWKS_URL` | Trusted platform signing-key endpoint. |
| `AI_ASSISTANT_AUDIENCE` | Exact service host accepted by the signed hook. |
| `AI_ASSISTANT_HANDSHAKE_SECRET` | Connector secret, at least 32 bytes. |
| `AI_ASSISTANT_API_KEY` | Server API access for reasoning and approved knowledge publishing. |
| `AI_ASSISTANT_ROUTE_PREFIX` | SDK route prefix; default `ai-assistant`. |
| `AI_ASSISTANT_TIMEOUT` | Reasoning timeout in seconds; default 30. |
| `AI_ASSISTANT_MAX_TOOL_ROUNDS` | Maximum local tool rounds; default 10. |
| `AI_ASSISTANT_SUPPORT_ENABLED` | Enable support session issuance and deployment knowledge sync; default false. |
| `AI_ASSISTANT_SUPPORT_WIDGET` | Service widget key. |
| `AI_ASSISTANT_SUPPORT_ASSISTANT` | Published support profile key. |
| `AI_ASSISTANT_SUPPORT_PRODUCT` | Product knowledge ID. |
| `AI_ASSISTANT_IDENTITY_NAMESPACE` | Namespace agreed with the support workspace's identity integration. |

Config-only keys include `resolve_user`, `middleware`, `replay_ttl` and `jwks_cache_ttl`. Do not shorten replay storage below token validity. The verifier enforces that minimum even if a smaller value is configured.
