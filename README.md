# TheMusicDev/EdgeCache

Cache headers for a CakePHP 5 site that sits behind a CDN. **Everything is private and uncached unless a route opts in**, so a
route someone forgot to think about is slow, never leaked. Public pages are kept by Cloudflare for a year and purged when
content changes; forms, `/admin`, gated downloads and plugin routes are never cached.

Built for the layout of our hosting (Cloudflare in front of ServerByt's own CDN, see "Why two headers"), but nothing in it is
specific to one site.

## Install

```
composer require themusicdev/edge-cache
```

Load the plugin (`config/plugins.php`: `'TheMusicDev/EdgeCache' => []`), then **remove `CsrfProtectionMiddleware` from
`Application::middleware()`**: it sets a cookie on every response, and no CDN caches a response with a cookie. This plugin applies it
per route scope instead.

## Use it (config/routes.php)

```php
use TheMusicDev\EdgeCache\EdgeCache;

return function (RouteBuilder $routes): void {
    EdgeCache::register($routes);

    $routes->scope('/', function (RouteBuilder $builder): void {
        $builder->applyMiddleware('edgeForm');     // has a form: CSRF cookie + check, never cached
        $builder->connect('/contact', ...);
    });
    $routes->scope('/', function (RouteBuilder $builder): void {
        $builder->applyMiddleware('edgePublic');   // identical for every visitor: cached at the edge
        $builder->connect('/about', ...);
    });
    // A route in neither scope (health probe, redirects, plugin routes) is `private, no-store`.
};
```

Pick the scope by intent. Getting it wrong is loud: a form page in `edgePublic` throws in debug (it sets a cookie), and a form
page left in no scope fails its POST with a 403. Add a test to the host that every route is in a scope (see the reference app's
`EdgeCacheTest`).

## What it sends

| Where | Response |
|---|---|
| `edgePublic`, listed host, `GET`/`HEAD` 200 | `Cache-Control: private, no-cache`, `Cloudflare-CDN-Cache-Control: max-age=31536000`, an `ETag` (a matching `If-None-Match` gets an empty 304) |
| `edgePublic`, any other host, method or status | `Cache-Control: private, no-store` |
| `edgePublic`, response sets a cookie | debug: throws `LogicException`; production: `private, no-store` and an error in the log |
| `edgeForm` | CSRF cookie and check (Cake's `CsrfProtectionMiddleware`, options from `EdgeCache.csrf`), `private, no-store` |
| anything else | `private, no-store`, unless the response sets its own `Cache-Control` |

The last row is a middleware the plugin puts outermost, before the error handler, so error pages carry it too.

## Configure (host `config/app.php`, key `EdgeCache`; defaults in `config/app_default.php`)

```php
'EdgeCache' => [
    'hosts' => ['example.com'],   // only these hosts are made cacheable; staging and localhost stay out
    // 'public' => ['edgeTtl' => 31536000, 'browser' => 'private, no-cache', 'etag' => true],
    // 'csrf' => ['httponly' => true],
    // 'purge' => ['driver' => 'cloudflare'|'null', 'zoneId' => ..., 'token' => ...],
],
```

The purge driver turns on by itself when `CLOUDFLARE_API_TOKEN` is set (with `CLOUDFLARE_ZONE_ID`), so staging and local
development, which have no token, purge nothing. Create the token with **only** "Zone > Cache Purge" on the one zone.

## Purging

```
bin/cake edge_cache purge --all
bin/cake edge_cache purge --url /about --url https://example.com/careers/x
```

Run it from the deploy script and from a queued job (`Queue.Execute`). A refused purge exits with an error, so a queued job fails
and is retried. If a page change also needs other work first (the reference app rebuilds its Seo index), run both **in one job, in
order**: two jobs can run in parallel, and a purge that wins the race lets Cloudflare re-cache the old page for a year.

## Check the credentials

```
bin/cake edge_cache check [--url https://example.com/x]
```

Run it on the server after putting `CLOUDFLARE_API_TOKEN` and `CLOUDFLARE_ZONE_ID` in `config/.env`. It looks for the usual typing
mistakes (a quote, a space, a `Bearer ` prefix, a Global API Key pasted as a token, a zone ID of the wrong shape), asks Cloudflare
whether the token is valid, and then purges **one URL that does not exist** (`<App.fullBaseUrl>/__edge_cache_check`): harmless, and
it succeeds only if the token may purge this zone. Exit code 0 means purging works. The token is never printed. A refused purge
(HTTP 401 in the deploy) is explained: wrong or rolled token, no Cache Purge permission on this zone, a zone ID from another
account, a URL outside the zone (pass `--url`).

## One-time Cloudflare setup

Cloudflare does not cache HTML unless a Cache Rule makes it eligible. Add one for the site, scoped to pages (the app decides what
is cacheable through its headers; assets keep Cloudflare's defaults):

- When: `http.host eq "example.com" and http.request.uri.path.extension eq ""`
- Then: **Eligible for cache**; **Edge TTL: Use cache-control header if present, bypass cache if not**.

Check it with `curl -I https://example.com/about` twice: `cf-cache-status: HIT` on the second.

## Why two headers

`Cache-Control` is read by the browser and every CDN. `Cloudflare-CDN-Cache-Control` is read only by Cloudflare and not passed on.
So the browser and any other CDN in the chain (ServerByt's StackCDN sits behind Cloudflare and has account-wide settings we cannot
vary per site) are told "revalidate, do not share", while Cloudflare keeps the page until we purge it. An ETag does not extend how
long anything is trusted; it makes the browser's revalidation (answered by Cloudflare) an empty 304.

## Gotchas

- **A cookie on a response stops every CDN caching it.** Anything on a public route that starts a PHP session or sets a cookie
  (a flash message, a consent banner that sets one on the server) trips the guard. Client-side cookies are fine.
- **Public pages must be identical for every visitor**: do not print anything per user (a logged-in banner, a CSRF token) on them.
- **A `Set-Cookie` check needs Cake's cookie collection**, not the headers: until the response is emitted Cake keeps cookies in
  `getCookieCollection()`, so `hasHeader('Set-Cookie')` is false (the first version of the guard passed vacuously; a test found it).
- Not covered: static files (images, CSS, JS). Give changed files a new URL (`Asset.timestamp`) and long `Cache-Control` headers in
  `webroot/.htaccess`.
- Untested against a real StackCDN: that it honours `private`/`no-cache`; the vendor documents `no-store`. Check
  `x-cdn-cache-status` after the first deploy.
