<?php
/**
 * 공용 HTTP 프로브 — 루프백 · 외부 API 호출의 단일 통로.
 *
 * ⚠ 모든 HTTP 는 wp_remote_* 만 쓴다. file_get_contents(url) 은 하드닝된 서버에서
 *   allow_url_fopen=Off 라 죽는다 — 그 설정이 우리 검사 항목이기도 하다.
 * ⚠ TTFB 는 cURL 의 STARTTRANSFER 를 시도하고, 못 얻으면 전체 왕복 시간으로
 *   폴백한다 (루프백에서는 연결 비용이 ~0 이라 둘이 거의 같다).
 * ⚠ 루프백은 **절대 병렬로 쏘지 않는다** — REST 워커 안에서 자기 사이트를 부르므로
 *   PHP-FPM 워커가 1개면 데드락이다. 순차 + 짧은 타임아웃이 규약이다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPER_Checklist_HTTP {

	/** @var mixed 마지막 요청의 cURL 핸들 (http_api_curl 훅이 채운다). */
	private static $handle = null;

	/**
	 * GET 1회. 반환:
	 * [ ok, code, headers(소문자 키), body, time_ms, ttfb_ms, error ]
	 */
	public static function get( string $url, array $args = [] ): array {
		$defaults = [
			'timeout'     => 10,
			'redirection' => 0,
			'user-agent'  => 'wper-checklist/' . WPER_CHECKLIST_VERSION,
		];
		$args = array_merge( $defaults, $args );

		self::$handle = null;
		add_action( 'http_api_curl', [ __CLASS__, 'stash_handle' ], 10, 1 );

		$start    = microtime( true );
		$response = wp_remote_get( $url, $args );
		$total_ms = ( microtime( true ) - $start ) * 1000;

		remove_action( 'http_api_curl', [ __CLASS__, 'stash_handle' ], 10 );

		if ( is_wp_error( $response ) ) {
			return [
				'ok'      => false,
				'code'    => 0,
				'headers' => [],
				'body'    => '',
				'time_ms' => $total_ms,
				'ttfb_ms' => null,
				'error'   => $response->get_error_message(),
			];
		}

		$ttfb = null;
		if ( self::$handle instanceof CurlHandle ) {
			$info = @curl_getinfo( self::$handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- 핸들이 이미 닫혔을 수 있다: 폴백 있음.
			if ( is_array( $info ) && ! empty( $info['starttransfer_time'] ) ) {
				$ttfb = (float) $info['starttransfer_time'] * 1000;
			}
		}
		self::$handle = null;

		$headers = wp_remote_retrieve_headers( $response );
		$headers = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;
		$headers = array_change_key_case( $headers, CASE_LOWER );

		return [
			'ok'      => true,
			'code'    => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => $headers,
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'time_ms' => $total_ms,
			'ttfb_ms' => $ttfb ?? $total_ms,
			'error'   => '',
		];
	}

	/**
	 * 리다이렉트를 수동 추적해 홉 수를 센다 (최대 $max).
	 * 반환: [ hops, final_code, final_url ]
	 */
	public static function hops( string $url, int $max = 4 ): array {
		$hops    = 0;
		$current = $url;
		$code    = 0;

		while ( $hops <= $max ) {
			$res  = self::get( $current, [ 'timeout' => 8 ] );
			$code = $res['code'];

			if ( $code < 300 || $code >= 400 || empty( $res['headers']['location'] ) ) {
				break;
			}

			$loc     = is_array( $res['headers']['location'] ) ? end( $res['headers']['location'] ) : $res['headers']['location'];
			$current = WP_Http::make_absolute_url( $loc, $current );
			$hops++;
		}

		return [ 'hops' => $hops, 'code' => $code, 'url' => $current ];
	}

	/**
	 * http_api_curl 콜백 — 핸들을 잡아 둔다 (TTFB 용).
	 *
	 * @param mixed $handle cURL 핸들 (참조로 전달됨).
	 */
	public static function stash_handle( $handle ): void {
		self::$handle = $handle;
	}

	/**
	 * 중앙값 (3회 측정 규약 — 단발 수치를 리포트에 쓰지 않는다).
	 *
	 * @param float[] $values 측정값.
	 */
	public static function median( array $values ): float {
		if ( ! $values ) {
			return 0.0;
		}
		sort( $values );
		$n   = count( $values );
		$mid = (int) floor( $n / 2 );
		return ( $n % 2 ) ? $values[ $mid ] : ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2;
	}
}
