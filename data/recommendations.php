<?php
/**
 * 규칙 기반 권장 조치 — 항목 키 → [ 원인, 해결 ].
 *
 * fail/warn 항목에만 표출된다. AI 생성이 아니라 사전 작성 문안 — 같은 진단은
 * 언제나 같은 조치를 말한다 (재현 가능성이 신뢰의 근거다).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	/* ---------------------------------------------------------- ① 응답 속도 */
	'latency:home'       => [
		'cause' => __( 'The landing page is likely executing PHP and DB queries on every request — there is no page cache (e.g. nginx FastCGI) or its miss rate is high.', 'wper-checklist' ),
		'fix'   => __( 'Enable a server-layer page cache (nginx FastCGI cache) together with an object cache (Redis) and OPcache. Avoid stacking cache plugins.', 'wper-checklist' ),
	],
	'latency:archive'    => [
		'cause' => __( 'Archive listing queries are heavy and slow down easily without caching.', 'wper-checklist' ),
		'fix'   => __( 'Include archives in the page cache scope and verify listing queries use indexes.', 'wper-checklist' ),
	],
	'latency:single'     => [
		'cause' => __( 'Post rendering is uncached, or related-post/widget queries are heavy.', 'wper-checklist' ),
		'fix'   => __( 'Apply page caching and audit the query cost of related-post plugins and sidebar widgets.', 'wper-checklist' ),
	],
	'latency:page'       => [
		'cause' => __( 'Static pages are rendered on every request without caching.', 'wper-checklist' ),
		'fix'   => __( 'Check the page cache coverage.', 'wper-checklist' ),
	],
	'latency:product'    => [
		'cause' => __( 'Product/service pages tend to be excluded from caching due to dynamic elements.', 'wper-checklist' ),
		'fix'   => __( 'Exclude only personalized fragments (cart etc.) and cache the rest.', 'wper-checklist' ),
	],
	'latency:stability'  => [
		'cause' => __( 'Measurements vary widely — fluctuating server load, cache-miss mixing, or resource contention is suspected.', 'wper-checklist' ),
		'fix'   => __( 'Re-measure during low traffic; if the spread persists, inspect server resources (CPU/memory) and co-hosted workloads.', 'wper-checklist' ),
	],
	'latency:size'       => [
		'cause' => __( 'The HTML payload is large — excessive inline CSS/JS or page-builder markup bloat are the usual causes.', 'wper-checklist' ),
		'fix'   => __( 'Move unnecessary inline assets to external files and verify gzip/brotli compression.', 'wper-checklist' ),
	],
	'latency:redirects'  => [
		'cause' => __( 'Every redirect hop adds a round trip and increases perceived latency.', 'wper-checklist' ),
		'fix'   => __( 'Point internal links and canonicals at the final URL (www vs non-www, trailing slash).', 'wper-checklist' ),
	],

	/* ------------------------------------------------------------ ② DB 쿼리 */
	'db:home'            => [
		'cause' => __( 'Too many queries for one landing view — widget, menu, and option lookups are likely repeating uncached.', 'wper-checklist' ),
		'fix'   => __( 'Introduce a Redis object cache and use Query Monitor to find and reduce hotspots.', 'wper-checklist' ),
	],
	'db:single'          => [
		'cause' => __( 'Post pages issue many queries — related-post, view-counter, and comment plugins are common causes.', 'wper-checklist' ),
		'fix'   => __( 'Attribute queries per plugin and wrap cacheable lookups in transients.', 'wper-checklist' ),
	],
	'db:archive'         => [
		'cause' => __( 'The archive loop may run extra queries per post (N+1).', 'wper-checklist' ),
		'fix'   => __( 'Check update_post_meta_cache / update_post_term_cache and per-item lookups inside the loop.', 'wper-checklist' ),
	],
	'db:time'            => [
		'cause' => __( 'DB time is high — slow queries or latency on the DB server itself.', 'wper-checklist' ),
		'fix'   => __( 'Enable the slow query log to pinpoint offenders, then tune indexes and buffer pool size.', 'wper-checklist' ),
	],
	'db:slow'            => [
		'cause' => __( 'Queries exceed 20ms — index misses or full scans on large tables are suspected.', 'wper-checklist' ),
		'fix'   => __( 'Inspect the plan with EXPLAIN and add the missing indexes.', 'wper-checklist' ),
	],
	'db:dupes'           => [
		'cause' => __( 'Identically-shaped queries repeat within one request — per-item lookups inside a loop (N+1).', 'wper-checklist' ),
		'fix'   => __( 'Batch the repeated lookups or absorb them with the object cache.', 'wper-checklist' ),
	],
	'db:objcache'        => [
		'cause' => __( 'Without an external object cache, repeated queries hit the DB every time.', 'wper-checklist' ),
		'fix'   => __( 'Run a Redis server and enable the drop-in via the redis-cache plugin (wp redis enable).', 'wper-checklist' ),
	],

	/* --------------------------------------------------------------- ③ SEO */
	'seo:title'          => [
		'cause' => __( 'The document title is missing or outside the recommended 10–60 characters — search results truncate or ignore it.', 'wper-checklist' ),
		'fix'   => __( 'Configure title templates in your SEO plugin (e.g. Rank Math).', 'wper-checklist' ),
	],
	'seo:description'    => [
		'cause' => __( 'Without a meta description, search engines excerpt arbitrarily — you lose control of the click-through pitch.', 'wper-checklist' ),
		'fix'   => __( 'Write 50–160 character summaries yourself, starting with key pages.', 'wper-checklist' ),
	],
	'seo:h1'             => [
		'cause' => __( 'A missing or duplicated H1 blurs the page topic signal.', 'wper-checklist' ),
		'fix'   => __( 'Keep one H1 per page and demote the rest to H2 and below.', 'wper-checklist' ),
	],
	'seo:indexable'      => [
		'cause' => __( 'The landing page is blocked by noindex or robots.txt — it cannot appear in search at all.', 'wper-checklist' ),
		'fix'   => __( 'If unintended, check Settings › Reading discourage-search option and your SEO plugin robots settings.', 'wper-checklist' ),
	],
	'seo:canonical'      => [
		'cause' => __( 'A missing or off-target canonical causes duplicate-content judgments and diluted authority.', 'wper-checklist' ),
		'fix'   => __( 'Verify your SEO plugin outputs a self-referential canonical.', 'wper-checklist' ),
	],
	'seo:lang'           => [
		'cause' => __( 'Without html lang, search engines and screen readers must guess the language.', 'wper-checklist' ),
		'fix'   => __( 'Check the theme outputs <html <?php language_attributes(); ?>>.', 'wper-checklist' ),
	],
	'seo:viewport'       => [
		'cause' => __( 'Without a viewport meta the desktop layout renders shrunken on mobile — failing mobile-friendliness.', 'wper-checklist' ),
		'fix'   => __( 'Add the standard viewport meta to the theme head.', 'wper-checklist' ),
	],
	'seo:alt'            => [
		'cause' => __( 'Images without alt are excluded from image search and cost accessibility points.', 'wper-checklist' ),
		'fix'   => __( 'Fill in alt text starting with meaningful images in the media library. Decorative images should carry an empty alt="".', 'wper-checklist' ),
	],
	'seo:linktext'       => [
		'cause' => __( 'Links without text tell crawlers and screen readers nothing about the destination.', 'wper-checklist' ),
		'fix'   => __( 'Give icon links an aria-label or visually hidden text.', 'wper-checklist' ),
	],
	'seo:jsonld'         => [
		'cause' => __( 'Without structured data the site is excluded from rich results (FAQ, ratings, breadcrumbs).', 'wper-checklist' ),
		'fix'   => __( 'Enable your SEO plugin schema features and emit Article, FAQPage, BreadcrumbList.', 'wper-checklist' ),
	],
	'seo:hreflang'       => [
		'cause' => __( 'If you have multilingual pages without hreflang, the wrong language version may surface.', 'wper-checklist' ),
		'fix'   => __( 'No action needed for single-language sites. For multilingual sites, declare reciprocal alternates plus x-default.', 'wper-checklist' ),
	],
	'seo:og'             => [
		'cause' => __( 'Without OG meta, shares on social media pick arbitrary titles and images.', 'wper-checklist' ),
		'fix'   => __( 'Enable social meta output in your SEO plugin and set a default image.', 'wper-checklist' ),
	],
	'seo:https'          => [
		'cause' => __( 'An http site — or https pages referencing http assets — triggers browser warnings and ranking penalties.', 'wper-checklist' ),
		'fix'   => __( 'Install a TLS certificate and bulk-replace http:// references in the DB (wp search-replace).', 'wper-checklist' ),
	],
	'seo:sitemap'        => [
		'cause' => __( 'A missing sitemap (or one absent from robots.txt) slows discovery of new content.', 'wper-checklist' ),
		'fix'   => __( 'Enable the SEO plugin sitemap, add a Sitemap: line to robots.txt, and submit it to Search Console.', 'wper-checklist' ),
	],

	/* ----------------------------------------------------------- ④ 취약점 */
	'vuln:core'          => [
		'cause' => __( 'Your core version has published vulnerabilities — public CVEs are the first target of automated scanners.', 'wper-checklist' ),
		'fix'   => __( 'Update core immediately after staging verification. Mitigate with WAF rules until then.', 'wper-checklist' ),
	],
	'vuln:core-latest'   => [
		'cause' => __( 'Core is outdated — the version is public, making known flaws targetable.', 'wper-checklist' ),
		'fix'   => __( 'Back up, then update to the latest version.', 'wper-checklist' ),
	],
	'vuln:plugins'       => [
		'cause' => __( 'A plugin with known vulnerabilities is active — the most common WordPress breach entry point.', 'wper-checklist' ),
		'fix'   => __( 'Update it immediately; if no fixed release exists, deactivate and find a replacement.', 'wper-checklist' ),
	],
	'vuln:plugin-updates' => [
		'cause' => __( 'For outdated plugins, the published changelog doubles as an attack manual.', 'wper-checklist' ),
		'fix'   => __( 'Establish a recurring update routine: verify on staging, then roll to production.', 'wper-checklist' ),
	],
	'vuln:themes'        => [
		'cause' => __( 'The theme has known vulnerabilities.', 'wper-checklist' ),
		'fix'   => __( 'Update the theme, or if it is abandoned, consider switching to a maintained one.', 'wper-checklist' ),
	],
	'vuln:theme-updates' => [
		'cause' => __( 'Theme updates are pending.', 'wper-checklist' ),
		'fix'   => __( 'Keeping customizations in a child theme lets you update the parent safely.', 'wper-checklist' ),
	],
	'vuln:js'            => [
		'cause' => __( 'A front-end JS library with known vulnerabilities (XSS etc.) is being loaded.', 'wper-checklist' ),
		'fix'   => __( 'Update the theme/plugin loading it. Core-bundled jQuery is fixed by updating core.', 'wper-checklist' ),
	],
	'vuln:author'        => [
		'cause' => __( '?author=N redirects hand out login usernames — giving attackers half of a brute-force pair for free.', 'wper-checklist' ),
		'fix'   => __( 'Block author-scan redirects at the web server or via security configuration.', 'wper-checklist' ),
	],
	'vuln:xmlrpc'        => [
		'cause' => __( 'xmlrpc.php enables amplified login attempts (system.multicall) and pingback DDoS.', 'wper-checklist' ),
		'fix'   => __( 'If no app depends on it, block xmlrpc.php at the web server.', 'wper-checklist' ),
	],
	'vuln:users'         => [
		'cause' => __( 'The REST users endpoint is open to unauthenticated requests, enumerating login names.', 'wper-checklist' ),
		'fix'   => __( 'Block unauthenticated /wp-json/wp/v2/users at the web server or require authentication.', 'wper-checklist' ),
	],

	/* --------------------------------------------------------- ⑤ 서버 설정 */
	'server:php-version' => [
		'cause' => __( 'PHP past (or near) end of security support receives no fixes for new vulnerabilities.', 'wper-checklist' ),
		'fix'   => __( 'Upgrade to a supported PHP branch. Verify compatibility on staging first.', 'wper-checklist' ),
	],
	'server:expose-php'  => [
		'cause' => __( 'X-Powered-By exposes the PHP version, inviting version-specific attacks.', 'wper-checklist' ),
		'fix'   => __( 'Set expose_php = Off in php.ini.', 'wper-checklist' ),
	],
	'server:disable-functions' => [
		'cause' => __( 'With shell-execution functions open, one code injection becomes full server takeover.', 'wper-checklist' ),
		'fix'   => __( 'Add exec, shell_exec, system, passthru, proc_open, popen to disable_functions in php.ini.', 'wper-checklist' ),
	],
	'server:curl-alive'  => [
		'cause' => __( 'Disabling curl_exec kills all WordPress outbound HTTP (core updates, plugin installs, external APIs) — the classic copy-pasted-blocklist accident.', 'wper-checklist' ),
		'fix'   => __( 'Remove curl_exec from disable_functions.', 'wper-checklist' ),
	],
	'server:url-fopen'   => [
		'cause' => __( 'With allow_url_fopen on, file functions can open remote URLs, widening the remote-inclusion attack surface.', 'wper-checklist' ),
		'fix'   => __( 'Set allow_url_fopen = Off in php.ini. WordPress uses cURL, so nothing breaks.', 'wper-checklist' ),
	],
	'server:display-errors' => [
		'cause' => __( 'Error messages shown to visitors leak paths, queries, and internal structure.', 'wper-checklist' ),
		'fix'   => __( 'Set display_errors = Off and keep log_errors writing to a file instead.', 'wper-checklist' ),
	],
	'server:open-basedir' => [
		'cause' => __( 'Without a path restriction, compromised PHP can reach files across the whole server.', 'wper-checklist' ),
		'fix'   => __( 'Restrict open_basedir to the document root plus the temp directory.', 'wper-checklist' ),
	],
	'server:wp-debug'    => [
		'cause' => __( 'WP_DEBUG in production can expose errors to visitors.', 'wper-checklist' ),
		'fix'   => __( 'Keep WP_DEBUG false; if you need logs, enable only WP_DEBUG_LOG and keep WP_DEBUG_DISPLAY always false.', 'wper-checklist' ),
	],
	'server:file-edit'   => [
		'cause' => __( 'The built-in theme/plugin editor is the shortest path to a web shell once an admin account is stolen.', 'wper-checklist' ),
		'fix'   => __( 'Add define( \'DISALLOW_FILE_EDIT\', true ) to wp-config.php.', 'wper-checklist' ),
	],
	'server:db-charset'  => [
		'cause' => __( 'utf8 (3-byte) silently loses emoji and some CJK characters.', 'wper-checklist' ),
		'fix'   => __( 'Switch DB_CHARSET to utf8mb4 and convert existing tables (back up first).', 'wper-checklist' ),
	],
	'server:sec-headers' => [
		'cause' => __( 'Without security headers, MIME-sniffing, clickjacking, and referrer-leak defenses rely on browser defaults alone.', 'wper-checklist' ),
		'fix'   => __( 'Add X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy, and (on https) HSTS at the web server.', 'wper-checklist' ),
	],
	'server:tokens'      => [
		'cause' => __( 'Exposed server software versions invite attacks on version-specific known flaws.', 'wper-checklist' ),
		'fix'   => __( 'Hide versions with server_tokens off (nginx) and expose_php Off (PHP).', 'wper-checklist' ),
	],
	'server:https'       => [
		'cause' => __( 'Without forced https, session cookies can travel in plaintext.', 'wper-checklist' ),
		'fix'   => __( 'Add an http→https 301 redirect at the web server and define( \'FORCE_SSL_ADMIN\', true ) in wp-config.php.', 'wper-checklist' ),
	],
	'server:files'       => [
		'cause' => __( 'Web-reachable config files, repository metadata, or PHP execution inside uploads leads straight to disclosure and remote execution.', 'wper-checklist' ),
		'fix'   => __( 'Block access to wp-config.php, .git, and *.sql at the web server and forbid PHP execution in uploads.', 'wper-checklist' ),
	],
	'server:db-vars'     => [
		'cause' => __( 'The DB server is outdated or deviates from recommended settings (utf8mb4, local_infile Off, slow query log).', 'wper-checklist' ),
		'fix'   => __( 'Upgrade to a supported MariaDB/MySQL and set character-set-server=utf8mb4, local_infile=0, slow_query_log=1 in my.cnf.', 'wper-checklist' ),
	],
];
