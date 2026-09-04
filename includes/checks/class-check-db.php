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

	/** 스텝 → [ URL 키, 가중치, 라벨, pass/warn 상한(쿼리 수), 측정 횟수 ]. */
	const URL_STEPS = [
		'db:home'    => [ 'home', 40, '랜딩 쿼리 수', [ 30, 80 ], 3 ],
		'db:single'  => [ 'single', 30, '글 페이지 쿼리 수', [ 40, 100 ], 1 ],
		'db:archive' => [ 'archive', 30, '아카이브 쿼리 수', [ 50, 120 ], 1 ],
	];

	public static function run( string $step_id, array $ctx ): array {
		if ( 'db:analyze' === $step_id ) {
			return self::run_analyze( $ctx );
		}
		return self::run_url( $step_id, $ctx );
	}

	private static function run_url( string $step_id, array $ctx ): array {
		[ $url_key, $weight, $label, [ $ok, $warn ], $runs ] = self::URL_STEPS[ $step_id ];

		$url   = $ctx['urls'][ $url_key ] ?? '';
		$token = $ctx['token'] ?? '';

		if ( '' === $url ) {
			$item = wper_checklist_item( $step_id, self::CAT, $label, 'skip', $weight, '해당 URL 없음 — 건너뜀' );
			return [ 'items' => [ $item ], 'events' => [ $label . ': 대상 없음' ], 'done' => true ];
		}

		// 워밍 1회 — 첫 요청은 오브젝트 캐시 미스가 섞여 대표성이 없다. "전" 측정도 동일 조건.
		self::probe( $url, $token );

		$counts = [];
		$times  = [];
		$last   = null;

		for ( $i = 0; $i < $runs; $i++ ) {
			$res = self::probe( $url, $token );
			if ( null === $res ) {
				$item = wper_checklist_item( $step_id, self::CAT, $label, 'skip', $weight, '캡처 헤더 미수신 — 확인 불가 (플러그인이 프론트에서 비활성일 수 있음)' );
				return [ 'items' => [ $item ], 'events' => [ $label . ': 캡처 실패' ], 'done' => true ];
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
			sprintf( '%d개 쿼리 (%s)', $count, $runs > 1 ? '3회 중앙값' : '1회' ),
			[ 'slow' => $last['slow'], 'dupes' => $last['dupes'], 'hits' => $last['hits'], 'misses' => $last['misses'] ]
		);

		if ( 'home' === $url_key ) {
			$time_ms = WPER_Checklist_HTTP::median( $times );
			$t_state = $time_ms < 50 ? 'pass' : ( $time_ms <= 150 ? 'warn' : 'fail' );
			$items[] = wper_checklist_item( 'db:time', self::CAT, '랜딩 DB 총 시간', $t_state, 30, sprintf( '%dms (3회 중앙값)', (int) round( $time_ms ) ) );
		}

		return [ 'items' => $items, 'events' => [ sprintf( '%s: %d개', $label, $count ) ], 'done' => true ];
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
			$items[] = wper_checklist_item( 'db:slow', self::CAT, '슬로 쿼리 (>20ms)', 'skip', 30, '캡처 데이터 없음 — 확인 불가' );
			$items[] = wper_checklist_item( 'db:dupes', self::CAT, '중복 쿼리 (N+1)', 'skip', 20, '캡처 데이터 없음 — 확인 불가' );
		} else {
			$fingerprints = WPER_Checklist_Capture::read_and_clear();
			$fp_note      = '';
			if ( $fingerprints ) {
				$first   = reset( $fingerprints );
				$fp_note = $first ? sprintf( ' · 최다 %sms', $first[0]['ms'] ?? '?' ) : '';
			}

			$s_status = 0 === $slow ? 'pass' : ( $slow <= 3 ? 'warn' : 'fail' );
			$items[]  = wper_checklist_item( 'db:slow', self::CAT, '슬로 쿼리 (>20ms)', $s_status, 30, sprintf( '%d건%s', $slow, $fp_note ) );

			$d_status = 0 === $dupes ? 'pass' : ( $dupes <= 5 ? 'warn' : 'fail' );
			$items[]  = wper_checklist_item( 'db:dupes', self::CAT, '중복 쿼리 (N+1)', $d_status, 20, sprintf( '%d건 (리터럴 제거 지문 기준)', $dupes ) );
		}

		// 오브젝트 캐시 — 외부 드롭인 + 히트율.
		$ext     = wp_using_ext_object_cache();
		$dropin  = file_exists( WP_CONTENT_DIR . '/object-cache.php' );
		$ratio   = ( $hits >= 0 && ( $hits + $miss ) > 0 ) ? $hits / ( $hits + $miss ) : null;
		$o_state = ( $ext && $dropin ) ? 'pass' : ( $ext || $dropin ? 'warn' : 'fail' );
		$note    = $ext ? '외부 오브젝트 캐시 활성' : '외부 오브젝트 캐시 없음';
		if ( null !== $ratio ) {
			$note .= sprintf( ' · 히트율 %d%%', (int) round( $ratio * 100 ) );
		}
		$items[] = wper_checklist_item( 'db:objcache', self::CAT, '오브젝트 캐시', $o_state, 20, $note );

		return [ 'items' => $items, 'events' => [ sprintf( '쿼리 분석: 슬로 %d · 중복 %d', $slow, $dupes ) ], 'done' => true ];
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
