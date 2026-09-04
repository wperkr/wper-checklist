<?php
/**
 * 카테고리 ② DB 쿼리 — 캡처 토큰으로 비캐시 경로를 실측한다.
 *
 * "capture ready(토큰) → urls hit(루프백) → capture output(X-WPER-Check-* 헤더)"
 *
 * ⭐ 토큰 쿼리스트링이 페이지 캐시를 자연히 우회한다 — PHP·DB 가 실제로 도는
 *    경로를 재는 것이 이 카테고리의 의도다 (①과 다른 숫자가 정상).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPER_Checklist_Check_DB {

	const CAT = 'db';

	/**
	 * 스텝 → [ URL 키, 가중치, 라벨, pass/warn 상한(쿼리 수), 측정 횟수 ].
	 *
	 * ⚠ 상수가 아니라 메서드다 — 상수 표현식에는 `__()` 를 넣을 수 없다.
	 */
	private static function url_steps(): array {
		return [
			'db:home'    => [ 'home', 40, __( 'Landing query count', 'wper-checklist' ), [ 30, 80 ], 3 ],
			'db:single'  => [ 'single', 30, __( 'Post query count', 'wper-checklist' ), [ 40, 100 ], 1 ],
			'db:archive' => [ 'archive', 30, __( 'Archive query count', 'wper-checklist' ), [ 50, 120 ], 1 ],
		];
	}

	public static function run( string $step_id, array $ctx ): array {
		if ( 'db:analyze' === $step_id ) {
			return self::run_analyze( $ctx );
		}
		return self::run_url( $step_id, $ctx );
	}

	private static function run_url( string $step_id, array $ctx ): array {
		[ $url_key, $weight, $label, [ $ok, $warn ], $runs ] = self::url_steps()[ $step_id ];

		$url   = $ctx['urls'][ $url_key ] ?? '';
		$token = $ctx['token'] ?? '';

		if ( '' === $url ) {
			$item = wper_checklist_item( $step_id, self::CAT, $label, 'skip', $weight, __( 'No such URL — skipped', 'wper-checklist' ) );
			return [ 'items' => [ $item ], 'events' => [ $label . __( ': no target', 'wper-checklist' ) ], 'done' => true ];
		}

		// 워밍 1회 — 첫 요청은 오브젝트 캐시 미스가 섞여 대표성이 없다. "전" 측정도 동일 조건.
		self::probe( $url, $token );

		$counts = [];
		$times  = [];
		$last   = null;

		for ( $i = 0; $i < $runs; $i++ ) {
			$res = self::probe( $url, $token );
			if ( null === $res ) {
				$item = wper_checklist_item( $step_id, self::CAT, $label, 'skip', $weight, __( 'Capture headers not received — unverifiable (plugin may be inactive on the front end)', 'wper-checklist' ) );
				return [ 'items' => [ $item ], 'events' => [ $label . __( ': capture failed', 'wper-checklist' ) ], 'done' => true ];
			}
			$counts[] = $res['queries'];
			$times[]  = $res['time_ms'];
			$last     = $res;
		}

		$count  = (int) WPER_Checklist_HTTP::median( $counts );
		$status = $count < $ok ? 'pass' : ( $count <= $warn ? 'warn' : 'fail' );

		$items   = [];
		$items[] = wper_checklist_item(
			$step_id,
			self::CAT,
			$label,
			$status,
			$weight,
			/* translators: 1: query count, 2: how it was measured (median of 3, or single run). */
			sprintf( __( '%1$d queries (%2$s)', 'wper-checklist' ), $count, $runs > 1 ? __( 'median of 3', 'wper-checklist' ) : __( 'single run', 'wper-checklist' ) ),
			[ 'slow' => $last['slow'], 'dupes' => $last['dupes'], 'hits' => $last['hits'], 'misses' => $last['misses'] ]
		);

		if ( 'home' === $url_key ) {
			$time_ms = WPER_Checklist_HTTP::median( $times );
			$t_state = $time_ms < 50 ? 'pass' : ( $time_ms <= 150 ? 'warn' : 'fail' );
			/* translators: %d: database time in milliseconds. */
			$items[] = wper_checklist_item( 'db:time', self::CAT, __( 'Landing total DB time', 'wper-checklist' ), $t_state, 30, sprintf( __( '%dms (median of 3)', 'wper-checklist' ), (int) round( $time_ms ) ) );
		}

		/* translators: 1: item label, 2: measured count. */
		return [ 'items' => $items, 'events' => [ sprintf( __( '%1$s: %2$d', 'wper-checklist' ), $label, $count ) ], 'done' => true ];
	}

	/**
	 * 집계 항목 — 슬로 · 중복 · 오브젝트 캐시. 앞 스텝들의 raw 를 합산한다.
	 */
	private static function run_analyze( array $ctx ): array {
		$prev  = $ctx['items'] ?? [];
		$slow  = 0;
		$dupes = 0;
		$hits  = -1;
		$miss  = -1;
		$seen  = false;

		foreach ( [ 'db:home', 'db:single', 'db:archive' ] as $key ) {
			$raw = $prev[ $key ]['raw'] ?? null;
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$seen   = true;
			$slow  += (int) ( $raw['slow'] ?? 0 );
			$dupes += (int) ( $raw['dupes'] ?? 0 );
			if ( ( $raw['hits'] ?? -1 ) >= 0 ) {
				$hits = max( $hits, 0 ) + (int) $raw['hits'];
				$miss = max( $miss, 0 ) + (int) $raw['misses'];
			}
		}

		$items = [];

		if ( ! $seen ) {
			$items[] = wper_checklist_item( 'db:slow', self::CAT, __( 'Slow queries (>20ms)', 'wper-checklist' ), 'skip', 30, __( 'No capture data — unverifiable', 'wper-checklist' ) );
			$items[] = wper_checklist_item( 'db:dupes', self::CAT, __( 'Duplicate queries (N+1)', 'wper-checklist' ), 'skip', 20, __( 'No capture data — unverifiable', 'wper-checklist' ) );
		} else {
			$fingerprints = WPER_Checklist_Capture::read_and_clear();
			$fp_note      = '';
			if ( $fingerprints ) {
				$first = reset( $fingerprints );
				/* translators: %s: duration of the slowest query, in milliseconds. */
				$fp_note = $first ? sprintf( __( ' · slowest %sms', 'wper-checklist' ), $first[0]['ms'] ?? '?' ) : '';
			}

			$s_status = 0 === $slow ? 'pass' : ( $slow <= 3 ? 'warn' : 'fail' );
			/* translators: 1: number of occurrences, 2: optional suffix with extra detail. */
			$items[]  = wper_checklist_item( 'db:slow', self::CAT, __( 'Slow queries (>20ms)', 'wper-checklist' ), $s_status, 30, sprintf( __( '%1$d found%2$s', 'wper-checklist' ), $slow, $fp_note ) );

			$d_status = 0 === $dupes ? 'pass' : ( $dupes <= 5 ? 'warn' : 'fail' );
			/* translators: %d: number of duplicate queries. */
			$items[]  = wper_checklist_item( 'db:dupes', self::CAT, __( 'Duplicate queries (N+1)', 'wper-checklist' ), $d_status, 20, sprintf( __( '%d found (by literal-stripped fingerprint)', 'wper-checklist' ), $dupes ) );
		}

		// 오브젝트 캐시 — 외부 드롭인 + 히트율.
		$ext     = wp_using_ext_object_cache();
		$dropin  = file_exists( WP_CONTENT_DIR . '/object-cache.php' );
		$ratio   = ( $hits >= 0 && ( $hits + $miss ) > 0 ) ? $hits / ( $hits + $miss ) : null;
		$o_state = ( $ext && $dropin ) ? 'pass' : ( $ext || $dropin ? 'warn' : 'fail' );
		$note    = $ext ? __( 'External object cache active', 'wper-checklist' ) : __( 'No external object cache', 'wper-checklist' );
		if ( null !== $ratio ) {
			/* translators: %d: object cache hit ratio, as a percentage. */
			$note .= sprintf( __( ' · hit ratio %d%%', 'wper-checklist' ), (int) round( $ratio * 100 ) );
		}
		$items[] = wper_checklist_item( 'db:objcache', self::CAT, __( 'Object cache', 'wper-checklist' ), $o_state, 20, $note );

		/* translators: 1: slow query count, 2: duplicate query count. */
		return [ 'items' => $items, 'events' => [ sprintf( __( 'Query analysis: %1$d slow · %2$d duplicate', 'wper-checklist' ), $slow, $dupes ) ], 'done' => true ];
	}

	/**
	 * 캡처 GET 1회 → 헤더 집계 파싱. 헤더가 없으면 null.
	 */
	private static function probe( string $url, string $token ): ?array {
		$probe_url = add_query_arg( 'wper_check', $token, $url );
		$res       = WPER_Checklist_HTTP::get( $probe_url, [ 'timeout' => 20 ] );

		if ( ! $res['ok'] || ! isset( $res['headers']['x-wper-check-queries'] ) ) {
			return null;
		}

		$h = static fn( $key ) => (int) ( $res['headers'][ $key ] ?? -1 );

		return [
			'queries' => $h( 'x-wper-check-queries' ),
			'time_ms' => $h( 'x-wper-check-time-ms' ),
			'slow'    => $h( 'x-wper-check-slow' ),
			'dupes'   => $h( 'x-wper-check-dupes' ),
			'hits'    => $h( 'x-wper-check-cache-hits' ),
			'misses'  => $h( 'x-wper-check-cache-misses' ),
		];
	}
}
