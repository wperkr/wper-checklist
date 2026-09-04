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
				$items[] = wper_checklist_item( $key, self::CAT, $label, 'skip', $weight, '랜딩 HTML 수신 실패 — 확인 불가' );
			}
			return [ 'items' => $items, 'events' => [ 'SEO: 랜딩 수신 실패' ], 'done' => true ];
		}

		$dom = self::dom( $res['body'] );
		$xp  = new DOMXPath( $dom );

		$items = [];

		// 1. <title>
		$title = trim( (string) $xp->evaluate( 'string(//title[1])' ) );
		$t_len = mb_strlen( $title );
		$items[] = wper_checklist_item(
			'seo:title', self::CAT, '문서 제목 (title)',
			'' === $title ? 'fail' : ( ( $t_len >= 10 && $t_len <= 60 ) ? 'pass' : 'warn' ),
			20,
			'' === $title ? '없음' : sprintf( '%d자 — "%s"', $t_len, mb_substr( $title, 0, 40 ) )
		);

		// 2. meta description
		$desc  = trim( (string) $xp->evaluate( 'string(//meta[@name="description"]/@content)' ) );
		$d_len = mb_strlen( $desc );
		$items[] = wper_checklist_item(
			'seo:description', self::CAT, '메타 설명 (description)',
			'' === $desc ? 'fail' : ( ( $d_len >= 50 && $d_len <= 160 ) ? 'pass' : 'warn' ),
			20,
			'' === $desc ? '없음' : sprintf( '%d자', $d_len )
		);

		// 3. h1 단일
		$h1s = $xp->query( '//h1' )->length;
		$items[] = wper_checklist_item(
			'seo:h1', self::CAT, 'H1 제목 구조',
			1 === $h1s ? 'pass' : ( 0 === $h1s ? 'fail' : 'warn' ),
			15,
			sprintf( 'h1 %d개', $h1s )
		);

		// 4. 색인 가능 (meta robots · X-Robots-Tag) — robots.txt 는 seo:site 가 덮어쓴다.
		$robots_meta = strtolower( (string) $xp->evaluate( 'string(//meta[@name="robots"]/@content)' ) );
		$xrobots     = strtolower( implode( ',', (array) ( $res['headers']['x-robots-tag'] ?? [] ) ) );
		$noindex     = str_contains( $robots_meta, 'noindex' ) || str_contains( $xrobots, 'noindex' );
		$items[] = wper_checklist_item(
			'seo:indexable', self::CAT, '랜딩 색인 가능',
			$noindex ? 'fail' : 'pass',
			20,
			$noindex ? 'noindex 지시 발견' : '색인 허용',
			[ 'noindex' => $noindex ]
		);

		// 5. canonical self
		$canon = trim( (string) $xp->evaluate( 'string(//link[@rel="canonical"]/@href)' ) );
		if ( '' === $canon ) {
			$c_status = 'fail';
			$c_note   = '없음';
		} else {
			$self     = untrailingslashit( strtok( $url, '?' ) );
			$c_status = untrailingslashit( strtok( $canon, '?' ) ) === $self ? 'pass' : 'warn';
			$c_note   = 'pass' === $c_status ? '자기 자신' : '자기 아님: ' . $canon;
		}
		$items[] = wper_checklist_item( 'seo:canonical', self::CAT, 'canonical URL', $c_status, 15, $c_note );

		// 6. html lang
		$lang = trim( (string) $xp->evaluate( 'string(//html/@lang)' ) );
		$items[] = wper_checklist_item( 'seo:lang', self::CAT, 'html lang 속성', '' !== $lang ? 'pass' : 'fail', 10, '' !== $lang ? $lang : '없음' );

		// 7. viewport
		$vp = (string) $xp->evaluate( 'string(//meta[@name="viewport"]/@content)' );
		$items[] = wper_checklist_item( 'seo:viewport', self::CAT, '모바일 viewport', '' !== $vp ? 'pass' : 'fail', 10, '' !== $vp ? $vp : '없음' );

		// 8. img alt 커버리지
		$imgs     = $xp->query( '//img' );
		$with_alt = 0;
		foreach ( $imgs as $img ) {
			if ( '' !== trim( (string) $img->getAttribute( 'alt' ) ) ) {
				$with_alt++;
			}
		}
		if ( 0 === $imgs->length ) {
			$items[] = wper_checklist_item( 'seo:alt', self::CAT, '이미지 대체 텍스트', 'pass', 15, '이미지 0장' );
		} else {
			$ratio   = $with_alt / $imgs->length;
			$items[] = wper_checklist_item(
				'seo:alt', self::CAT, '이미지 대체 텍스트',
				$ratio >= 0.9 ? 'pass' : ( $ratio >= 0.5 ? 'warn' : 'fail' ),
				15,
				sprintf( '%d/%d장에 alt (%d%%)', $with_alt, $imgs->length, (int) round( $ratio * 100 ) )
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
			'seo:linktext', self::CAT, '링크 텍스트',
			0 === $empty_links ? 'pass' : ( $empty_links <= 2 ? 'warn' : 'fail' ),
			10,
			0 === $empty_links ? '빈 링크 없음' : sprintf( '텍스트 없는 링크 %d개', $empty_links )
		);

		// 10. JSON-LD 구조화 데이터 (랜딩 또는 글 어느 한쪽에 유효하면 통과).
		$items[] = self::jsonld_item( $xp, $ctx );

		// 11. hreflang — 단일 언어 사이트에는 없어도 되는 항목이라 미보유는 warn.
		$alts    = $xp->query( '//link[@rel="alternate"][@hreflang]' );
		$items[] = wper_checklist_item(
			'seo:hreflang', self::CAT, 'hreflang 대체 주소',
			$alts->length >= 2 ? 'pass' : ( $alts->length > 0 ? 'warn' : 'warn' ),
			10,
			$alts->length > 0 ? sprintf( '%d개 선언', $alts->length ) : '없음 (단일 언어 사이트면 무시 가능)'
		);

		// 12. Open Graph
		$og_t = (string) $xp->evaluate( 'string(//meta[@property="og:title"]/@content)' );
		$og_i = (string) $xp->evaluate( 'string(//meta[@property="og:image"]/@content)' );
		$og_d = (string) $xp->evaluate( 'string(//meta[@property="og:description"]/@content)' );
		$og_n = (int) ( '' !== $og_t ) + (int) ( '' !== $og_i ) + (int) ( '' !== $og_d );
		$items[] = wper_checklist_item(
			'seo:og', self::CAT, '소셜 공유 메타 (OG)',
			3 === $og_n ? 'pass' : ( $og_n > 0 ? 'warn' : 'fail' ),
			10,
			sprintf( 'og:title·description·image 중 %d개', $og_n )
		);

		// 13. HTTPS · 혼합 콘텐츠
		$is_https = str_starts_with( $url, 'https://' );
		$mixed    = 0;
		if ( $is_https ) {
			$mixed = preg_match_all( '/\s(?:src|href)=["\']http:\/\//i', $res['body'] );
			$items[] = wper_checklist_item(
				'seo:https', self::CAT, 'HTTPS · 혼합 콘텐츠',
				0 === $mixed ? 'pass' : 'warn',
				10,
				0 === $mixed ? 'https + 혼합 콘텐츠 없음' : sprintf( 'http:// 참조 %d건', $mixed )
			);
		} else {
			$items[] = wper_checklist_item( 'seo:https', self::CAT, 'HTTPS · 혼합 콘텐츠', 'fail', 10, '사이트가 http — TLS 미적용' );
		}

		return [ 'items' => $items, 'events' => [ 'SEO 랜딩 분석 완료 (' . count( $items ) . '개 항목)' ], 'done' => true ];
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
				'seo:indexable', self::CAT, '랜딩 색인 가능', 'fail', 20,
				$blocked ? 'robots.txt 가 전체 차단 (Disallow: /)' : 'noindex 지시 발견'
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
			'seo:sitemap', self::CAT, 'XML 사이트맵',
			'' !== $found ? ( $in_robots ? 'pass' : 'warn' ) : 'fail',
			15,
			'' !== $found ? ( wp_parse_url( $found, PHP_URL_PATH ) . ( $in_robots ? ' · robots.txt 에 명시' : ' · robots.txt 미명시' ) ) : '도달 가능한 사이트맵 없음'
		);
		$events[] = '사이트맵: ' . ( $found ? '확인' : '없음' );

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
				$single_note  = ' (글 페이지 포함)';
			}
		}

		if ( $valid > 0 && $valid === $total ) {
			return wper_checklist_item( 'seo:jsonld', self::CAT, '구조화 데이터 (JSON-LD)', 'pass', 20, sprintf( '유효 블록 %d개%s', $valid, $single_note ) );
		}
		if ( $valid > 0 ) {
			return wper_checklist_item( 'seo:jsonld', self::CAT, '구조화 데이터 (JSON-LD)', 'warn', 20, sprintf( '%d/%d 블록만 유효', $valid, $total ) );
		}
		return wper_checklist_item( 'seo:jsonld', self::CAT, '구조화 데이터 (JSON-LD)', 'fail', 20, $total > 0 ? 'JSON 파싱 실패' : '없음' );
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
			'seo:title'       => [ '문서 제목 (title)', 20 ],
			'seo:description' => [ '메타 설명 (description)', 20 ],
			'seo:h1'          => [ 'H1 제목 구조', 15 ],
			'seo:indexable'   => [ '랜딩 색인 가능', 20 ],
			'seo:canonical'   => [ 'canonical URL', 15 ],
			'seo:lang'        => [ 'html lang 속성', 10 ],
			'seo:viewport'    => [ '모바일 viewport', 10 ],
			'seo:alt'         => [ '이미지 대체 텍스트', 15 ],
			'seo:linktext'    => [ '링크 텍스트', 10 ],
			'seo:jsonld'      => [ '구조화 데이터 (JSON-LD)', 20 ],
			'seo:hreflang'    => [ 'hreflang 대체 주소', 10 ],
			'seo:og'          => [ '소셜 공유 메타 (OG)', 10 ],
			'seo:https'       => [ 'HTTPS · 혼합 콘텐츠', 10 ],
		];
	}
}
