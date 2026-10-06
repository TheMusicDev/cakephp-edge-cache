<?php
declare(strict_types=1);

/**
 * TheMusicDev/EdgeCache ship-with defaults. Merged UNDER host values by the plugin's config/bootstrap.php, so hosts
 * win. With these defaults nothing is cached at the edge (no host is listed) and nothing is purged (no token).
 */
return [
    'EdgeCache' => [
        // Only requests for these hosts (no port) are made cacheable at the edge. Staging and localhost stay out.
        'hosts' => [],
        // What a public route (the `edgePublic` route middleware) sends.
        'public' => [
            // Cloudflare-CDN-Cache-Control max-age, seconds: how long Cloudflare keeps the page (until purged).
            'edgeTtl' => 31536000,
            // Cache-Control for the browser and every other CDN: keep it, but ask before using it.
            'browser' => 'private, no-cache',
            // An ETag from the body, so the browser's "still valid?" is answered with an empty 304.
            'etag' => true,
        ],
        // Options for Cake's CsrfProtectionMiddleware, applied by the `edgeForm` route middleware.
        'csrf' => ['httponly' => true],
        // Cloudflare purge. The driver turns on by itself when the token is set.
        'purge' => [
            'driver' => env('CLOUDFLARE_API_TOKEN') ? 'cloudflare' : 'null',
            'zoneId' => env('CLOUDFLARE_ZONE_ID'),
            'token' => env('CLOUDFLARE_API_TOKEN'),
        ],
    ],
];
