<?php
/**
 * 카테고리 ④ WP 취약점 — 코어 · 플러그인 · 테마 CVE + 프론트 JS + 노출면 프로브.
 *
 * ⭐ 데이터 출처는 wpvulnerability.net 무료 API (키 불요). **API 실패 = 확인 불가(skip)**
 *    이지 fail 이 아니다 — 네트워크 문제로 점수가 0 이 되면 리포트 신뢰가 무너진다.
 * ⭐ 플러그인 조회는 슬러그당 REST 스텝 1개 (커서 페이지네이션) — 무료 API 예절 +
 *    max_execution_time 안전.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPER_Checklist_Check_Vuln_WP {

	const CAT = 'vuln';
	const API = 'https://www.wpvulnerability.net/';

	public static function run( string $step_id, array $ctx ): array {
		switch ( $step_id ) {
			case 'vuln:core':
				return self::run_core();
			case 'vuln:plugins':
				return self::run_plugins( $ctx );
			case 'vuln:themes':
				return self::run_themes();
			case 'vuln:js':
				return self::run_js( $ctx );
			case 'vuln:probes':
				return self::run_probes( $ctx );
		}
		return [ 'items' => [], 'events' => [], 'done' => true ];
	}

	/* ---------------------------------------------------------------- 코어 */

	private static function run_core(): array {
		$version = get_bloginfo( 'version' );
		$items   = [];

		$hits = self::lookup( 'core/' . $version, $version );
		if ( null === $hits ) {
			$items[] = wper_checklist_item( 'vuln:core', self::CAT, __( 'Core known vulnerabilities', 'wper-checklist' ), 'skip', 40, __( 'API lookup failed — unverifiable', 'wper-checklist' ) );
		} else {
			$items[] = wper_checklist_item(
				'vuln:core', self::CAT, __( 'Core known vulnerabilities', 'wper-checklist' ),
				$hits ? 'fail' : 'pass',
				40,
				/* translators: %s: WordPress version number. */
				$hits ? sprintf( 'WP %s: %s', $version, self::summarize( $hits ) ) : sprintf( __( 'WP %s: no matching CVE', 'wper-checklist' ), $version )
			);
		}

		// 최신 여부 — 코어가 이미 파악한 상태를 읽는다 (외부 호출 불요).
		$core = get_site_transient( 'update_core' );
		if ( ! is_object( $core ) || empty( $core->updates ) ) {
			$items[] = wper_checklist_item( 'vuln:core-latest', self::CAT, __( 'Core up to date', 'wper-checklist' ), 'skip', 15, __( 'Update info unavailable — unverifiable', 'wper-checklist' ) );
		} else {
			$latest  = $core->updates[0]->response ?? '';
			$items[] = wper_checklist_item(
				'vuln:core-latest', self::CAT, __( 'Core up to date', 'wper-checklist' ),
				'latest' === $latest ? 'pass' : 'fail',
				15,
				/* translators: %s: current version number. */
				'latest' === $latest ? sprintf( __( '%s = latest', 'wper-checklist' ), $version ) : sprintf( __( '%1$s → %2$s update pending', 'wper-checklist' ), $version, $core->updates[0]->version ?? '?' )
			);
		}

		return [ 'items' => $items, 'events' => [ __( 'Core vulnerability lookup: WP ', 'wper-checklist' ) . $version ], 'done' => true ];
	}

	/* ------------------------------------------------------------ 플러그인 */

	private static function run_plugins( array $ctx ): array {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active = (array) get_option( 'active_plugins', [] );
		$cursor = (int) ( $ctx['cursor'] ?? 0 );
		$total  = count( $active );

		// 앞 커서까지의 누적 결과를 이어받는다 (커서 재전송에 멱등 — 같은 슬러그는 덮어쓴다).
		$acc = $ctx['items']['vuln:plugins']['raw'] ?? [];
		$acc = is_array( $acc ) ? $acc : [];

		$events = [];

		if ( $cursor < $total ) {
			$file = $active[ $cursor ];
			$slug = str_contains( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' );
			$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
			$ver  = (string) ( $data['Version'] ?? '' );

			$hits = self::lookup( 'plugin/' . $slug, $ver );

			$acc[ $slug ] = [
				'version' => $ver,
				'state'   => null === $hits ? 'unknown' : ( $hits ? 'vulnerable' : 'clean' ),
				'cves'    => $hits ? self::summarize( $hits ) : '',
			];

			/* translators: 1: plugin slug, 2: version, 3: result summary. */
			$events[] = sprintf( __( 'Plugin %1$s %2$s: %3$s', 'wper-checklist' ), $slug, $ver, [ 'unknown' => __( 'Unverifiable', 'wper-checklist' ), 'vulnerable' => __( '⚠ vulnerable', 'wper-checklist' ), 'clean' => __( 'no issues', 'wper-checklist' ) ][ $acc[ $slug ]['state'] ] );
		}

		$done  = ( $cursor + 1 ) >= $total;
		$items = [];

		if ( $done ) {
			$vulnerable = array_filter( $acc, static fn( $r ) => 'vulnerable' === $r['state'] );
			$unknown    = array_filter( $acc, static fn( $r ) => 'unknown' === $r['state'] );

			if ( $vulnerable ) {
				$list    = implode( ', ', array_map( static fn( $s, $r ) => "{$s} {$r['version']} ({$r['cves']})", array_keys( $vulnerable ), $vulnerable ) );
				/* translators: 1: number of vulnerable plugins, 2: comma-separated list. */
				$measured = sprintf( __( '%1$d vulnerable: %2$s', 'wper-checklist' ), count( $vulnerable ), $list );

				$items[] = wper_checklist_item( 'vuln:plugins', self::CAT, __( 'Plugin known vulnerabilities', 'wper-checklist' ), 'fail', 40, $measured, $acc );
			} elseif ( count( $unknown ) === $total && $total > 0 ) {
				$items[] = wper_checklist_item( 'vuln:plugins', self::CAT, __( 'Plugin known vulnerabilities', 'wper-checklist' ), 'skip', 40, __( 'All API lookups failed — unverifiable', 'wper-checklist' ), $acc );
			} else {
				/* translators: %d: how many could not be checked. */
				$note    = $unknown ? sprintf( __( ' (%d unverifiable)', 'wper-checklist' ), count( $unknown ) ) : '';
				/* translators: 1: number of active plugins, 2: optional suffix. */
				$clean = sprintf( __( '%1$d active, 0 vulnerable%2$s', 'wper-checklist' ), $total, $note );

				$items[] = wper_checklist_item( 'vuln:plugins', self::CAT, __( 'Plugin known vulnerabilities', 'wper-checklist' ), 'pass', 40, $clean, $acc );
			}

			// 업데이트 대기 수 — 로컬 상태만으로 판정 (API 다운과 무관하게 동작).
			$upd     = get_site_transient( 'update_plugins' );
			$pending = is_object( $upd ) && ! empty( $upd->response ) ? count( $upd->response ) : 0;
			if ( ! is_object( $upd ) ) {
				$items[] = wper_checklist_item( 'vuln:plugin-updates', self::CAT, __( 'Plugins up to date', 'wper-checklist' ), 'skip', 15, __( 'Update info unavailable — unverifiable', 'wper-checklist' ) );
			} else {
				$items[] = wper_checklist_item(
					'vuln:plugin-updates', self::CAT, __( 'Plugins up to date', 'wper-checklist' ),
					0 === $pending ? 'pass' : ( $pending <= 2 ? 'warn' : 'fail' ),
					15,
					/* translators: %d: number of pending updates. */
					0 === $pending ? __( 'All up to date', 'wper-checklist' ) : sprintf( __( '%d update(s) pending', 'wper-checklist' ), $pending )
				);
			}
		} else {
			// 중간 커서: 누적만 저장 (pending 상태는 채점에서 제외된다).
			/* translators: 1: items processed so far, 2: total items. */
			$progress = sprintf( __( 'In progress %1$d/%2$d', 'wper-checklist' ), $cursor + 1, $total );

			$items[] = wper_checklist_item( 'vuln:plugins', self::CAT, __( 'Plugin known vulnerabilities', 'wper-checklist' ), 'skip', 40, $progress, $acc );
		}

		return [ 'items' => $items, 'events' => $events, 'done' => $done, 'cursor' => $cursor + 1 ];
	}

	/* --------------------------------------------------------------- 테마 */

	private static function run_themes(): array {
		$theme  = wp_get_theme();
		$slugs  = array_unique( array_filter( [ $theme->get_stylesheet(), $theme->get_template() ] ) );
		$items  = [];
		$vuln   = [];
		$miss   = 0;

		foreach ( $slugs as $slug ) {
			$t    = wp_get_theme( $slug );
			$hits = self::lookup( 'theme/' . $slug, (string) $t->get( 'Version' ) );
			if ( null === $hits ) {
				$miss++;
			} elseif ( $hits ) {
				$vuln[] = $slug . ' (' . self::summarize( $hits ) . ')';
			}
		}

		if ( $vuln ) {
			$items[] = wper_checklist_item( 'vuln:themes', self::CAT, __( 'Theme known vulnerabilities', 'wper-checklist' ), 'fail', 20, implode( ', ', $vuln ) );
		} elseif ( $miss === count( $slugs ) ) {
			$items[] = wper_checklist_item( 'vuln:themes', self::CAT, __( 'Theme known vulnerabilities', 'wper-checklist' ), 'skip', 20, __( 'API lookup failed — unverifiable', 'wper-checklist' ) );
		} else {
			$items[] = wper_checklist_item( 'vuln:themes', self::CAT, __( 'Theme known vulnerabilities', 'wper-checklist' ), 'pass', 20, implode( ' · ', $slugs ) . __( ': no matching CVE', 'wper-checklist' ) );
		}

		$upd     = get_site_transient( 'update_themes' );
		$pending = is_object( $upd ) && ! empty( $upd->response ) ? count( $upd->response ) : 0;
		if ( ! is_object( $upd ) ) {
			$items[] = wper_checklist_item( 'vuln:theme-updates', self::CAT, __( 'Themes up to date', 'wper-checklist' ), 'skip', 10, __( 'Update info unavailable — unverifiable', 'wper-checklist' ) );
		} else {
			$items[] = wper_checklist_item(
				'vuln:theme-updates', self::CAT, __( 'Themes up to date', 'wper-checklist' ),
				0 === $pending ? 'pass' : 'warn',
				10,
				/* translators: %d: number of pending updates. */
				0 === $pending ? __( 'All up to date', 'wper-checklist' ) : sprintf( __( '%d update(s) pending', 'wper-checklist' ), $pending )
			);
		}

		return [ 'items' => $items, 'events' => [ __( 'Theme vulnerability lookup: ', 'wper-checklist' ) . implode( ', ', $slugs ) ], 'done' => true ];
	}

	/* ----------------------------------------------------------- 프론트 JS */

	private static function run_js( array $ctx ): array {
		$res = WPER_Checklist_HTTP::get( $ctx['urls']['home'] ?? home_url( '/' ) );

		if ( ! $res['ok'] ) {
			$item = wper_checklist_item( 'vuln:js', self::CAT, __( 'Front-end JS libraries', 'wper-checklist' ), 'skip', 25, __( 'Failed to fetch landing — unverifiable', 'wper-checklist' ) );
			return [ 'items' => [ $item ], 'events' => [ __( 'JS check: request failed', 'wper-checklist' ) ], 'done' => true ];
		}

		preg_match_all( '/<script[^>]+src=["\']([^"\']+)["\']/i', $res['body'], $m );
		$srcs = $m[1] ?? [];

		$table    = (array) include WPER_CHECKLIST_PATH . 'data/js-vulns.php';
		$found    = [];
		$bad      = [];

		foreach ( $srcs as $src ) {
			foreach ( $table as $lib => $def ) {
				if ( ! preg_match( $def['detect'], $src ) ) {
					continue;
				}
				// 버전: 파일명(lib-1.2.3) 또는 ?ver= 쿼리.
				$ver = '';
				if ( preg_match( '/[-.\/]((?:\d+\.){1,3}\d+)[^\/]*\.js/', $src, $vm ) ) {
					$ver = $vm[1];
				} elseif ( preg_match( '/[?&]ver=((?:\d+\.){1,3}\d+)/', $src, $vm ) ) {
					$ver = $vm[1];
				}

				$found[ $lib ] = $ver ?: '?';

				if ( '' !== $ver ) {
					foreach ( $def['vulns'] as $v ) {
						$above = ! isset( $v['since'] ) || version_compare( $ver, $v['since'], '>=' );
						if ( $above && version_compare( $ver, $v['below'], '<' ) ) {
							/* translators: 1: library name, 2: detected version, 3: CVE identifier, 4: first fixed version. */
							$bad[] = sprintf( __( '%1$s %2$s (%3$s, below %4$s)', 'wper-checklist' ), $lib, $ver, $v['cve'], $v['below'] );
							break;
						}
					}
				}
			}
		}

		if ( $bad ) {
			$item = wper_checklist_item( 'vuln:js', self::CAT, __( 'Front-end JS libraries', 'wper-checklist' ), 'fail', 25, implode( ', ', $bad ) );
		} elseif ( in_array( '?', $found, true ) ) {
			$unk  = implode( ', ', array_keys( array_filter( $found, static fn( $v ) => '?' === $v ) ) );
			/* translators: %s: script file name. */
			$item = wper_checklist_item( 'vuln:js', self::CAT, __( 'Front-end JS libraries', 'wper-checklist' ), 'warn', 25, sprintf( __( 'Unknown version: %s — cannot judge', 'wper-checklist' ), $unk ) );
		} else {
			$note = $found ? __( 'Detected: ', 'wper-checklist' ) . implode( ', ', array_map( static fn( $l, $v ) => "$l $v", array_keys( $found ), $found ) ) : __( 'No known libraries detected', 'wper-checklist' );
			$item = wper_checklist_item( 'vuln:js', self::CAT, __( 'Front-end JS libraries', 'wper-checklist' ), 'pass', 25, $note . __( ' — not in a vulnerable range', 'wper-checklist' ) );
		}

		/* translators: %d: number of scripts inspected. */
		return [ 'items' => [ $item ], 'events' => [ sprintf( __( 'JS libraries: %d scripts checked', 'wper-checklist' ), count( $srcs ) ) ], 'done' => true ];
	}

	/* ------------------------------------------------------ 노출면 프로브 */

	private static function run_probes( array $ctx ): array {
		$home  = untrailingslashit( $ctx['urls']['home'] ?? home_url() );
		$items = [];

		// ?author=1 열거 — 리다이렉트 위치에 사용자명이 노출되는지.
		$a = WPER_Checklist_HTTP::get( $home . '/?author=1' );
		$loc = (string) ( is_array( $a['headers']['location'] ?? null ) ? end( $a['headers']['location'] ) : ( $a['headers']['location'] ?? '' ) );
		if ( $a['code'] >= 300 && $a['code'] < 400 && str_contains( $loc, '/author/' ) ) {
			$items[] = wper_checklist_item( 'vuln:author', self::CAT, __( 'Username enumeration (?author=N)', 'wper-checklist' ), 'fail', 10, __( 'Username exposed via redirect: ', 'wper-checklist' ) . wp_parse_url( $loc, PHP_URL_PATH ) );
		} elseif ( 200 === $a['code'] && preg_match( '/\/author\/[^"\']+/', $a['body'] ) ) {
			$items[] = wper_checklist_item( 'vuln:author', self::CAT, __( 'Username enumeration (?author=N)', 'wper-checklist' ), 'warn', 10, __( 'Author archive exposed in response', 'wper-checklist' ) );
		} else {
			/* translators: %d: HTTP status code. */
			$items[] = wper_checklist_item( 'vuln:author', self::CAT, __( 'Username enumeration (?author=N)', 'wper-checklist' ), 'pass', 10, sprintf( __( 'Blocked (HTTP %d)', 'wper-checklist' ), $a['code'] ) );
		}

		// xmlrpc.php — GET 에 405 면 활성(POST 수락), 403/404 면 차단.
		$x = WPER_Checklist_HTTP::get( $home . '/xmlrpc.php' );
		if ( in_array( $x['code'], [ 403, 404, 410 ], true ) ) {
			/* translators: %d: HTTP status code. */
			$items[] = wper_checklist_item( 'vuln:xmlrpc', self::CAT, __( 'xmlrpc.php blocked', 'wper-checklist' ), 'pass', 10, sprintf( __( 'Blocked (HTTP %d)', 'wper-checklist' ), $x['code'] ) );
		} elseif ( 405 === $x['code'] || str_contains( $x['body'], 'XML-RPC' ) ) {
			$items[] = wper_checklist_item( 'vuln:xmlrpc', self::CAT, __( 'xmlrpc.php blocked', 'wper-checklist' ), 'fail', 10, __( 'Active — brute-force amplification / pingback DDoS vector', 'wper-checklist' ) );
		} else {
			/* translators: %d: HTTP status code. */
			$items[] = wper_checklist_item( 'vuln:xmlrpc', self::CAT, __( 'xmlrpc.php blocked', 'wper-checklist' ), 'warn', 10, sprintf( __( 'Inconclusive (HTTP %d)', 'wper-checklist' ), $x['code'] ) );
		}

		// /wp-json/wp/v2/users — 비인증 사용자 목록.
		$u = WPER_Checklist_HTTP::get( $home . '/wp-json/wp/v2/users' );
		$list = json_decode( $u['body'], true );
		if ( 200 === $u['code'] && is_array( $list ) && isset( $list[0]['slug'] ) ) {
			/* translators: %d: number of user records exposed. */
			$measured = sprintf( __( '%d users exposed without authentication', 'wper-checklist' ), count( $list ) );
			$items[]  = wper_checklist_item( 'vuln:users', self::CAT, __( 'REST user enumeration blocked', 'wper-checklist' ), 'fail', 15, $measured );
		} elseif ( in_array( $u['code'], [ 401, 403, 404 ], true ) || ( is_array( $list ) && ! isset( $list[0]['slug'] ) ) ) {
			/* translators: %d: HTTP status code. */
			$items[] = wper_checklist_item( 'vuln:users', self::CAT, __( 'REST user enumeration blocked', 'wper-checklist' ), 'pass', 15, sprintf( __( 'Blocked (HTTP %d)', 'wper-checklist' ), $u['code'] ) );
		} else {
			/* translators: %d: HTTP status code. */
			$items[] = wper_checklist_item( 'vuln:users', self::CAT, __( 'REST user enumeration blocked', 'wper-checklist' ), 'warn', 15, sprintf( __( 'Inconclusive (HTTP %d)', 'wper-checklist' ), $u['code'] ) );
		}

		return [ 'items' => $items, 'events' => [ __( '3 exposure probes complete', 'wper-checklist' ) ], 'done' => true ];
	}

	/* --------------------------------------------------------------- 공용 */

	/**
	 * API 조회 + 현재 버전에 해당하는 취약점 필터.
	 *
	 * @return array|null 해당 취약점 배열. null = 조회 실패(확인 불가).
	 */
	private static function lookup( string $path, string $version ): ?array {
		$key    = 'wper_checklist_vapi_' . md5( $path );
		$cached = get_transient( $key );

		if ( 'ERR' === $cached ) {
			return null;
		}

		if ( false === $cached ) {
			$res = WPER_Checklist_HTTP::get( self::API . $path, [ 'timeout' => 8 ] );

			if ( ! $res['ok'] || 200 !== $res['code'] ) {
				set_transient( $key, 'ERR', HOUR_IN_SECONDS );
				return null;
			}

			$cached = json_decode( $res['body'], true );

			if ( ! is_array( $cached ) ) {
				set_transient( $key, 'ERR', HOUR_IN_SECONDS );
				return null;
			}

			set_transient( $key, $cached, 12 * HOUR_IN_SECONDS );
		}

		$vulns = $cached['data']['vulnerability'] ?? [];
		if ( ! is_array( $vulns ) ) {
			return [];
		}

		$hits = [];
		foreach ( $vulns as $v ) {
			if ( '' !== $version && true === self::affected( $v, $version ) ) {
				$hits[] = $v;
			}
		}
		return $hits;
	}

	/**
	 * 버전 범위 매칭. 서술자가 불명이면 null (그 항목은 판단하지 않는다 — 추측 금지).
	 */
	private static function affected( array $vuln, string $version ): ?bool {
		$op = $vuln['operator'] ?? null;
		if ( ! is_array( $op ) ) {
			return null;
		}

		$map = [ 'lt' => '<', 'le' => '<=', 'lte' => '<=', 'gt' => '>', 'ge' => '>=', 'gte' => '>=', 'eq' => '==' ];
		$cmp = static function ( string $ver, string $bound, string $word ) use ( $map ): ?bool {
			$sym = $map[ strtolower( $word ) ] ?? ( in_array( $word, $map, true ) ? $word : null );
			return null === $sym ? null : version_compare( $ver, $bound, $sym );
		};

		$min_ok = true;
		if ( ! empty( $op['min_version'] ) ) {
			$min_ok = $cmp( $version, (string) $op['min_version'], (string) ( $op['min_operator'] ?? 'ge' ) );
		}
		$max_ok = null;
		if ( ! empty( $op['max_version'] ) ) {
			$max_ok = $cmp( $version, (string) $op['max_version'], (string) ( $op['max_operator'] ?? 'le' ) );
		}

		if ( null === $min_ok || null === $max_ok ) {
			return null; // 상한이 없거나 서술자 불명 — 미수정 여부를 추측하지 않는다.
		}

		return $min_ok && $max_ok;
	}

	/**
	 * 취약점 목록 → 요약 문자열 (CVE 우선, 최대 3개).
	 */
	private static function summarize( array $hits ): string {
		$names = [];
		foreach ( array_slice( $hits, 0, 3 ) as $v ) {
			$cve = '';
			foreach ( (array) ( $v['source'] ?? [] ) as $src ) {
				if ( ! empty( $src['id'] ) && str_starts_with( (string) $src['id'], 'CVE' ) ) {
					$cve = (string) $src['id'];
					break;
				}
			}
			$names[] = $cve ?: (string) ( $v['name'] ?? __( 'known vulnerabilities', 'wper-checklist' ) );
		}
		/* translators: %d: number of additional findings not listed. */
		$more = count( $hits ) > 3 ? sprintf( __( ' and %d more', 'wper-checklist' ), count( $hits ) - 3 ) : '';
		return implode( ', ', $names ) . $more;
	}
}
