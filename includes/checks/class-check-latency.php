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

	/**
	 * 스텝 ID → [ URL 컨텍스트 키, 가중치, 라벨 ].
	 *
	 * ⚠ 상수가 아니라 메서드다 — PHP 상수 표현식은 함수 호출을 담을 수 없어 `__()` 가
	 *   들어가지 못한다. 라벨을 상수에 두면 그 순간 언어팩 밖으로 문자열이 샌다.
	 */
	private static function url_steps(): array {
		return [
			'latency:home'    => [ 'home', 40, __( 'Landing response time', 'wper-checklist' ) ],
			'latency:archive' => [ 'archive', 25, __( 'Archive response time', 'wper-checklist' ) ],
			'latency:single'  => [ 'single', 25, __( 'Post response time', 'wper-checklist' ) ],
			'latency:page'    => [ 'page', 20, __( 'Page response time', 'wper-checklist' ) ],
			'latency:product' => [ 'product', 20, __( 'Product/service response time', 'wper-checklist' ) ],
		];
	}

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
		[ $url_key, $weight, $label ] = self::url_steps()[ $step_id ];

		$url    = $ctx['urls'][ $url_key ] ?? '';
		$items  = [];
		$events = [];

		if ( '' === $url ) {
			// 대상 없음 (상품 CPT 미보유 등) — 확인 불가가 아니라 "해당 없음" skip.
			$items[] = wper_checklist_item( $step_id, self::CAT, $label, 'skip', $weight, __( 'No such URL — skipped', 'wper-checklist' ) );
			return [ 'items' => $items, 'events' => [ $label . __( ': no target — skipped', 'wper-checklist' ) ], 'done' => true ];
		}

		// 워밍 1회 (전 측정도 동일 조건 — 첫 요청 캐시 미스는 사용자 경험이 아니다).
		WPER_Checklist_HTTP::get( $url );

		$samples = [];
		$body    = '';
		$code    = 0;

		for ( $i = 0; $i < 3; $i++ ) {
			$res = WPER_Checklist_HTTP::get( $url );
			if ( ! $res['ok'] || $res['code'] >= 500 ) {
				/* translators: 1: HTTP status code, 2: error message. */
				$items[] = wper_checklist_item( $step_id, self::CAT, $label, 'fail', $weight, sprintf( __( 'Measurement failed (HTTP %1$d %2$s)', 'wper-checklist' ), $res['code'], $res['error'] ) );
				return [ 'items' => $items, 'events' => [ $label . __( ': measurement failed', 'wper-checklist' ) ], 'done' => true ];
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
			/* translators: 1: response time in milliseconds, 2: HTTP status code. */
			sprintf( __( '%1$dms (median of 3 · HTTP %2$d)', 'wper-checklist' ), (int) round( $median ), $code ),
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
				__( 'Response time stability', 'wper-checklist' ),
				$s_status,
				25,
				/* translators: 1: spread between runs as a percentage, 2: the three measurements. */
				sprintf( __( 'Spread %1$d%% (3 runs: %2$s ms)', 'wper-checklist' ), (int) round( $spread * 100 ), implode( ' · ', array_map( static fn( $v ) => (string) (int) round( $v ), $samples ) ) )
			);

			// HTML 페이로드 크기.
			$kb       = strlen( $body ) / 1024;
			$z_status = $kb < 100 ? 'pass' : ( $kb <= 250 ? 'warn' : 'fail' );
			$items[]  = wper_checklist_item( 'latency:size', self::CAT, __( 'Landing HTML size', 'wper-checklist' ), $z_status, 20, sprintf( '%dKB', (int) round( $kb ) ) );
		}

		return [ 'items' => $items, 'events' => $events, 'done' => true ];
	}

	/**
	 * 샘플 URL 전부의 리다이렉트 홉 검사 — 홉마다 왕복이 하나씩 늘어난다.
	 */
	private static function run_redirects( array $ctx ): array {
		$max_hops = 0;
		$detail   = [];

		foreach ( self::url_steps() as [ $url_key ] ) {
			$url = $ctx['urls'][ $url_key ] ?? '';
			if ( '' === $url ) {
				continue;
			}
			$hop      = WPER_Checklist_HTTP::hops( $url );
			$max_hops = max( $max_hops, $hop['hops'] );
			if ( $hop['hops'] > 0 ) {
				$detail[] = wp_parse_url( $url, PHP_URL_PATH ) . ' → ' . $hop['hops'] . __( ' hop(s)', 'wper-checklist' );
			}
		}

		$status = 0 === $max_hops ? 'pass' : ( 1 === $max_hops ? 'warn' : 'fail' );
		$item   = wper_checklist_item(
			'latency:redirects',
			self::CAT,
			__( 'No redirect chains', 'wper-checklist' ),
			$status,
			25,
			$detail ? implode( ', ', $detail ) : __( 'All URLs direct (0 hops)', 'wper-checklist' )
		);

		return [ 'items' => [ $item ], 'events' => [ __( 'Redirect check: max ', 'wper-checklist' ) . $max_hops . __( ' hop(s)', 'wper-checklist' ) ], 'done' => true ];
	}
}
