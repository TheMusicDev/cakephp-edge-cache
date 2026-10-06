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

### Purge everything, or by URL?

The command and the purger offer **purge everything** and **purge by URL**. Which to use is a trade-off, not a rule:

| | Purge everything | Purge by URL |
|---|---|---|
| Correctness | cannot leave a stale page | a URL you forget stays stale for as long as the edge TTL (a year by default), silently |
| Cost | the next request for every page goes to the origin | only the pages you named |
| Cloudflare Free limit | **5 requests a minute** per account (burst of 25), shared with every zone on the account | 800 URLs a second, 100 URLs per request |

Start with everything: it is never wrong, and a small site feels the cold cache only as a few slow first requests (static files
come back from any CDN behind Cloudflare, not from PHP). Move to a narrower purge when the limit or the cold cache starts to hurt
(heavy traffic, several sites on one Cloudflare account, several editors).

Two things make a narrower purge harder than it looks:

- **Query strings are part of the cache key.** `/list`, `/list?page=2` and `/list?category=x` are different cached objects, and
  the set is open-ended, so exact URLs cannot cover a list page. Use a **prefix** purge for it, but prefix, hostname and tag purges
  are in the same 5-a-minute bucket as purge everything; only exact URLs escape it.
- **You must know every page that depends on the data that changed.** A test that fails when a page outside the purged area renders
  that data keeps the assumption honest.

Today the plugin purges everything (`edge_cache purge --all`) or exact URLs (`--url`). Prefix purge and purge by `Cache-Tag` are not
built; tags also need every CDN behind Cloudflare to pass the header through, which nobody has tested. The reference app's choice and
its reasons: `TheMusicDev/cakephp-tmd`, README (CDN / caching, "Purge strategy") and issue #49.

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

Cloudflare does not cache HTML unless a Cache Rule makes it eligible. Add **one** rule for the site (dashboard: the zone, then
Caching, then Cache Rules, then Create rule, "Cache Rules"). The app decides what is cacheable through its headers, so the rule is
broad and assets keep Cloudflare's defaults:

- **Name:** anything, for example `HTML pages: respect origin cache headers`.
- **When** (Custom filter expression, "Edit expression"): `(http.host eq "example.com" and not http.request.uri.path contains ".")`,
  every URL without a dot in its path, so pages but not files. (`http.request.uri.path.extension eq ""` looks equivalent but the
  dashboard rejects the empty string as an "Invalid value".)
- **Then:** **Eligible for cache**; **Edge TTL: Use cache-control header if present, bypass cache if not**; and
  **Browser TTL: Respect origin TTL**.

**Do not skip the Browser TTL.** Without it Cloudflare rewrites the `Cache-Control` it sends to browsers on every page it caches:
`private, no-cache` becomes `private, max-age=14400` (the zone's default 4-hour browser TTL), so a returning visitor keeps an old
page for up to 4 hours after a change, and a purge cannot reach a browser. (Found on the reference app, 2026-10-06, by comparing the
headers before and after the rule.)

Check it with `curl -sI https://example.com/about` twice: the second answer has `cf-cache-status: HIT`, and `cache-control` is still
`private, no-cache`. A form page, `/admin` and a 404 must stay `DYNAMIC` or `BYPASS`, never `HIT`.

**Cloudflare's Email Address Obfuscation** (Security, Settings) rewrites email addresses in the HTML with a random key on every
response. Because it changes the body, Cloudflare then drops the `ETag`, so browsers cannot get a cheap 304 and re-download the page.
Edge caching is unaffected; turn the setting off if you want the 304s.

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
