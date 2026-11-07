<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Tests;

use Cbox\AiAssistant\Connector\AiAssistantConnectorServiceProvider;
use Cbox\AiAssistant\Connector\Testing\InteractsWithAssistant;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use InteractsWithAssistant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistantSigning();
    }

    protected function getPackageProviders($app): array
    {
        return [AiAssistantConnectorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai-assistant-connector.jwks_url', 'https://platform.test/.well-known/jwks.json');
        $app['config']->set('ai-assistant-connector.audience', 'localhost');
        $app['config']->set('cache.default', 'array');
    }

    /** @param array<string, mixed> $claims */
    protected function sign(array $claims): string
    {
        return $this->assistantSignature($claims);
    }
}
