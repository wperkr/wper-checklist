=== WPER Checklist ===
Contributors: wper
Tags: health check, performance, seo, security, diagnostics
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPL-3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.html

WordPress site health diagnostics — response time, database queries, SEO, vulnerabilities and server configuration, scored out of 1000.

== Description ==

From **WPER › Site health** in the admin menu, one button checks five areas. (WPER plugins gather under a shared WPER top-level menu.)

* **Response time** — landing, archive, post and page measured three times, judged on the median (never a single reading)
* **Database queries** — query count, database time, slow and duplicate queries, object cache, on the uncached path
* **SEO** — 14 static HTML checks in the spirit of a Lighthouse SEO audit (title, description, canonical, structured data, sitemap and more)
* **WordPress vulnerabilities** — known CVEs for core, plugins and themes (wpvulnerability.net) plus front-end JS libraries and exposure probes
* **Server configuration** — PHP, wp-config, security headers, sensitive paths, database variables

Scored out of 1000. **Anything that could not be measured is reported as "unverifiable" and dropped from the score denominator** — an unmeasured item is never reported as a pass or a failure.

The **[WPER Recommendation]** button expands a detailed report with a written cause and fix for every failing item.

WP-CLI: `wp wper checklist run [--format=json]`, `wp wper checklist history`

== Languages ==

The plugin ships a standard gettext language pack and **follows the site (or user) locale — there is no language switch to set**.

* Source strings are English; `languages/wper-checklist-ko_KR.mo` supplies Korean
* Bundled: Korean (ko_KR) and English (en_US), plus `wper-checklist.pot` for new translations
* Because it is a normal text domain, translation tools such as Loco Translate, Poedit and Weglot work without any extra setup — add a `.po`/`.mo` pair for your locale and it is picked up

A note on stored reports: item labels are re-rendered from a stable key, so past runs display in your current language. The measured values (`574ms (median of 3 · HTTP 200)`) are composed at scan time and stay in the language the scan ran in — they are a record of what was observed then, not a re-translatable phrase.

== Requirements ==

* PHP 8.1 or newer
* Diagnostics use loopback HTTP requests to your own site, so **PHP-FPM needs at least 2 workers** (`pm.max_children >= 2`). With a single worker the request waits on itself and times out
* If you run an nginx FastCGI cache, add the `wper-checklist/v1` REST path to the cache bypass rules
* Vulnerability lookups use the free wpvulnerability.net API (no key required). Where outbound requests are blocked, those items are reported as "unverifiable"

== Privacy ==

* Diagnostic results are stored only in your own site database (the most recent 20 runs)
* The only outbound data is the slug and version sent to wpvulnerability.net for vulnerability lookups
* The database capture reports **query counts and timings only** — SQL text never leaves the server

== Changelog ==

= 1.1.0 =
* Replaced the bundled custom translation layer with a standard gettext language pack (`.pot` / `.po` / `.mo`), so the plugin now works with Loco Translate, Poedit, Weglot and any normal WordPress translation workflow
* Removed the KO/EN report toggle — the report now follows the site locale, and the button reads simply **WPER Recommendation**
* Source strings are English; Korean (ko_KR) and English (en_US) packs are bundled
* Item labels in stored reports are re-rendered from their key, so past runs display in the current language

= 1.0.0 =
* First release — five areas, 1000-point diagnostics, Korean/English report
