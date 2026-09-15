<?php

$devMode = (int) env('DEV_MODE', 0) === 1;

$authSubject = $devMode
    ? 'auth.testing.v1.>'
    : 'auth.v1.>';

$toolboxSubject = $devMode
    ? 'toolbox.testing.v1.>'
    : 'toolbox.v1.>';

$notificationsSubject = $devMode
    ? 'notifications.testing.v1.>'
    : 'notifications.v1.>';

return [
    'dev_mode' => $devMode,
    'host' => env('NATS_HOST', '127.0.0.1'),
    'port' => (int) env('NATS_PORT', 4222),

    'user' => env('NATS_USER'),
    'pass' => env('NATS_PASS'),
    'token' => env('NATS_TOKEN'),

    /*
     |--------------------------------------------------------------------------
     | Publishers
     |--------------------------------------------------------------------------
     | Streams THIS service publishes to. Subject -> stream is resolved by
     | JetStreamPublisher::resolvePublishTarget().
     |
     | Two of them: our own domain events, and the notification envelopes we
     | hand to NotificationsPizza. There is no HTTP endpoint for the latter —
     | publishing here IS how a notification is created.
     */
    'publishers' => [
        [
            'name' => $devMode
                ? env('NATS_TOOLBOX_STREAM', 'TOOLBOX_TESTING_EVENTS')
                : env('NATS_TOOLBOX_STREAM', 'TOOLBOX_EVENTS'),
            'subjects' => [$toolboxSubject],
        ],
        [
            'name' => $devMode
                ? env('NATS_NOTIFICATIONS_STREAM', 'NOTIFICATIONS_TESTING_EVENTS')
                : env('NATS_NOTIFICATIONS_STREAM', 'NOTIFICATIONS_EVENTS'),
            'subjects' => [$notificationsSubject],
        ],
    ],

    /**
     * Streams consumed by THIS service. Each stream gets its own durable pull
     * consumer.
     *
     * Only AUTH. Breaks are per-user and nothing here is store-scoped, so
     * stores are not replicated. The tickets slice will want them; the handler
     * pattern is identical (updateOrCreate on a replicated primary key) and
     * the auth middleware needs no change either way — its buildStoreContext()
     * reads the request, never the database.
     *
     * NOTE: streams and durable consumers are created MANUALLY via the NATS
     * CLI — the app never calls create() (see JetStreamConsumer::getOrInitConsumer).
     * Before this consumer can do anything, someone must run:
     *
     *   nats stream add TOOLBOX_EVENTS --subjects 'toolbox.v1.>'
     *   nats consumer add AUTH_EVENTS TOOLBOX_AUTH_CONSUMER --filter 'auth.v1.>' --pull
     *
     * ...plus the _TESTING_ variants when DEV_MODE=1.
     */
    'streams' => [
        [
            'name' => $devMode
                ? env('NATS_AUTH_STREAM', 'AUTH_TESTING_EVENTS')
                : env('NATS_AUTH_STREAM', 'AUTH_EVENTS'),
            'durable' => $devMode
                ? env('NATS_AUTH_DURABLE', 'TOOLBOX_AUTH_TESTING_CONSUMER')
                : env('NATS_AUTH_DURABLE', 'TOOLBOX_AUTH_CONSUMER'),
            'filter_subject' => $authSubject,
        ],
    ],

    'pull' => [
        'batch' => (int) env('NATS_PULL_BATCH', 25),
        'timeout_ms' => (int) env('NATS_PULL_TIMEOUT_MS', 2000),
        'sleep_ms' => (int) env('NATS_PULL_SLEEP_MS', 250),
    ],
];
