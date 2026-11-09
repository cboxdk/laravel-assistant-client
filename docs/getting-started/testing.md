---
title: Testing
weight: 20
description: Use real signatures and fake transport in host authorization tests.
---

# Testing

Use `Cbox\AiAssistant\Connector\Testing\InteractsWithAssistant` in your Laravel test. Call `$this->fakeAssistantSigning()` during setup; it creates a disposable Ed25519 keypair and fakes the public JWKS endpoint. `$this->assistantSignature($claims)` signs a request with the correct audience, handshake, expiry and unique request ID.

To test the customer profile, call `$this->getAssistantProfile($principal, $query, $supportAccess)`; `$this->assistantSupportAccess()` builds a staff elevation claim. These helpers need a Laravel test case. See [Customer profile](../cookbook/customer-profile.md#testing).

Send that token as `Authorization: Bearer ...` to the real hook route. Test a permitted member, a different organization, a revoked membership, a forbidden environment and replay of the same signature. Keep the application authorization guard real.

The package uses this same trait in its suite. Run `composer qa` for formatting, maximum-level static analysis, tests, license and vulnerability checks. CI tests PHP 8.4/8.5 with Laravel 12/13. Regenerate the deterministic dependency inventory with `composer sbom`.
