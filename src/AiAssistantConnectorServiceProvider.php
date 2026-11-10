<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector;

use Cbox\AiAssistant\Connector\Embedded\EmbeddedRunner;
use Cbox\AiAssistant\Connector\Help\HelpClient;
use Cbox\AiAssistant\Connector\Help\HttpHelpClient;
use Cbox\AiAssistant\Connector\Knowledge\SyncSupportKnowledge;
use Cbox\AiAssistant\Connector\Support\Configuration;
use Cbox\AiAssistant\Connector\Support\PrincipalResolver;
use Cbox\AiAssistant\Connector\Support\SupportSession;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the connector into a host Laravel app: a singleton registry + manager (so tool
 * definitions survive the request), the config, and the hook + manifest routes under the
 * configured prefix.
 */
class AiAssistantConnectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-assistant-connector.php', 'ai-assistant-connector');

        $this->app->singleton(ToolRegistry::class);
        $this->app->singleton(PrincipalResolver::class);
        $this->app->singleton(EmbeddedRunner::class, fn (Application $app): EmbeddedRunner => new EmbeddedRunner(
            $app->make(ToolRegistry::class),
            $app->make(PrincipalResolver::class),
            $app->make(Repository::class),
        ));
        $this->app->bind(HelpClient::class, fn (Application $app): HelpClient => new HttpHelpClient(
            $app->make(SupportSession::class),
            Configuration::positiveInteger($app->make(Repository::class), 'ai-assistant-connector.help_timeout', 5),
        ));
        $this->app->singleton(ConnectorManager::class, fn (Application $app): ConnectorManager => new ConnectorManager(
            $app->make(ToolRegistry::class),
            $app->make(EmbeddedRunner::class),
        ));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'assistant-connector');
        if ($this->app->runningInConsole()) {
            $this->commands([SyncSupportKnowledge::class]);
        }
        $this->publishes([
            __DIR__.'/../config/ai-assistant-connector.php' => config_path('ai-assistant-connector.php'),
        ], 'ai-assistant-connector-config');

        Route::group([
            'prefix' => config('ai-assistant-connector.route_prefix', 'ai-assistant'),
            'middleware' => config('ai-assistant-connector.middleware', ['api']),
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/connector.php');
        });
    }
}
