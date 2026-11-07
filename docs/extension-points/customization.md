---
title: Customization
weight: 10
description: Override user resolution and bind your own collaborators.
---

# Customization

Set `ai-assistant-connector.resolve_user` to a closure that resolves `principal.sub` to your local model. The default searches the configured Eloquent auth model by primary key. For Cbox ID subjects stored in a separate column, explicitly resolve that column. Missing users return null; guards must deny access accordingly.

Public registry, builder, transport and resolver classes are extendable and resolved through Laravel's container. Bind or decorate `PrincipalResolver`, `EmbeddedRunner` or `KnowledgeClient` in a host provider as needed. Do not replace signature verification with an accepting fake outside tests.

`ToolContext` and `EmbeddedReply` are immutable values with usable empty constructors for tests. `ToolContext::accountId()` exposes the authorized account claim; the host must still verify that membership is current.

The `AiAssistant` facade registers tools, procedures, groups, inbox cards and actions. Optional exported procedures and groups do not create campaigns or send messages: those capabilities belong to the appropriate host service.
