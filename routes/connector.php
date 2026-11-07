<?php

declare(strict_types=1);

use Cbox\AiAssistant\Connector\Http\HookController;
use Cbox\AiAssistant\Connector\Http\InboxComponentController;
use Cbox\AiAssistant\Connector\Http\ManifestController;
use Cbox\AiAssistant\Connector\Http\SupportGuideController;
use Illuminate\Support\Facades\Route;

Route::get('/support.js', static fn () => response(
    file_get_contents(__DIR__.'/../resources/support.js'),
    200,
    ['Content-Type' => 'application/javascript', 'Cache-Control' => 'public, max-age=300'],
))->name('ai-assistant-connector.support-script');

Route::get('/support.css', static fn () => response(
    file_get_contents(__DIR__.'/../resources/support.css'),
    200,
    ['Content-Type' => 'text/css', 'Cache-Control' => 'public, max-age=300'],
))->name('ai-assistant-connector.support-style');
Route::get('/guides/{key}', SupportGuideController::class)
    ->where('key', '[a-zA-Z0-9_-]+')->name('ai-assistant-connector.guide');

Route::post('/hook', HookController::class)->name('ai-assistant-connector.hook');
Route::get('/manifest', ManifestController::class)->name('ai-assistant-connector.manifest');

// The support-inbox surface: hydrate a card (GET) or fire a confirm-gated action (POST). Both are
// platform-signed + verified exactly like the tool hook.
Route::match(['get', 'post'], '/inbox/{key}', InboxComponentController::class)->name('ai-assistant-connector.inbox');
