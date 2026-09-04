<?php
/**
 * DB 캡처 토큰 엔진 — 카테고리 ② 의 측정 장치.
 *
 * "capture ready → urls hit → capture output":
 *   1. 관리자 REST(/run) 가 토큰을 발급하고(ready)
 *   2. 러너가 공개 URL 을 `?wper_check=<token>` 으로 루프백 호출하면(hit)
 *   3. 이 클래스가 그 요청의 쿼리 통계를 응답 헤더로 돌려준다(output)
 *
 * ⭐ 보안 경계 — 이 코드는 **공개 프론트엔드에서** 실행된다:
 *   · 토큰 불일치 = 부작용 0. hash_equals 비교 전에는 어떤 상태도 만들지 않는다
 *   · 헤더에는 **정수 집계만** 나간다. SQL 텍스트는 네트워크로 나가지 않는다 —
 *     슬로 쿼리 지문(리터럴 제거)은 서버측 트랜션트에 남고 러너가 읽은 뒤 지운다
 *   · 토큰은 15분 TTL + 진단 1회 단위. 유출돼도 얻는 것은 공개 페이지의 쿼리 개수뿐
 *
 * ⭐ 토큰 쿼리스트링이 페이지 캐시(FastCGI 등)를 자연히 우회한다 — 캐시된 응답은
 *    DB 를 아예 안 타므로, 이 카테고리는 **비캐시 경로**를 재는 것이 의도다.
 *    (카테고리 ① 지연시간이 캐시 경로 = 실사용자 경로를 잰다. 둘을 섞지 말 것.)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPER_Checklist_Capture {

	const TOKEN_OPT = 'wper_checklist_cap_token';
	const DATA_OPT  = 'wper_checklist_cap_data';
	const TTL       = 15 * MINUTE_IN_SECONDS;
	const SLOW_MS   = 20.0;

	/** @var bool 이 요청이 유효 토큰으로 무장되었는가. */
	private static $armed = false;

	/**
	 * 진단 시작 시 토큰 발급. 동시 진단은 없다(관리자 1명 전제) — 새 발급이 이전을 대체한다.
	 */
	public static function mint( int $run_id ): string {
		$token = bin2hex( random_bytes( 16 ) );
		set_transient( self::TOKEN_OPT, [ 'token' => $token, 'run' => $run_id ], self::TTL );
		delete_transient( self::DATA_OPT );
		return $token;
	}

	/**
	 * 부트스트랩 최상단에서 호출된다 (SAVEQUERIES 는 메인 쿼리 전에 정의돼야 한다).
	 */
	public static function maybe_arm(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 공개 측정 엔드포인트: 인증은 토큰 hash_equals.
		if ( ! isset( $_GET['wper_check'] ) || is_admin() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$given = (string) wp_unslash( $_GET['wper_check'] );

		if ( ! preg_match( '/^[a-f0-9]{32}$/', $given ) ) {
			return;
		}

		$stored = get_transient( self::TOKEN_OPT );

		if ( ! is_array( $stored ) || empty( $stored['token'] ) || ! hash_equals( $stored['token'], $given ) ) {
			return; // 부작용 0 — 여기까지 읽기만 했다.
		}

		self::$armed = true;

		if ( ! defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true );
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		/*
		 * ⚠ 헤더는 shutdown 에서 쏜다 — 그 시점엔 보통 본문이 이미 흘러나가
		 * headers_sent() 다. 그래서 요청 전체를 출력 버퍼로 감싼다: 버퍼가
		 * 최종 flush 되기 전까지 헤더를 보낼 수 있다. 무장된 요청만 버퍼링하므로
		 * 평시 트래픽에는 영향이 없다.
		 */
		ob_start();

		// wp_ob_end_flush_all 이 shutdown prio 1 에 flush 한다 — 그 전에 헤더를 쏜다.
		add_action( 'shutdown', [ __CLASS__, 'emit' ], 0 );
	}

	/**
	 * 집계 + 방출. 헤더는 정수만.
	 */
	public static function emit(): void {
		global $wpdb, $wp_object_cache;

		if ( ! self::$armed ) {
			return;
		}

		$count   = (int) get_num_queries();
		$time_ms = 0.0;
		$slow    = 0;
		$dupes   = 0;
		$seen    = [];
		$slowest = [];

		if ( ! empty( $wpdb->queries ) && is_array( $wpdb->queries ) ) {
			foreach ( $wpdb->queries as $q ) {
				$sql      = (string) ( $q[0] ?? '' );
				$elapsed  = (float) ( $q[1] ?? 0 ) * 1000;
				$time_ms += $elapsed;

				$fp = self::fingerprint( $sql );

				if ( isset( $seen[ $fp ] ) ) {
					$dupes++;
				}
				$seen[ $fp ] = true;

				if ( $elapsed > self::SLOW_MS ) {
					$slow++;
					$slowest[] = [ 'fp' => $fp, 'ms' => round( $elapsed, 1 ) ];
				}
			}
		}

		if ( ! headers_sent() ) {
			header( 'X-WPER-Check-Queries: ' . $count );
			header( 'X-WPER-Check-Time-Ms: ' . (int) round( $time_ms ) );
			header( 'X-WPER-Check-Slow: ' . $slow );
			header( 'X-WPER-Check-Dupes: ' . $dupes );
			header( 'X-WPER-Check-Peak-Mem: ' . memory_get_peak_usage( true ) );

			$hits   = is_object( $wp_object_cache ) && property_exists( $wp_object_cache, 'cache_hits' ) ? (int) $wp_object_cache->cache_hits : -1;
			$misses = is_object( $wp_object_cache ) && property_exists( $wp_object_cache, 'cache_misses' ) ? (int) $wp_object_cache->cache_misses : -1;
			header( 'X-WPER-Check-Cache-Hits: ' . $hits );
			header( 'X-WPER-Check-Cache-Misses: ' . $misses );
		}

		// 슬로 쿼리 지문은 서버측에만 — 브라우저·프록시 로그에 SQL 형상이 남지 않는다.
		if ( $slowest ) {
			usort( $slowest, static fn( $a, $b ) => $b['ms'] <=> $a['ms'] );
			$data = get_transient( self::DATA_OPT );
			$data = is_array( $data ) ? $data : [];

			$uri          = remove_query_arg( 'wper_check', (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$data[ $uri ] = array_slice( $slowest, 0, 3 );
			set_transient( self::DATA_OPT, $data, self::TTL );
		}
	}

	/**
	 * 러너가 슬로 쿼리 지문을 읽고 지운다 — 1회성.
	 */
	public static function read_and_clear(): array {
		$data = get_transient( self::DATA_OPT );
		delete_transient( self::DATA_OPT );
		return is_array( $data ) ? $data : [];
	}

	/**
	 * 진단 종료 시 토큰 폐기.
	 */
	public static function burn(): void {
		delete_transient( self::TOKEN_OPT );
		delete_transient( self::DATA_OPT );
	}

	/**
	 * 리터럴 제거 지문 — 같은 형상의 쿼리(N+1)를 묶고, 값(PII 포함 가능)은 버린다.
	 */
	private static function fingerprint( string $sql ): string {
		$sql = preg_replace( "/'(?:[^'\\\\]|\\\\.)*'/", '?', $sql );
		$sql = preg_replace( '/\b\d+\b/', '?', (string) $sql );
		$sql = preg_replace( '/\s+/', ' ', (string) $sql );
		$sql = strtolower( trim( (string) $sql ) );
		// IN (?, ?, ?) → IN (?) — 항목 수 차이로 지문이 갈리지 않게.
		$sql = preg_replace( '/\(\s*\?(?:\s*,\s*\?)*\s*\)/', '(?)', $sql );
		return substr( md5( (string) $sql ), 0, 12 ) . ':' . substr( (string) $sql, 0, 80 );
	}
}
