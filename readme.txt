=== WPER Checklist ===
Contributors: wper
Tags: health check, performance, seo, security, diagnostics
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.2.0
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

== Extending ==

Since 1.2.0 the runner is a host, not a fixed program: what gets measured, how it is grouped and how it is scored are all replaceable from outside. **The plugin itself knows nothing about any extension** — with none installed, nothing changes.

* `wper_checklist_categories` (`$categories`, `$context`) — the `key => label` map. **The number of categories is the scoring scale**: each is worth `1000 / count`, so five areas are 200 each and four are 250 each; the total is always 1000. It receives the run context, so a stored report is always re-scored with the layout it actually ran under
* `wper_checklist_urls` (`$urls`, `$args`) — the targets to measure
* `wper_checklist_manifest` (`$steps`, `$urls`, `$args`) — the steps to run
* `wper_checklist_run_context` (`$context`, `$urls`, `$args`) — add your own keys to the stored run; `urls` and `manifest` are restored afterwards
* `wper_checklist_dispatch` (`$map`, `$step_id`) — register a step prefix against your own check class (any class with a static `run( $step_id, $ctx )`). An unknown class is dropped, never fatal
* `wper_checklist_item_labels` (`$labels`) — labels for your item keys, so stored runs re-render in the reader's language
* `wper_checklist_recommendations` (`$recommendations`) — cause/fix copy for your item keys
* `wper_checklist_run_title` (`$title`, `$context`) — name the run in the history list
* `do_action( 'wper_checklist_before_stage' )` — markup slot on the admin screen, just before the start button. `.wrap.wper-checklist` is safe to treat as a positioning context

A check returns `[ 'items' => [...], 'events' => [...], 'done' => bool, 'cursor' => int|null ]`; build items with `wper_checklist_item( $key, $cat, $label, $status, $weight, $measured, $raw, $earned )`. Item weights are relative *within* a category — they do not need to add up to the category maximum.

== Changelog ==

= 1.2.0 =
* **Fixed: core vulnerability lookups never matched anything.** The `core/{version}` endpoint is queried per version and returns only what affects it, so its entries carry no version range — but the client applied the plugin-style range filter anyway and dropped every one. Any WordPress version therefore reported "no matching CVE". Verified against the API: 4.7 returns 362 entries, 6.8 returns 22, 7.1 returns 0
* Vulnerability summaries fall back to the advisory title instead of the queried version number, so a core finding no longer reads "4.7, 4.7, 4.7"
* The screen's summary line is composed from the category list rather than naming five fixed areas, so it cannot contradict the scorecard below it
* Added an extension API: categories, targets, step manifest, dispatch, labels and recommendations are all filterable, and the admin screen has a markup slot
* Category scoring is no longer fixed at five areas × 200 — the maximum is derived from the category count, so a four-area layout scores 250 each and still totals 1000
* Reports are scored and labelled with **the context of the run being viewed**, not the current configuration, so past runs cannot change score when the setup changes
* The report and the CLI now name the address that was actually measured instead of assuming the current site
* The screen is one panel with three bands — masthead, summary, detail — instead of loose blocks on the wp-admin canvas. Admin notices land inside the masthead, and the score ring, category cards and detail toggle read as a single unit

= 1.1.0 =
* Replaced the bundled custom translation layer with a standard gettext language pack (`.pot` / `.po` / `.mo`), so the plugin now works with Loco Translate, Poedit, Weglot and any normal WordPress translation workflow
* Removed the KO/EN report toggle — the report now follows the site locale, and the button reads simply **WPER Recommendation**
* Source strings are English; Korean (ko_KR) and English (en_US) packs are bundled
* Item labels in stored reports are re-rendered from their key, so past runs display in the current language

= 1.0.0 =
* First release — five areas, 1000-point diagnostics, Korean/English report
