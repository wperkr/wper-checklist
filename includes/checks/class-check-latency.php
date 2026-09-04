<?php
/**
 * 카테고리 ① 지연시간 — URL 샘플러 루프백 측정.
 *
 * ⭐ 계측 규약: 워밍 1회 후 3회 측정 → **중앙값**. 단발 수치는 리포트에 쓰지 않는다.
 * ⭐ 이 카테고리는 **캐시 히트 경로**(= 실사용자 경로)를 잰다. 비캐시 DB 경로는
 *    카테고리 ② 담당이다 — 두 숫자를 섞지 않는다.
 * ⚠ 루프백은 순차로만 — FPM 워커 데드락(§리스크).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPER_Checklist_Check_Latency {

	const CAT = 'latency';

	/** 스텝 ID → [ URL 컨텍스트 키, 가중치, 라벨 ]. */
	const URL_STEPS = [
		'latency:home'    => [ 'home', 40, '랜딩 응답 속도' ],
		'latency:archive' => [ 'archive', 25, '아카이브 응답 속도' ],
		'latency:single'  => [ 'single', 25, '글 페이지 응답 속도' ],
		'latency:page'    => [ 'page', 20, '고정 페이지 응답 속도' ],
		'latency:product' => [ 'product', 20, '상품·서비스 응답 속도' ],
	];

	/** [ pass 상한, warn 상한 ] (ms) — 초과는 fail. */
	const THRESHOLDS = [
		'home'    => [ 200, 600 ],
		'archive' => [ 250, 700 ],
		'single'  => [ 300, 800 ],
		'page'    => [ 250, 700 ],
		'product' => [ 250, 700 ],
	];

	public static function run( string $step_id, array $ctx ): array {
		if ( 'latency:redirects' === $step_id ) {
			return self::run_redirects( $ctx );
		}
		return self::run_url( $step_id, $ctx );
	}

	private static function run_url( string $step_id, array $ctx ): array {
		[ $url_key, $weight, $label ] = self::URL_STEPS[ $step_id ];

		$url    = $ctx['urls'][ $url_key ] ?? '';
		$items  = [];
		$events = [];

		if ( '' === $url ) {
			// 대상 없음 (상품 CPT 미보유 등) — 확인 불가가 아니라 "해당 없음" skip.
			$items[] = wper_checklist_item( $step_id, self::CAT, $label, 'skip', $weight, '해당 URL 없음 — 건너뜀' );
			return [ 'items' => $items, 'events' => [ $label . ': 대상 없음 — 건너뜀' ], 'done' => true ];
		}

		// 워밍 1회 (전 측정도 동일 조건 — 첫 요청 캐시 미스는 사용자 경험이 아니다).
		WPER_Checklist_HTTP::get( $url );

		$samples = [];
		$body    = '';
		$code    = 0;

		for ( $i = 0; $i < 3; $i++ ) {
			$res = WPER_Checklist_HTTP::get( $url );
			if ( ! $res['ok'] || $res['code'] >= 500 ) {
				$items[] = wper_checklist_item( $step_id, self::CAT, $label, 'fail', $weight, sprintf( '측정 실패 (HTTP %d %s)', $res['code'], $res['error'] ) );
				return [ 'items' => $items, 'events' => [ $label . ': 측정 실패' ], 'done' => true ];
			}
			$samples[] = (float) $res['ttfb_ms'];
			$body      = $res['body'];
			$code      = $res['code'];
		}

		$median = WPER_Checklist_HTTP::median( $samples );
		[ $ok, $warn ] = self::THRESHOLDS[ $url_key ];
		$status = $median < $ok ? 'pass' : ( $median <= $warn ? 'warn' : 'fail' );

		$items[] = wper_checklist_item(
			$step_id,
			self::CAT,
			$label,
			$status,
			$weight,
			sprintf( '%dms (3회 중앙값 · HTTP %d)', (int) round( $median ), $code ),
			[ 'samples' => array_map( 'round', $samples ), 'median' => round( $median ) ]
		);
		$events[] = sprintf( '%s: %dms', $label, (int) round( $median ) );

		if ( 'home' === $url_key ) {
			// 측정 안정성 — 편차가 크면 중앙값 자체를 신뢰하기 어렵다.
			$spread   = $median > 0 ? ( max( $samples ) - min( $samples ) ) / $median : 0;
			$s_status = $spread < 0.15 ? 'pass' : ( $spread <= 0.40 ? 'warn' : 'fail' );
			$items[]  = wper_checklist_item(
				'latency:stability',
				self::CAT,
				'응답 시간 안정성',
				$s_status,
				25,
				sprintf( '편차 %d%% (3회: %s ms)', (int) round( $spread * 100 ), implode( ' · ', array_map( static fn( $v ) => (string) (int) round( $v ), $samples ) ) )
			);

			// HTML 페이로드 크기.
			$kb       = strlen( $body ) / 1024;
			$z_status = $kb < 100 ? 'pass' : ( $kb <= 250 ? 'warn' : 'fail' );
			$items[]  = wper_checklist_item( 'latency:size', self::CAT, '랜딩 HTML 크기', $z_status, 20, sprintf( '%dKB', (int) round( $kb ) ) );
		}

		return [ 'items' => $items, 'events' => $events, 'done' => true ];
	}

	/**
	 * 샘플 URL 전부의 리다이렉트 홉 검사 — 홉마다 왕복이 하나씩 늘어난다.
	 */
	private static function run_redirects( array $ctx ): array {
		$max_hops = 0;
		$detail   = [];

		foreach ( self::URL_STEPS as [ $url_key ] ) {
			$url = $ctx['urls'][ $url_key ] ?? '';
			if ( '' === $url ) {
				continue;
			}
			$hop      = WPER_Checklist_HTTP::hops( $url );
			$max_hops = max( $max_hops, $hop['hops'] );
			if ( $hop['hops'] > 0 ) {
				$detail[] = wp_parse_url( $url, PHP_URL_PATH ) . ' → ' . $hop['hops'] . '홉';
			}
		}

		$status = 0 === $max_hops ? 'pass' : ( 1 === $max_hops ? 'warn' : 'fail' );
		$item   = wper_checklist_item(
			'latency:redirects',
			self::CAT,
			'리다이렉트 체인 없음',
			$status,
			25,
			$detail ? implode( ', ', $detail ) : '전 URL 직행 (0홉)'
		);

		return [ 'items' => [ $item ], 'events' => [ '리다이렉트 검사: 최대 ' . $max_hops . '홉' ], 'done' => true ];
	}
}
