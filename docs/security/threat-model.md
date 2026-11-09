---
title: Security boundaries
weight: 10
description: What the client verifies and what the host must authorize.
---

# Security boundaries

Hook signatures use Firebase PHP-JWT with EdDSA pinned to an Ed25519 JWKS key. Audience must match the configured service host. Tokens require issued/expiry timestamps with at most a five-minute lifetime. Hook and inbox calls require a unique `jti`; read-only manifest discovery may omit it. Replay entries persist through the token's expiry plus clock leeway, using an atomic cache add. Use a shared, atomic production cache across replicas.

A required connector handshake of at least 32 bytes prevents another Assistant workspace from calling the same service using a legitimate platform signature. Protect the JWKS configuration and HTTPS transport. Refresh on an unknown key ID supports key rotation. Invalid or unavailable keys deny requests.

Signatures do not replace authorization. Each tool and inbox component requires an explicit guard returning true. Recheck subject, membership, product permissions and environment at execution time. Never derive account scope from model arguments. A signed `verified` value must be the boolean true to mark a principal verified.

The support session issuer signs a three-minute, widget-bound HS256 assertion. Keep its handshake secret and server API keys out of public output. The hosted Assistant server is responsible for validating assertions, isolating conversations, storing messages, indexing knowledge and delivering support replies.

Inbox action arguments arrive from the authorized staff interface; they still require input validation and a permission check. The signature does not make arbitrary arguments safe. Host business logic must validate tool arguments against its domain rules; exported JSON Schema is descriptive and does not substitute for those checks.

Public guides expose only the explicit manifest. Removing a file from that manifest does not remove previously indexed content from Assistant; withdrawal requires an explicit platform removal.

The customer profile endpoint is verified like discovery and may omit `jti` because it only reads; a present `jti` is still single-use. Profile resources need an explicit guard returning true, exactly like tools. Signals closures have no separate guard and must check access themselves. Items are normalised at the boundary: non-HTTPS links are dropped, text is cut and lists are capped, but the list closure still decides which records belong to the principal.

A `support_access` claim in the signed principal is exposed through `ToolContext::supportAccess()` only with a reason of at least ten characters and an expiry in the future, at most sixty minutes ahead. The SDK never widens access because of it; the service decides in its guards and closures. Every elevated request that passes a guard dispatches `SupportAccessUsed` so the service can keep its own record.

Only declare `->readOnly()` for handlers that do not change business state. Assistant may run these reads without a confirmation prompt; the service guard still runs. Omitted declarations remain subject to confirmation.
