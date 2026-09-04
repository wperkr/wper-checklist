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
			/* translators: %s: PHP version number. */
			$note   = version_compare( $branch, '7.4', '<' ) ? sprintf( __( 'PHP %s — long past end of life', 'wper-checklist' ), PHP_VERSION ) : sprintf( __( 'PHP %s — not in the EOL table, unverifiable', 'wper-checklist' ), PHP_VERSION );
		} else {
			$days   = ( strtotime( $eol ) - time() ) / DAY_IN_SECONDS;
			$status = $days <= 0 ? 'fail' : ( $days < 365 ? 'warn' : 'pass' );
			/* translators: 1: PHP version number, 2: end-of-support date. */
			$note   = sprintf( __( 'PHP %1$s — security support until %2$s', 'wper-checklist' ), PHP_VERSION, $eol );
		}
		$items[] = wper_checklist_item( 'server:php-version', self::CAT, __( 'PHP version supported', 'wper-checklist' ), $status, 20, $note );

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
			'server:disable-functions', self::CAT, __( 'Shell functions disabled', 'wper-checklist' ),
			$blocked >= 4 ? 'pass' : ( $blocked >= 1 ? 'warn' : 'fail' ),
			15,
			/* translators: %d: how many shell functions are disabled. */
			sprintf( __( '%d of 6 shell functions blocked', 'wper-checklist' ), $blocked )
		);

		// ⚠ curl_exec 는 차단하면 안 된다 — WP HTTP API 의 기본 전송이라 끄면 코어
		//   업데이트 · 플러그인 설치 · 외부 API 가 전부 죽는다. "위험 함수" 목록
		//   복붙의 대표 사고 (CLAUDE.md §Gotchas).
		$items[] = wper_checklist_item(
			'server:curl-alive', self::CAT, __( 'curl_exec not disabled', 'wper-checklist' ),
			in_array( 'curl_exec', $disabled, true ) ? 'fail' : 'pass',
			10,
			in_array( 'curl_exec', $disabled, true ) ? __( 'Disabled — kills all WP outbound HTTP', 'wper-checklist' ) : __( 'Available', 'wper-checklist' )
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
			'server:open-basedir', self::CAT, __( 'open_basedir path isolation', 'wper-checklist' ),
			'' !== $basedir ? 'pass' : 'warn',
			10,
			'' !== $basedir ? __( 'Configured', 'wper-checklist' ) : __( 'Not set — PHP can reach the entire filesystem', 'wper-checklist' )
		);

		return [ 'items' => $items, 'events' => [ __( 'PHP settings checked: ', 'wper-checklist' ) . PHP_VERSION ], 'done' => true ];
	}

	/* ----------------------------------------------------------- wp-config */

	private static function run_wp(): array {
		$items = [];

		// WP_DEBUG — 로컬 환경이면 판정하지 않는다 (개발 편의가 정상).
		$is_local = ( defined( 'WPER_ENV' ) && 'production' !== constant( 'WPER_ENV' ) )
			|| in_array( wp_get_environment_type(), [ 'local', 'development' ], true );
		if ( $is_local ) {
			$items[] = wper_checklist_item( 'server:wp-debug', self::CAT, __( 'WP_DEBUG disabled', 'wper-checklist' ), 'skip', 10, __( 'Local/dev environment — excluded from judgment', 'wper-checklist' ) );
		} elseif ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			$items[] = wper_checklist_item( 'server:wp-debug', self::CAT, __( 'WP_DEBUG disabled', 'wper-checklist' ), 'pass', 10, 'WP_DEBUG = false' );
		} else {
			$display = defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY;
			$items[] = wper_checklist_item(
				'server:wp-debug', self::CAT, __( 'WP_DEBUG disabled', 'wper-checklist' ),
				$display ? 'fail' : 'warn',
				10,
				$display ? __( 'WP_DEBUG with display — errors shown to visitors', 'wper-checklist' ) : __( 'WP_DEBUG on (log only)', 'wper-checklist' )
			);
		}

		$items[] = wper_checklist_item(
			'server:file-edit', self::CAT, __( 'File editor disabled (DISALLOW_FILE_EDIT)', 'wper-checklist' ),
			defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ? 'pass' : 'fail',
			10,
			defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ? __( 'Blocked', 'wper-checklist' ) : __( 'Allowed — the shortest path to a web shell after admin takeover', 'wper-checklist' )
		);

		$charset = defined( 'DB_CHARSET' ) ? DB_CHARSET : '';
		$items[] = wper_checklist_item(
			'server:db-charset', self::CAT, 'DB_CHARSET utf8mb4',
			'utf8mb4' === $charset ? 'pass' : ( 'utf8' === $charset ? 'fail' : 'warn' ),
			10,
			'DB_CHARSET = ' . ( $charset ?: __( '(undefined)', 'wper-checklist' ) ) . ( 'utf8' === $charset ? __( ' — 3-byte: silent data loss for emoji and some CJK', 'wper-checklist' ) : '' )
		);

		return [ 'items' => $items, 'events' => [ __( 'wp-config constants checked', 'wper-checklist' ) ], 'done' => true ];
	}

	/* -------------------------------------------------------- 응답 헤더 */

	private static function run_headers( array $ctx ): array {
		$home = $ctx['urls']['home'] ?? home_url( '/' );
		$res  = WPER_Checklist_HTTP::get( $home );

		if ( ! $res['ok'] ) {
			$items = [
				wper_checklist_item( 'server:sec-headers', self::CAT, __( 'Security response headers', 'wper-checklist' ), 'skip', 25, __( 'No response received — unverifiable', 'wper-checklist' ) ),
				wper_checklist_item( 'server:tokens', self::CAT, __( 'Server version hidden', 'wper-checklist' ), 'skip', 10, __( 'No response received — unverifiable', 'wper-checklist' ) ),
				wper_checklist_item( 'server:https', self::CAT, __( 'HTTPS enforced', 'wper-checklist' ), 'skip', 10, __( 'No response received — unverifiable', 'wper-checklist' ) ),
			];
			return [ 'items' => $items, 'events' => [ __( 'Header check: request failed', 'wper-checklist' ) ], 'done' => true ];
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
			'server:sec-headers', self::CAT, __( 'Security response headers', 'wper-checklist' ),
			$n >= $applicable ? 'pass' : ( $n > 0 ? 'warn' : 'fail' ),
			25,
			/* translators: 1: headers present, 2: headers expected, 3: optional suffix listing what is missing. */
			sprintf( __( '%1$d of %2$d present%3$s', 'wper-checklist' ), $n, $applicable, $missing ? __( ' — missing: ', 'wper-checklist' ) . implode( ', ', $missing ) : '' ),
			[],
			$earned
		);

		// 서버 버전 은닉.
		$server  = (string) ( is_array( $h['server'] ?? null ) ? implode( ',', $h['server'] ) : ( $h['server'] ?? '' ) );
		$powered = isset( $h['x-powered-by'] );
		if ( preg_match( '/\d+\.\d+/', $server ) ) {
			$items[] = wper_checklist_item( 'server:tokens', self::CAT, __( 'Server version hidden', 'wper-checklist' ), 'fail', 10, 'Server: ' . $server . __( ' — version exposed', 'wper-checklist' ) );
		} elseif ( $powered ) {
			$items[] = wper_checklist_item( 'server:tokens', self::CAT, __( 'Server version hidden', 'wper-checklist' ), 'warn', 10, __( 'X-Powered-By header exposed', 'wper-checklist' ) );
		} else {
			$items[] = wper_checklist_item( 'server:tokens', self::CAT, __( 'Server version hidden', 'wper-checklist' ), 'pass', 10, 'Server: ' . ( $server ?: __( '(none)', 'wper-checklist' ) ) );
		}

		// HTTPS 강제.
		if ( ! $is_https ) {
			$items[] = wper_checklist_item( 'server:https', self::CAT, __( 'HTTPS enforced', 'wper-checklist' ), 'fail', 10, __( 'Site address is http', 'wper-checklist' ) );
		} else {
			$http_variant = 'http://' . substr( $home, 8 );
			$hop          = WPER_Checklist_HTTP::hops( $http_variant, 2 );
			$redirected   = str_starts_with( $hop['url'], 'https://' ) && $hop['hops'] > 0;
			$fsa          = defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN;
			$items[]      = wper_checklist_item(
				'server:https', self::CAT, __( 'HTTPS enforced', 'wper-checklist' ),
				$redirected && $fsa ? 'pass' : 'warn',
				10,
				( $redirected ? __( 'http→https redirect OK', 'wper-checklist' ) : __( 'http does not redirect to https', 'wper-checklist' ) ) . ( $fsa ? ' · FORCE_SSL_ADMIN on' : ' · FORCE_SSL_ADMIN off' )
			);
		}

		/* translators: 1: headers present, 2: headers expected. */
		return [ 'items' => $items, 'events' => [ sprintf( __( 'Security headers %1$d/%2$d', 'wper-checklist' ), $n, $applicable ) ], 'done' => true ];
	}

	/* ------------------------------------------------------ 민감 파일 */

	private static function run_files( array $ctx ): array {
		$home   = untrailingslashit( $ctx['urls']['home'] ?? home_url() );
		$probes = [];

		// wp-config.php — 200 이어도 본문이 비면 노출은 아니다 (PHP 가 실행됨). 403 이 정답.
		$c = WPER_Checklist_HTTP::get( $home . '/wp-config.php' );
		if ( str_contains( $c['body'], 'DB_NAME' ) || str_contains( $c['body'], 'DB_PASSWORD' ) ) {
			$probes['wp-config.php'] = [ 'fail', __( 'source exposed!', 'wper-checklist' ) ];
		} elseif ( in_array( $c['code'], [ 403, 404 ], true ) ) {
			$probes['wp-config.php'] = [ 'pass', 'HTTP ' . $c['code'] ];
		} else {
			/* translators: %d: HTTP status code. */
			$probes['wp-config.php'] = [ 'warn', sprintf( __( 'HTTP %d (executes but exposes nothing — blocking recommended)', 'wper-checklist' ), $c['code'] ) ];
		}

		// .git — 저장소 노출.
		$g = WPER_Checklist_HTTP::get( $home . '/.git/HEAD' );
		$probes['.git/HEAD'] = ( 200 === $g['code'] && str_contains( $g['body'], 'ref:' ) )
			? [ 'fail', __( 'repository exposed!', 'wper-checklist' ) ]
			: [ 'pass', 'HTTP ' . $g['code'] ];

		// uploads 내 PHP 실행 — **존재하지 않는** 경로를 프로브한다. 실제 .php 를 써 넣는
		// 검사는 하지 않는다: 요청이 중단되면 웹 도달 경로에 실행 파일이 남는 RCE 창이 된다.
		$rand = 'wper-checklist-probe-' . bin2hex( random_bytes( 6 ) ) . '.php';
		$u    = WPER_Checklist_HTTP::get( $home . '/wp-content/uploads/' . $rand );
		if ( 403 === $u['code'] ) {
			$probes['uploads *.php'] = [ 'pass', __( 'HTTP 403 — execution-blocking rule confirmed', 'wper-checklist' ) ];
		} else {
			/* translators: %d: HTTP status code. */
			$probes['uploads *.php'] = [ 'skip', sprintf( __( 'HTTP %d — cannot be judged from a non-existent file (unverifiable)', 'wper-checklist' ), $u['code'] ) ];
		}

		// 부분 점수: skip 프로브는 분모에서 제외.
		$applicable = count( array_filter( $probes, static fn( $p ) => 'skip' !== $p[0] ) );
		$passed     = count( array_filter( $probes, static fn( $p ) => 'pass' === $p[0] ) );
		$failed     = count( array_filter( $probes, static fn( $p ) => 'fail' === $p[0] ) );
		$earned     = $applicable > 0 ? 15 * $passed / $applicable : 0;
		$status     = $failed > 0 ? 'fail' : ( $passed === $applicable && $applicable > 0 ? 'pass' : 'warn' );

		$note  = implode( ' · ', array_map( static fn( $k, $p ) => "$k: {$p[1]}", array_keys( $probes ), $probes ) );
		$items = [ wper_checklist_item( 'server:files', self::CAT, __( 'Sensitive paths blocked', 'wper-checklist' ), $status, 15, $note, [], $earned ) ];

		return [ 'items' => $items, 'events' => [ __( '3 sensitive-path probes complete', 'wper-checklist' ) ], 'done' => true ];
	}

	/* ------------------------------------------------------------- DB 변수 */

	private static function run_db(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SHOW VARIABLES WHERE Variable_name IN ('version', 'character_set_server', 'local_infile', 'slow_query_log', 'long_query_time')",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			$item = wper_checklist_item( 'server:db-vars', self::CAT, __( 'DB server settings', 'wper-checklist' ), 'skip', 25, __( 'No SHOW VARIABLES privilege — unverifiable', 'wper-checklist' ) );
			return [ 'items' => [ $item ], 'events' => [ __( 'DB variables: permission denied', 'wper-checklist' ) ], 'done' => true ];
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
			$checks[__( 'version', 'wper-checklist' )] = version_compare( $vm[1] ?? '0', '10.6', '>=' ) ? 'ok' : 'old';
		} elseif ( '' !== $raw_ver ) {
			preg_match( '/^(\d+\.\d+)/', $raw_ver, $vm );
			$checks[__( 'version', 'wper-checklist' )] = version_compare( $vm[1] ?? '0', '8.0', '>=' ) ? 'ok' : 'old';
		}

		if ( isset( $vars['character_set_server'] ) ) {
			$checks[__( 'server charset', 'wper-checklist' )] = 'utf8mb4' === $vars['character_set_server'] ? 'ok' : 'warn';
		}
		if ( isset( $vars['local_infile'] ) ) {
			$checks['local_infile'] = 'OFF' === strtoupper( $vars['local_infile'] ) ? 'ok' : 'warn';
		}
		if ( isset( $vars['slow_query_log'] ) ) {
			$checks[__( 'slow query log', 'wper-checklist' )] = 'ON' === strtoupper( $vars['slow_query_log'] ) ? 'ok' : 'warn';
		}

		$total  = count( $checks );
		$ok_n   = count( array_filter( $checks, static fn( $s ) => 'ok' === $s ) );
		$old    = in_array( 'old', $checks, true );
		$earned = $total > 0 ? 25 * $ok_n / $total : 0;
		$status = $old ? 'fail' : ( $ok_n === $total ? 'pass' : 'warn' );

		$detail = implode( ' · ', array_map( static fn( $k, $s ) => $k . ( 'ok' === $s ? ' OK' : ( 'old' === $s ? __( ' outdated', 'wper-checklist' ) : __( ' not recommended', 'wper-checklist' ) ) ), array_keys( $checks ), $checks ) );
		$item   = wper_checklist_item( 'server:db-vars', self::CAT, __( 'DB server settings', 'wper-checklist' ), $status, 25, $raw_ver . ' — ' . $detail, [], $earned );

		return [ 'items' => [ $item ], 'events' => [ __( 'DB variables checked: ', 'wper-checklist' ) . $raw_ver ], 'done' => true ];
	}
}
