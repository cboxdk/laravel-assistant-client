<?php

declare(strict_types=1);

return [
    /*
    | Backend-driven ("embedded") orchestration. When your app drives the loop itself via
    | AiAssistant::assistant(...)->send(...), it calls the platform's reasoning API with the
    | tenant API key below and executes tools in-process. `url` is the platform base URL;
    | `api_key` is a tenant API key (server-to-server — never ship it to a browser/device).
    */
    'platform' => [
        'url' => env('AI_ASSISTANT_PLATFORM_URL', 'https://app.example.com'),
    ],
    'api_key' => env('AI_ASSISTANT_API_KEY'),
    'timeout' => (int) env('AI_ASSISTANT_TIMEOUT', 30),
    'max_tool_rounds' => (int) env('AI_ASSISTANT_MAX_TOOL_ROUNDS', 10),

    'support' => [
        'enabled' => (bool) env('AI_ASSISTANT_SUPPORT_ENABLED', false),
        'widget' => env('AI_ASSISTANT_SUPPORT_WIDGET'),
        'assistant' => env('AI_ASSISTANT_SUPPORT_ASSISTANT'),
        'product' => env('AI_ASSISTANT_SUPPORT_PRODUCT'),
        'identity_namespace' => env('AI_ASSISTANT_IDENTITY_NAMESPACE'),
    ],

    /*
    | The platform's JWKS URL. Every hook/discovery request is signed by the platform and
    | verified against these public keys, so a request can only come from your assistant
    | platform — no shared secret to leak.
    */
    'jwks_url' => env('AI_ASSISTANT_JWKS_URL', 'https://app.example.com/.well-known/ai-assistant-jwks.json'),

    /*
    | The audience the platform binds tokens to — your own host. A token minted for another
    | audience is rejected, so a leaked token can't be replayed against you. Defaults to the
    | incoming request host.
    */
    'audience' => env('AI_ASSISTANT_AUDIENCE'),

    /*
    | Where the connector mounts its routes.
    */
    'route_prefix' => env('AI_ASSISTANT_ROUTE_PREFIX', 'ai-assistant'),
    'middleware' => ['api'],

    /*
    | Resolve the verified principal's subject (their user id in your system) to your own
    | user model, handed to guards and handlers as $ctx->user. Return null for an unknown
    | user. The default tries the app's default User model by primary key.
    */
    'resolve_user' => null, // fn (string $userId): ?object

    /*
    | Required shared secret (at least 32 bytes) that binds requests to the connector the
    | platform registered for this app. The platform-signed token must carry a
    | matching `cnt` claim or the request is refused — so a different legitimate tenant
    | hitting the same endpoint still can't enumerate or call your tools. Not required
    | for backend-driven mode, which does not expose signed hooks.
    */
    'handshake_secret' => env('AI_ASSISTANT_HANDSHAKE_SECRET'),

    /*
    | How long (seconds) a request's jti is remembered to reject replays. Should comfortably
    | exceed the platform's token lifetime (~120s).
    */
    'replay_ttl' => 300,

    /*
    | Cache TTL (seconds) for the fetched JWKS.
    */
    'jwks_cache_ttl' => 3600,
];
