# Decisions

Record of why the plugin looks the way it does (design discussion with the maintainer, 2026-10-05/06, reference app
`TheMusicDev/cakephp-tmd`).

- **Default deny.** Anything that does not opt in is `private, no-store`. A route-scope allow-list would fail silently
  in the safe direction (a forgotten page is just not cached); a deny-list fails in the leaking direction (a gated download, or
  a page with a CSRF token, kept by a CDN). It also covers plugin routes the host cannot scope.
- **Two scopes, chosen by intent, instead of a component or helper.** The CSRF cookie is set by middleware before any
  controller or component runs, so a component cannot prevent it. A form page in the wrong scope fails loudly (403, or the
  guard's exception), a junior cannot forget a whitelist in `Application.php`.
- **Cloudflare is the HTML cache; its header is the only one that says "keep".** ServerByt's CDN settings are per hosting
  package (shared with other sites) and its only purge is a panel button, so it must be kept out of HTML per response.
- **ETag, not Last-Modified**: pages are rendered, so there is no honest modification time; an ETag from the body is.
- **Purge through a console command**, one code path for the deploy script, the queue and a person.
- **No per-path TTL table, no cache tags, no StackCDN driver (yet).** One TTL for all public routes. Cache tags (purge by tag is
  free on Cloudflare) need StackCDN to pass `Cache-Tag` through, which nobody has tested. StackCDN has no purge API for us.
- **No `guard` or `queue` config keys.** The guard's behaviour follows `debug`; queuing is the host's business.
- **Purge everything is the default we recommend; by URL is the optimisation (2026-10-06, from the reference app's first deploy).**
  Purging too little is silent and lasts as long as the edge TTL (a year); purging too much is a few slow first requests. Cloudflare
  Free: everything, hostname, tag and prefix share 5 requests a minute per account (burst 25); single URLs get 800 a second, so a
  precise purge only escapes the limit when every URL can be listed, which a list page with `?page=`/filter variants cannot.
  So the plugin ships `--all` and `--url`; **prefix purge is the likely next addition** (one request, covers query strings), tags only
  after a CDN in the chain is shown to pass `Cache-Tag`. The README has the comparison; the reference app tracks its move in issue #49.
