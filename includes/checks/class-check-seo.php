<?php
/**
 * 카테고리 ③ SEO — Lighthouse SEO 감사의 서버측 등가물 (정적 HTML 분석).
 *
 * ⚠ 헤드리스 브라우저가 없으므로 **렌더 전 HTML** 을 본다 — JS 로 주입되는 메타는
 *   잡지 못하지만, 크롤러 다수도 첫 파싱은 원본 HTML 이므로 오히려 보수적 판정이다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPER_Checklist_Check_SEO {

	const CAT = 'seo';

	public static function run( string $step_id, array $ctx ): array {
		if ( 'seo:site' === $step_id ) {
			return self::run_site( $ctx );
		}
		return self::run_landing( $ctx );
	}

	private static function run_landing( array $ctx ): array {
		$url = $ctx['urls']['home'] ?? home_url( '/' );
		$res = WPER_Checklist_HTTP::get( $url );

		if ( ! $res['ok'] || '' === $res['body'] ) {
			// 랜딩을 못 읽으면 전 항목 확인 불가 — 0점 폭탄이 아니라 분모 제외가 규약.
			$items = [];
			foreach ( self::landing_defs() as $key => [ $label, $weight ] ) {
				$items[] = wper_checklist_item( $key, self::CAT, $label, 'skip', $weight, __( 'Failed to fetch landing HTML — unverifiable', 'wper-checklist' ) );
			}
			return [ 'items' => $items, 'events' => [ __( 'SEO: could not fetch the landing page', 'wper-checklist' ) ], 'done' => true ];
		}

		$dom = self::dom( $res['body'] );
		$xp  = new DOMXPath( $dom );

		$items = [];

		// 1. <title>
		$title = trim( (string) $xp->evaluate( 'string(//title[1])' ) );
		$t_len = mb_strlen( $title );

		if ( '' === $title ) {
			$title_measured = __( 'None', 'wper-checklist' );
		} else {
			/* translators: 1: character count, 2: the text that was found. */
			$title_measured = sprintf( __( '%1$d chars — "%2$s"', 'wper-checklist' ), $t_len, mb_substr( $title, 0, 40 ) );
		}

		$items[] = wper_checklist_item(
			'seo:title', self::CAT, __( 'Document title', 'wper-checklist' ),
			'' === $title ? 'fail' : ( ( $t_len >= 10 && $t_len <= 60 ) ? 'pass' : 'warn' ),
			20,
			$title_measured
		);

		// 2. meta description
		$desc  = trim( (string) $xp->evaluate( 'string(//meta[@name="description"]/@content)' ) );
		$d_len = mb_strlen( $desc );
		$items[] = wper_checklist_item(
			'seo:description', self::CAT, __( 'Meta description', 'wper-checklist' ),
			'' === $desc ? 'fail' : ( ( $d_len >= 50 && $d_len <= 160 ) ? 'pass' : 'warn' ),
			20,
			/* translators: %d: character count. */
			'' === $desc ? __( 'None', 'wper-checklist' ) : sprintf( __( '%d chars', 'wper-checklist' ), $d_len )
		);

		// 3. h1 단일
		$h1s = $xp->query( '//h1' )->length;
		$items[] = wper_checklist_item(
			'seo:h1', self::CAT, __( 'H1 heading structure', 'wper-checklist' ),
			1 === $h1s ? 'pass' : ( 0 === $h1s ? 'fail' : 'warn' ),
			15,
			/* translators: %d: number of h1 elements found. */
			sprintf( __( '%d h1 elements', 'wper-checklist' ), $h1s )
		);

		// 4. 색인 가능 (meta robots · X-Robots-Tag) — robots.txt 는 seo:site 가 덮어쓴다.
		$robots_meta = strtolower( (string) $xp->evaluate( 'string(//meta[@name="robots"]/@content)' ) );
		$xrobots     = strtolower( implode( ',', (array) ( $res['headers']['x-robots-tag'] ?? [] ) ) );
		$noindex     = str_contains( $robots_meta, 'noindex' ) || str_contains( $xrobots, 'noindex' );
		$items[] = wper_checklist_item(
			'seo:indexable', self::CAT, __( 'Landing indexable', 'wper-checklist' ),
			$noindex ? 'fail' : 'pass',
			20,
			$noindex ? __( 'noindex directive found', 'wper-checklist' ) : __( 'Indexing allowed', 'wper-checklist' ),
			[ 'noindex' => $noindex ]
		);

		// 5. canonical self
		$canon = trim( (string) $xp->evaluate( 'string(//link[@rel="canonical"]/@href)' ) );
		if ( '' === $canon ) {
			$c_status = 'fail';
			$c_note   = __( 'None', 'wper-checklist' );
		} else {
			$self     = untrailingslashit( strtok( $url, '?' ) );
			$c_status = untrailingslashit( strtok( $canon, '?' ) ) === $self ? 'pass' : 'warn';
			$c_note   = 'pass' === $c_status ? __( 'self-referential', 'wper-checklist' ) : __( 'not self: ', 'wper-checklist' ) . $canon;
		}
		$items[] = wper_checklist_item( 'seo:canonical', self::CAT, 'canonical URL', $c_status, 15, $c_note );

		// 6. html lang
		$lang = trim( (string) $xp->evaluate( 'string(//html/@lang)' ) );
		$items[] = wper_checklist_item( 'seo:lang', self::CAT, __( 'html lang attribute', 'wper-checklist' ), '' !== $lang ? 'pass' : 'fail', 10, '' !== $lang ? $lang : __( 'None', 'wper-checklist' ) );

		// 7. viewport
		$vp = (string) $xp->evaluate( 'string(//meta[@name="viewport"]/@content)' );
		$items[] = wper_checklist_item( 'seo:viewport', self::CAT, __( 'Mobile viewport', 'wper-checklist' ), '' !== $vp ? 'pass' : 'fail', 10, '' !== $vp ? $vp : __( 'None', 'wper-checklist' ) );

		// 8. img alt 커버리지
		$imgs     = $xp->query( '//img' );
		$with_alt = 0;
		foreach ( $imgs as $img ) {
			if ( '' !== trim( (string) $img->getAttribute( 'alt' ) ) ) {
				$with_alt++;
			}
		}
		if ( 0 === $imgs->length ) {
			$items[] = wper_checklist_item( 'seo:alt', self::CAT, __( 'Image alt text', 'wper-checklist' ), 'pass', 15, __( 'No images', 'wper-checklist' ) );
		} else {
			$ratio   = $with_alt / $imgs->length;
			$items[] = wper_checklist_item(
				'seo:alt', self::CAT, __( 'Image alt text', 'wper-checklist' ),
				$ratio >= 0.9 ? 'pass' : ( $ratio >= 0.5 ? 'warn' : 'fail' ),
				15,
				/* translators: 1: images with alt text, 2: total images, 3: percentage. */
				sprintf( __( '%1$d of %2$d images have alt (%3$d%%)', 'wper-checklist' ), $with_alt, $imgs->length, (int) round( $ratio * 100 ) )
			);
		}

		// 9. 링크 텍스트
		$empty_links = 0;
		foreach ( $xp->query( '//a[@href]' ) as $a ) {
			$text = trim( (string) $a->textContent );
			$aria = trim( (string) $a->getAttribute( 'aria-label' ) );
			$img_alt = (string) $xp->evaluate( 'string(.//img/@alt)', $a );
			if ( '' === $text && '' === $aria && '' === trim( $img_alt ) ) {
				$empty_links++;
			}
		}
		$items[] = wper_checklist_item(
			'seo:linktext', self::CAT, __( 'Link text', 'wper-checklist' ),
			0 === $empty_links ? 'pass' : ( $empty_links <= 2 ? 'warn' : 'fail' ),
			10,
			/* translators: %d: number of links with no descriptive text. */
			0 === $empty_links ? __( 'No empty links', 'wper-checklist' ) : sprintf( __( '%d links without text', 'wper-checklist' ), $empty_links )
		);

		// 10. JSON-LD 구조화 데이터 (랜딩 또는 글 어느 한쪽에 유효하면 통과).
		$items[] = self::jsonld_item( $xp, $ctx );

		// 11. hreflang — 단일 언어 사이트에는 없어도 되는 항목이라 미보유는 warn.
		$alts    = $xp->query( '//link[@rel="alternate"][@hreflang]' );
		$items[] = wper_checklist_item(
			'seo:hreflang', self::CAT, __( 'hreflang alternates', 'wper-checklist' ),
			$alts->length >= 2 ? 'pass' : ( $alts->length > 0 ? 'warn' : 'warn' ),
			10,
			/* translators: %d: number of hreflang declarations. */
			$alts->length > 0 ? sprintf( __( '%d declared', 'wper-checklist' ), $alts->length ) : __( 'None (safe to ignore for single-language sites)', 'wper-checklist' )
		);

		// 12. Open Graph
		$og_t = (string) $xp->evaluate( 'string(//meta[@property="og:title"]/@content)' );
		$og_i = (string) $xp->evaluate( 'string(//meta[@property="og:image"]/@content)' );
		$og_d = (string) $xp->evaluate( 'string(//meta[@property="og:description"]/@content)' );
		$og_n = (int) ( '' !== $og_t ) + (int) ( '' !== $og_i ) + (int) ( '' !== $og_d );
		$items[] = wper_checklist_item(
			'seo:og', self::CAT, __( 'Social meta (OG)', 'wper-checklist' ),
			3 === $og_n ? 'pass' : ( $og_n > 0 ? 'warn' : 'fail' ),
			10,
			/* translators: %d: how many of the three Open Graph tags are present. */
			sprintf( __( '%d of og:title / og:description / og:image', 'wper-checklist' ), $og_n )
		);

		// 13. HTTPS · 혼합 콘텐츠
		$is_https = str_starts_with( $url, 'https://' );
		$mixed    = 0;
		if ( $is_https ) {
			$mixed = preg_match_all( '/\s(?:src|href)=["\']http:\/\//i', $res['body'] );
			$items[] = wper_checklist_item(
				'seo:https', self::CAT, __( 'HTTPS · mixed content', 'wper-checklist' ),
				0 === $mixed ? 'pass' : 'warn',
				10,
				/* translators: %d: number of insecure http:// references on an https page. */
				0 === $mixed ? __( 'https + no mixed content', 'wper-checklist' ) : sprintf( __( '%d http:// references', 'wper-checklist' ), $mixed )
			);
		} else {
			$items[] = wper_checklist_item( 'seo:https', self::CAT, __( 'HTTPS · mixed content', 'wper-checklist' ), 'fail', 10, __( 'Site is http — no TLS', 'wper-checklist' ) );
		}

		return [ 'items' => $items, 'events' => [ __( 'Landing SEO analysis complete (', 'wper-checklist' ) . count( $items ) . __( ' items)', 'wper-checklist' ) ], 'done' => true ];
	}

	/**
	 * 사이트 수준 — robots.txt · 사이트맵. seo:indexable 을 robots.txt 결과로 재판정한다.
	 */
	private static function run_site( array $ctx ): array {
		$home   = untrailingslashit( $ctx['urls']['home'] ?? home_url() );
		$items  = [];
		$events = [];

		// robots.txt — 전면 차단이면 색인 항목을 fail 로 강등.
		$robots  = WPER_Checklist_HTTP::get( $home . '/robots.txt' );
		$blocked = false;
		if ( $robots['ok'] && 200 === $robots['code'] && preg_match( '/User-agent:\s*\*\s*(?:\r?\n(?!User-agent)[^\r\n]*)*/i', $robots['body'], $m ) ) {
			$blocked = (bool) preg_match( '/^Disallow:\s*\/\s*$/mi', $m[0] );
		}
		$prev_noindex = (bool) ( $ctx['items']['seo:indexable']['raw']['noindex'] ?? false );
		if ( $blocked || $prev_noindex ) {
			$items[] = wper_checklist_item(
				'seo:indexable', self::CAT, __( 'Landing indexable', 'wper-checklist' ), 'fail', 20,
				$blocked ? __( 'robots.txt blocks everything (Disallow: /)', 'wper-checklist' ) : __( 'noindex directive found', 'wper-checklist' )
			);
		}

		// 사이트맵 — ⚠ Rank Math 는 /wp-sitemap.xml 을 301 로 가로챈다: 리다이렉트를 따라가며 확인.
		$found = '';
		if ( $robots['ok'] && preg_match( '/^Sitemap:\s*(\S+)/mi', $robots['body'], $sm ) ) {
			$candidates = [ $sm[1] ];
		} else {
			$candidates = [];
		}
		$candidates[] = $home . '/sitemap_index.xml';
		$candidates[] = $home . '/wp-sitemap.xml';
		$candidates[] = $home . '/sitemap.xml';

		foreach ( array_unique( $candidates ) as $cand ) {
			$hop = WPER_Checklist_HTTP::hops( $cand, 3 );
			if ( 200 === $hop['code'] ) {
				$found = $hop['url'];
				break;
			}
		}
		$in_robots = $robots['ok'] && false !== stripos( $robots['body'], 'Sitemap:' );
		$items[]   = wper_checklist_item(
			'seo:sitemap', self::CAT, __( 'XML sitemap', 'wper-checklist' ),
			'' !== $found ? ( $in_robots ? 'pass' : 'warn' ) : 'fail',
			15,
			'' !== $found ? ( wp_parse_url( $found, PHP_URL_PATH ) . ( $in_robots ? __( ' · listed in robots.txt', 'wper-checklist' ) : __( ' · not listed in robots.txt', 'wper-checklist' ) ) ) : __( 'No reachable sitemap', 'wper-checklist' )
		);
		$events[] = __( 'Sitemap: ', 'wper-checklist' ) . ( $found ? __( 'checked', 'wper-checklist' ) : __( 'None', 'wper-checklist' ) );

		return [ 'items' => $items, 'events' => $events, 'done' => true ];
	}

	/**
	 * JSON-LD — 랜딩과 글 페이지 중 하나라도 유효하면 pass.
	 */
	private static function jsonld_item( DOMXPath $landing_xp, array $ctx ): array {
		$check = static function ( DOMXPath $xp ): array {
			$total = 0;
			$valid = 0;
			foreach ( $xp->query( '//script[@type="application/ld+json"]' ) as $node ) {
				$total++;
				$decoded = json_decode( trim( (string) $node->textContent ), true );
				if ( null !== $decoded ) {
					$valid++;
				}
			}
			return [ $total, $valid ];
		};

		[ $total, $valid ] = $check( $landing_xp );

		$single_note = '';
		$single_url  = $ctx['urls']['single'] ?? '';
		if ( '' !== $single_url && 0 === $valid ) {
			$res = WPER_Checklist_HTTP::get( $single_url );
			if ( $res['ok'] && '' !== $res['body'] ) {
				[ $s_total, $s_valid ] = $check( new DOMXPath( self::dom( $res['body'] ) ) );
				$total       += $s_total;
				$valid       += $s_valid;
				$single_note  = __( ' (including post page)', 'wper-checklist' );
			}
		}

		if ( $valid > 0 && $valid === $total ) {
			/* translators: 1: number of valid JSON-LD blocks, 2: optional suffix. */
			$measured = sprintf( __( '%1$d valid blocks%2$s', 'wper-checklist' ), $valid, $single_note );

			return wper_checklist_item( 'seo:jsonld', self::CAT, __( 'Structured data (JSON-LD)', 'wper-checklist' ), 'pass', 20, $measured );
		}
		if ( $valid > 0 ) {
			/* translators: 1: valid JSON-LD blocks, 2: total JSON-LD blocks. */
			return wper_checklist_item( 'seo:jsonld', self::CAT, __( 'Structured data (JSON-LD)', 'wper-checklist' ), 'warn', 20, sprintf( __( 'only %1$d of %2$d blocks valid', 'wper-checklist' ), $valid, $total ) );
		}
		return wper_checklist_item( 'seo:jsonld', self::CAT, __( 'Structured data (JSON-LD)', 'wper-checklist' ), 'fail', 20, $total > 0 ? __( 'JSON parse failure', 'wper-checklist' ) : __( 'None', 'wper-checklist' ) );
	}

	/**
	 * UTF-8 안전 DOM 로드.
	 */
	private static function dom( string $html ): DOMDocument {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		return $dom;
	}

	/** 랜딩 항목 정의 (수신 실패 시 skip 발급용). */
	private static function landing_defs(): array {
		return [
			'seo:title'       => [ __( 'Document title', 'wper-checklist' ), 20 ],
			'seo:description' => [ __( 'Meta description', 'wper-checklist' ), 20 ],
			'seo:h1'          => [ __( 'H1 heading structure', 'wper-checklist' ), 15 ],
			'seo:indexable'   => [ __( 'Landing indexable', 'wper-checklist' ), 20 ],
			'seo:canonical'   => [ 'canonical URL', 15 ],
			'seo:lang'        => [ __( 'html lang attribute', 'wper-checklist' ), 10 ],
			'seo:viewport'    => [ __( 'Mobile viewport', 'wper-checklist' ), 10 ],
			'seo:alt'         => [ __( 'Image alt text', 'wper-checklist' ), 15 ],
			'seo:linktext'    => [ __( 'Link text', 'wper-checklist' ), 10 ],
			'seo:jsonld'      => [ __( 'Structured data (JSON-LD)', 'wper-checklist' ), 20 ],
			'seo:hreflang'    => [ __( 'hreflang alternates', 'wper-checklist' ), 10 ],
			'seo:og'          => [ __( 'Social meta (OG)', 'wper-checklist' ), 10 ],
			'seo:https'       => [ __( 'HTTPS · mixed content', 'wper-checklist' ), 10 ],
		];
	}
}
