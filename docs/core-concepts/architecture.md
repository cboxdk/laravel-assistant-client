---
title: Architecture
weight: 10
description: Keep product authorization in the product and support in Assistant.
---

# Architecture

The registry holds tools, optional procedures, audience groups and support inbox components. The signed manifest advertises their schemas. Assistant imports these definitions and sends an Ed25519-signed request to the product when a tool is selected. A connector-specific handshake binds the call to the registered connection.

The same registry holds customer profile resources and signals closures. Assistant reads them with one signed `GET /profile` per service and combines the answers into one customer picture; see [Customer profile](../cookbook/customer-profile.md).

`PrincipalResolver` builds `ToolContext` from verified principal claims. The product guard must recheck current membership and permission before its handler reads or changes data. Signed claims establish who is asking; they do not grant access by themselves.

The hosted support mode returns a short-lived identity assertion from an authenticated product endpoint. The shared browser element opens Assistant in an iframe. Assistant validates that assertion, scopes the conversation and runs the human support workflow. Those server capabilities belong to the Assistant app, not this client package.

The backend-driven mode calls the reasoning API from your server and executes tools in the same process using the same registry and guards. It does not require inbound hooks. API keys remain on the server in both modes.
