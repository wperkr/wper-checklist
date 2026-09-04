<?php
/**
 * 카테고리 ⑤ 서버 · 외부 설정 — PHP · wp-config · HTTP 헤더 · DB 변수.
 *
 * ⚠ 설정 **파일은 읽지 않는다** (open_basedir 가 우리 검사 항목이기도 하다):
 *   PHP 는 ini_get, wp-config 는 defined(), nginx 는 응답 헤더(행동 프로브),
 *   DB 는 SHOW VARIABLES — 현재 세션에서 보이는 값만. 권한이 없으면 확인 불가(skip).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPER_Checklist_Check_Server {

	const CAT = 'server';

	/** PHP 브랜치 → 보안 지원 종료일. */
	const PHP_EOL = [
		'7.4' => '2022-11-28',
		'8.0' => '2023-11-26',
		'8.1' => '2025-12-31',
		'8.2' => '2026-12-31',
		'8.3' => '2027-12-31',
		'8.4' => '2028-12-31',
		'8.5' => '2029-12-31',
	];

	public static function run( string $step_id, array $ctx ): array {
		switch ( $step_id ) {
			case 'server:php':
				return self::run_php();
			case 'server:wp':
				return self::run_wp();
			case 'server:headers':
				return self::run_headers( $ctx );
			case 'server:files':
				return self::run_files( $ctx );
			case 'server:db':
				return self::run_db();
		}
		return [ 'items' => [], 'events' => [], 'done' => true ];
	}

	/* ----------------------------------------------------------------- PHP */

	private static function run_php(): array {
		$items = [];

		// 버전 지원 여부.
		$branch = implode( '.', array_slice( explode( '.', PHP_VERSION ), 0, 2 ) );
		$eol    = self::PHP_EOL[ $branch ] ?? null;
		if ( null === $eol ) {
			$status = version_compare( $branch, '7.4', '<' ) ? 'fail' : 'skip';
			$note   = version_compare( $branch, '7.4', '<' ) ? sprintf( 'PHP %s — 지원 종료된 지 오래', PHP_VERSION ) : sprintf( 'PHP %s — EOL 표에 없음, 확인 불가', PHP_VERSION );
		} else {
			$days   = ( strtotime( $eol ) - time() ) / DAY_IN_SECONDS;
			$status = $days <= 0 ? 'fail' : ( $days < 365 ? 'warn' : 'pass' );
			$note   = sprintf( 'PHP %s — 보안 지원 %s까지', PHP_VERSION, $eol );
		}
		$items[] = wper_checklist_item( 'server:php-version', self::CAT, 'PHP 버전 지원', $status, 20, $note );

		$ini_off = static fn( $key ) => ! filter_var( ini_get( $key ), FILTER_VALIDATE_BOOLEAN );

		$items[] = wper_checklist_item(
			'server:expose-php', self::CAT, 'expose_php Off',
			$ini_off( 'expose_php' ) ? 'pass' : 'fail',
			10,
			'expose_php = ' . ( ini_get( 'expose_php' ) ?: 'Off' )
		);

		// disable_functions — 셸 계열이 막혀 있는가.
		$disabled = array_filter( array_map( 'trim', explode( ',', strtolower( (string) ini_get( 'disable_functions' ) ) ) ) );
		$shell    = [ 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen' ];
		$blocked  = count( array_intersect( $shell, $disabled ) );
		$items[]  = wper_checklist_item(
			'server:disable-functions', self::CAT, '셸 함수 차단 (disable_functions)',
			$blocked >= 4 ? 'pass' : ( $blocked >= 1 ? 'warn' : 'fail' ),
			15,
			sprintf( '셸 계열 6종 중 %d종 차단', $blocked )
		);

		// ⚠ curl_exec 는 차단하면 안 된다 — WP HTTP API 의 기본 전송이라 끄면 코어
		//   업데이트 · 플러그인 설치 · 외부 API 가 전부 죽는다. "위험 함수" 목록
		//   복붙의 대표 사고 (CLAUDE.md §Gotchas).
		$items[] = wper_checklist_item(
			'server:curl-alive', self::CAT, 'curl_exec 미차단',
			in_array( 'curl_exec', $disabled, true ) ? 'fail' : 'pass',
			10,
			in_array( 'curl_exec', $disabled, true ) ? '차단됨 — WP 외부 통신 전체 마비' : '사용 가능'
		);

		$items[] = wper_checklist_item(
			'server:url-fopen', self::CAT, 'allow_url_fopen Off',
			$ini_off( 'allow_url_fopen' ) ? 'pass' : 'warn',
			10,
			'allow_url_fopen = ' . ( ini_get( 'allow_url_fopen' ) ? 'On' : 'Off' )
		);

		$items[] = wper_checklist_item(
			'server:display-errors', self::CAT, 'display_errors Off',
			$ini_off( 'display_errors' ) ? 'pass' : 'fail',
			10,
			'display_errors = ' . ( ini_get( 'display_errors' ) ?: 'Off' )
		);

		$basedir = (string) ini_get( 'open_basedir' );
		$items[] = wper_checklist_item(
			'server:open-basedir', self::CAT, 'open_basedir 경로 격리',
			'' !== $basedir ? 'pass' : 'warn',
			10,
			'' !== $basedir ? '설정됨' : '미설정 — PHP 가 전체 파일시스템에 접근 가능'
		);

		return [ 'items' => $items, 'events' => [ 'PHP 설정 검사: ' . PHP_VERSION ], 'done' => true ];
	}

	/* ----------------------------------------------------------- wp-config */

	private static function run_wp(): array {
		$items = [];

		// WP_DEBUG — 로컬 환경이면 판정하지 않는다 (개발 편의가 정상).
		$is_local = ( defined( 'WPER_ENV' ) && 'production' !== constant( 'WPER_ENV' ) )
			|| in_array( wp_get_environment_type(), [ 'local', 'development' ], true );
		if ( $is_local ) {
			$items[] = wper_checklist_item( 'server:wp-debug', self::CAT, 'WP_DEBUG 비활성', 'skip', 10, '로컬/개발 환경 — 판정 제외' );
		} elseif ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			$items[] = wper_checklist_item( 'server:wp-debug', self::CAT, 'WP_DEBUG 비활성', 'pass', 10, 'WP_DEBUG = false' );
		} else {
			$display = defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY;
			$items[] = wper_checklist_item(
				'server:wp-debug', self::CAT, 'WP_DEBUG 비활성',
				$display ? 'fail' : 'warn',
				10,
				$display ? 'WP_DEBUG + 화면 표시 — 방문자에게 오류 노출' : 'WP_DEBUG on (로그 전용)'
			);
		}

		$items[] = wper_checklist_item(
			'server:file-edit', self::CAT, '파일 편집기 차단 (DISALLOW_FILE_EDIT)',
			defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ? 'pass' : 'fail',
			10,
			defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ? '차단됨' : '허용 — 관리자 탈취 시 셸 심기 최단 경로'
		);

		$charset = defined( 'DB_CHARSET' ) ? DB_CHARSET : '';
		$items[] = wper_checklist_item(
			'server:db-charset', self::CAT, 'DB_CHARSET utf8mb4',
			'utf8mb4' === $charset ? 'pass' : ( 'utf8' === $charset ? 'fail' : 'warn' ),
			10,
			'DB_CHARSET = ' . ( $charset ?: '(미정의)' ) . ( 'utf8' === $charset ? ' — 3바이트: 이모지·일부 한자 무음 손실' : '' )
		);

		return [ 'items' => $items, 'events' => [ 'wp-config 상수 검사 완료' ], 'done' => true ];
	}

	/* -------------------------------------------------------- 응답 헤더 */

	private static function run_headers( array $ctx ): array {
		$home = $ctx['urls']['home'] ?? home_url( '/' );
		$res  = WPER_Checklist_HTTP::get( $home );

		if ( ! $res['ok'] ) {
			$items = [
				wper_checklist_item( 'server:sec-headers', self::CAT, '보안 응답 헤더', 'skip', 25, '응답 수신 실패 — 확인 불가' ),
				wper_checklist_item( 'server:tokens', self::CAT, '서버 버전 은닉', 'skip', 10, '응답 수신 실패 — 확인 불가' ),
				wper_checklist_item( 'server:https', self::CAT, 'HTTPS 강제', 'skip', 10, '응답 수신 실패 — 확인 불가' ),
			];
			return [ 'items' => $items, 'events' => [ '헤더 검사: 수신 실패' ], 'done' => true ];
		}

		$h        = $res['headers'];
		$is_https = str_starts_with( $home, 'https://' );
		$items    = [];

		// 보안 헤더 5종 — 부분 점수 (있는 만큼).
		$wanted = [
			'x-content-type-options' => static fn( $v ) => false !== stripos( $v, 'nosniff' ),
			'x-frame-options'        => static fn( $v ) => '' !== $v,
			'referrer-policy'        => static fn( $v ) => '' !== $v,
			'permissions-policy'     => static fn( $v ) => '' !== $v,
			'strict-transport-security' => static fn( $v ) => '' !== $v,
		];
		$present    = [];
		$applicable = $is_https ? 5 : 4; // HSTS 는 https 전용.
		foreach ( $wanted as $name => $ok ) {
			if ( 'strict-transport-security' === $name && ! $is_https ) {
				continue;
			}
			$val = $h[ $name ] ?? '';
			$val = is_array( $val ) ? implode( ',', $val ) : (string) $val;
			// CSP frame-ancestors 는 X-Frame-Options 의 상위 호환.
			if ( 'x-frame-options' === $name && '' === $val ) {
				$csp = (string) ( is_array( $h['content-security-policy'] ?? null ) ? implode( ',', $h['content-security-policy'] ) : ( $h['content-security-policy'] ?? '' ) );
				$val = false !== stripos( $csp, 'frame-ancestors' ) ? $csp : '';
			}
			if ( '' !== $val && $ok( $val ) ) {
				$present[] = $name;
			}
		}
		$n       = count( $present );
		$earned  = 25 * $n / max( 1, $applicable );
		$missing = array_diff( array_keys( $wanted ), $present, $is_https ? [] : [ 'strict-transport-security' ] );
		$items[] = wper_checklist_item(
			'server:sec-headers', self::CAT, '보안 응답 헤더',
			$n >= $applicable ? 'pass' : ( $n > 0 ? 'warn' : 'fail' ),
			25,
			sprintf( '%d/%d 존재%s', $n, $applicable, $missing ? ' — 누락: ' . implode( ', ', $missing ) : '' ),
			[],
			$earned
		);

		// 서버 버전 은닉.
		$server  = (string) ( is_array( $h['server'] ?? null ) ? implode( ',', $h['server'] ) : ( $h['server'] ?? '' ) );
		$powered = isset( $h['x-powered-by'] );
		if ( preg_match( '/\d+\.\d+/', $server ) ) {
			$items[] = wper_checklist_item( 'server:tokens', self::CAT, '서버 버전 은닉', 'fail', 10, 'Server: ' . $server . ' — 버전 노출' );
		} elseif ( $powered ) {
			$items[] = wper_checklist_item( 'server:tokens', self::CAT, '서버 버전 은닉', 'warn', 10, 'X-Powered-By 헤더 노출' );
		} else {
			$items[] = wper_checklist_item( 'server:tokens', self::CAT, '서버 버전 은닉', 'pass', 10, 'Server: ' . ( $server ?: '(없음)' ) );
		}

		// HTTPS 강제.
		if ( ! $is_https ) {
			$items[] = wper_checklist_item( 'server:https', self::CAT, 'HTTPS 강제', 'fail', 10, '사이트 주소가 http' );
		} else {
			$http_variant = 'http://' . substr( $home, 8 );
			$hop          = WPER_Checklist_HTTP::hops( $http_variant, 2 );
			$redirected   = str_starts_with( $hop['url'], 'https://' ) && $hop['hops'] > 0;
			$fsa          = defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN;
			$items[]      = wper_checklist_item(
				'server:https', self::CAT, 'HTTPS 강제',
				$redirected && $fsa ? 'pass' : 'warn',
				10,
				( $redirected ? 'http→https 리다이렉트 OK' : 'http 접속이 https 로 이동하지 않음' ) . ( $fsa ? ' · FORCE_SSL_ADMIN on' : ' · FORCE_SSL_ADMIN off' )
			);
		}

		return [ 'items' => $items, 'events' => [ sprintf( '보안 헤더 %d/%d', $n, $applicable ) ], 'done' => true ];
	}

	/* ------------------------------------------------------ 민감 파일 */

	private static function run_files( array $ctx ): array {
		$home   = untrailingslashit( $ctx['urls']['home'] ?? home_url() );
		$probes = [];

		// wp-config.php — 200 이어도 본문이 비면 노출은 아니다 (PHP 가 실행됨). 403 이 정답.
		$c = WPER_Checklist_HTTP::get( $home . '/wp-config.php' );
		if ( str_contains( $c['body'], 'DB_NAME' ) || str_contains( $c['body'], 'DB_PASSWORD' ) ) {
			$probes['wp-config.php'] = [ 'fail', '소스 노출!' ];
		} elseif ( in_array( $c['code'], [ 403, 404 ], true ) ) {
			$probes['wp-config.php'] = [ 'pass', 'HTTP ' . $c['code'] ];
		} else {
			$probes['wp-config.php'] = [ 'warn', sprintf( 'HTTP %d (실행되나 노출 없음 — 차단 권장)', $c['code'] ) ];
		}

		// .git — 저장소 노출.
		$g = WPER_Checklist_HTTP::get( $home . '/.git/HEAD' );
		$probes['.git/HEAD'] = ( 200 === $g['code'] && str_contains( $g['body'], 'ref:' ) )
			? [ 'fail', '저장소 노출!' ]
			: [ 'pass', 'HTTP ' . $g['code'] ];

		// uploads 내 PHP 실행 — **존재하지 않는** 경로를 프로브한다. 실제 .php 를 써 넣는
		// 검사는 하지 않는다: 요청이 중단되면 웹 도달 경로에 실행 파일이 남는 RCE 창이 된다.
		$rand = 'wper-checklist-probe-' . bin2hex( random_bytes( 6 ) ) . '.php';
		$u    = WPER_Checklist_HTTP::get( $home . '/wp-content/uploads/' . $rand );
		if ( 403 === $u['code'] ) {
			$probes['uploads *.php'] = [ 'pass', 'HTTP 403 — 실행 차단 규칙 확인' ];
		} else {
			$probes['uploads *.php'] = [ 'skip', sprintf( 'HTTP %d — 부재 파일로는 판정 불가 (확인 불가)', $u['code'] ) ];
		}

		// 부분 점수: skip 프로브는 분모에서 제외.
		$applicable = count( array_filter( $probes, static fn( $p ) => 'skip' !== $p[0] ) );
		$passed     = count( array_filter( $probes, static fn( $p ) => 'pass' === $p[0] ) );
		$failed     = count( array_filter( $probes, static fn( $p ) => 'fail' === $p[0] ) );
		$earned     = $applicable > 0 ? 15 * $passed / $applicable : 0;
		$status     = $failed > 0 ? 'fail' : ( $passed === $applicable && $applicable > 0 ? 'pass' : 'warn' );

		$note  = implode( ' · ', array_map( static fn( $k, $p ) => "$k: {$p[1]}", array_keys( $probes ), $probes ) );
		$items = [ wper_checklist_item( 'server:files', self::CAT, '민감 경로 차단', $status, 15, $note, [], $earned ) ];

		return [ 'items' => $items, 'events' => [ '민감 경로 프로브 3종 완료' ], 'done' => true ];
	}

	/* ------------------------------------------------------------- DB 변수 */

	private static function run_db(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SHOW VARIABLES WHERE Variable_name IN ('version', 'character_set_server', 'local_infile', 'slow_query_log', 'long_query_time')",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			$item = wper_checklist_item( 'server:db-vars', self::CAT, 'DB 서버 설정', 'skip', 25, 'SHOW VARIABLES 권한 없음 — 확인 불가' );
			return [ 'items' => [ $item ], 'events' => [ 'DB 변수: 권한 없음' ], 'done' => true ];
		}

		$vars = [];
		foreach ( $rows as $row ) {
			$vars[ strtolower( $row['Variable_name'] ) ] = (string) $row['Value'];
		}

		$checks = [];

		// 버전 지원 — MariaDB ≥10.6 / MySQL ≥8.0.
		$raw_ver = $vars['version'] ?? '';
		if ( false !== stripos( $raw_ver, 'mariadb' ) ) {
			preg_match( '/(\d+\.\d+)/', $raw_ver, $vm );
			$checks['버전'] = version_compare( $vm[1] ?? '0', '10.6', '>=' ) ? 'ok' : 'old';
		} elseif ( '' !== $raw_ver ) {
			preg_match( '/^(\d+\.\d+)/', $raw_ver, $vm );
			$checks['버전'] = version_compare( $vm[1] ?? '0', '8.0', '>=' ) ? 'ok' : 'old';
		}

		if ( isset( $vars['character_set_server'] ) ) {
			$checks['서버 문자셋'] = 'utf8mb4' === $vars['character_set_server'] ? 'ok' : 'warn';
		}
		if ( isset( $vars['local_infile'] ) ) {
			$checks['local_infile'] = 'OFF' === strtoupper( $vars['local_infile'] ) ? 'ok' : 'warn';
		}
		if ( isset( $vars['slow_query_log'] ) ) {
			$checks['슬로 쿼리 로그'] = 'ON' === strtoupper( $vars['slow_query_log'] ) ? 'ok' : 'warn';
		}

		$total  = count( $checks );
		$ok_n   = count( array_filter( $checks, static fn( $s ) => 'ok' === $s ) );
		$old    = in_array( 'old', $checks, true );
		$earned = $total > 0 ? 25 * $ok_n / $total : 0;
		$status = $old ? 'fail' : ( $ok_n === $total ? 'pass' : 'warn' );

		$detail = implode( ' · ', array_map( static fn( $k, $s ) => $k . ( 'ok' === $s ? ' OK' : ( 'old' === $s ? ' 구버전' : ' 권장 아님' ) ), array_keys( $checks ), $checks ) );
		$item   = wper_checklist_item( 'server:db-vars', self::CAT, 'DB 서버 설정', $status, 25, $raw_ver . ' — ' . $detail, [], $earned );

		return [ 'items' => [ $item ], 'events' => [ 'DB 변수 검사: ' . $raw_ver ], 'done' => true ];
	}
}
