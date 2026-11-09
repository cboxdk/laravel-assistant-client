# Cbox Assistant client

Product support, signed tools and curated knowledge for Laravel applications. Licensed under MIT.

```bash
composer require cboxdk/laravel-assistant-client
```

Register authorized tools in your app, connect the signed manifest in Assistant, and embed the shared support drawer in the product shell. Your app retains identity, permissions and business logic. Assistant provides AI conversations and human handover.

- [Quickstart](docs/quickstart.md)
- [Embed product support](docs/cookbook/product-support.md)
- [Publish product documentation](docs/cookbook/knowledge.md)
- [Run conversations from your backend](docs/cookbook/backend-driven.md)
- [Share the customer profile](docs/cookbook/customer-profile.md): resources, account signals and support access elevation
- [Security boundaries](docs/security/threat-model.md)
- [Testing](docs/getting-started/testing.md)
- [Requirements](docs/requirements.md)

The PHP namespace remains `Cbox\AiAssistant\Connector`. This package replaces the legacy `cbox/ai-assistant-connector` distribution. The first public release requires explicit tool guards, configured connector secrets and bounded, single-use hook signatures. See [installation](docs/getting-started/installation.md).

The SDK does not provision an Assistant workspace or deploy its server. Knowledge removal currently requires an explicit operation in Assistant; manifest omissions do not delete indexed sources.
