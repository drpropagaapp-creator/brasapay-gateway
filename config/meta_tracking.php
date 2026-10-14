<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Meta tracking queue
    |--------------------------------------------------------------------------
    |
    | Dedicated queue for Meta Pixel CAPI events (PageView, InitiateCheckout,
    | AddPaymentInfo, Purchase, Refund). Run: php artisan queue:work --queue=meta-tracking
    |
    */

    'queue' => env('META_TRACKING_QUEUE', 'meta-tracking'),

    'graph_api_version' => env('META_GRAPH_API_VERSION', 'v21.0'),

    'debug' => filter_var(env('META_TRACKING_DEBUG', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Event toggles (browser + server mirror)
    |--------------------------------------------------------------------------
    */

    'events' => [
        'PageView' => [
            'enabled' => true,
            'browser' => true,
            'server' => true,
        ],
        'InitiateCheckout' => [
            'enabled' => true,
            'browser' => true,
            'server' => true,
        ],
        'AddPaymentInfo' => [
            'enabled' => true,
            'browser' => true,
            'server' => true,
        ],
        'Purchase' => [
            'enabled' => true,
            'browser' => true,
            'server' => true,
        ],
        'Refund' => [
            'enabled' => true,
            'browser' => false,
            'server' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | event_id templates (deduplication browser + CAPI)
    |--------------------------------------------------------------------------
    */

    'event_ids' => [
        'pageview' => 'pv:{session_token}',
        'initiate_checkout' => 'chk:{session_token}',
        'add_payment_info' => 'pay:{order_id}',
        'purchase' => 'order:{order_id}',
        'refund' => 'refund:{order_id}',
    ],

    /*
    |--------------------------------------------------------------------------
    | Job retry
    |--------------------------------------------------------------------------
    */

    'retry' => [
        'tries' => (int) env('META_TRACKING_TRIES', 8),
        'backoff' => [30, 60, 120, 300, 600, 1200, 1800],
        'timeout' => (int) env('META_TRACKING_TIMEOUT', 60),
    ],

    'http_timeout' => (int) env('META_TRACKING_HTTP_TIMEOUT', 12),

];
