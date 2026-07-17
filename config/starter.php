<?php

declare(strict_types=1);

/**
 * Feature flags written by `bin/setup` (host-side wizard).
 * Prefer changing them through the setup wizard rather than hand-editing.
 */
return [
    'features' => [
        'sample_api'      => (bool) env('STARTER_SAMPLE_API', true),
        'sentry'          => (bool) env('STARTER_FEATURE_SENTRY', true),
        'passport'        => (bool) env('STARTER_FEATURE_PASSPORT', true),
        'authentik'       => (bool) env('STARTER_FEATURE_AUTHENTIK', false),
        'frontend_layout' => (string) env('STARTER_FRONTEND_LAYOUT', 'monolith'),
    ],
];
