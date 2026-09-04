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
			$items[] = wper_checklist_item( 'vuln:core', self::CAT, '코어 알려진 취약점', 'skip', 40, 'API 조회 실패 — 확인 불가' );
		} else {
			$items[] = wper_checklist_item(
				'vuln:core', self::CAT, '코어 알려진 취약점',
				$hits ? 'fail' : 'pass',
				40,
				$hits ? sprintf( 'WP %s: %s', $version, self::summarize( $hits ) ) : sprintf( 'WP %s: 해당 CVE 없음', $version )
			);
		}

		// 최신 여부 — 코어가 이미 파악한 상태를 읽는다 (외부 호출 불요).
		$core = get_site_transient( 'update_core' );
		if ( ! is_object( $core ) || empty( $core->updates ) ) {
			$items[] = wper_checklist_item( 'vuln:core-latest', self::CAT, '코어 최신 버전', 'skip', 15, '업데이트 정보 미수신 — 확인 불가' );
		} else {
			$latest  = $core->updates[0]->response ?? '';
			$items[] = wper_checklist_item(
				'vuln:core-latest', self::CAT, '코어 최신 버전',
				'latest' === $latest ? 'pass' : 'fail',
				15,
				'latest' === $latest ? sprintf( '%s = 최신', $version ) : sprintf( '%s → %s 업데이트 대기', $version, $core->updates[0]->version ?? '?' )
			);
		}

		return [ 'items' => $items, 'events' => [ '코어 취약점 조회: WP ' . $version ], 'done' => true ];
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

			$events[] = sprintf( '플러그인 %s %s: %s', $slug, $ver, [ 'unknown' => '확인 불가', 'vulnerable' => '⚠ 취약', 'clean' => '이상 없음' ][ $acc[ $slug ]['state'] ] );
		}

		$done  = ( $cursor + 1 ) >= $total;
		$items = [];

		if ( $done ) {
			$vulnerable = array_filter( $acc, static fn( $r ) => 'vulnerable' === $r['state'] );
			$unknown    = array_filter( $acc, static fn( $r ) => 'unknown' === $r['state'] );

			if ( $vulnerable ) {
				$list    = implode( ', ', array_map( static fn( $s, $r ) => "{$s} {$r['version']} ({$r['cves']})", array_keys( $vulnerable ), $vulnerable ) );
				$items[] = wper_checklist_item( 'vuln:plugins', self::CAT, '플러그인 알려진 취약점', 'fail', 40, sprintf( '취약 %d개: %s', count( $vulnerable ), $list ), $acc );
			} elseif ( count( $unknown ) === $total && $total > 0 ) {
				$items[] = wper_checklist_item( 'vuln:plugins', self::CAT, '플러그인 알려진 취약점', 'skip', 40, 'API 전체 조회 실패 — 확인 불가', $acc );
			} else {
				$note    = $unknown ? sprintf( ' (%d개는 확인 불가)', count( $unknown ) ) : '';
				$items[] = wper_checklist_item( 'vuln:plugins', self::CAT, '플러그인 알려진 취약점', 'pass', 40, sprintf( '활성 %d개 중 취약 0개%s', $total, $note ), $acc );
			}

			// 업데이트 대기 수 — 로컬 상태만으로 판정 (API 다운과 무관하게 동작).
			$upd     = get_site_transient( 'update_plugins' );
			$pending = is_object( $upd ) && ! empty( $upd->response ) ? count( $upd->response ) : 0;
			if ( ! is_object( $upd ) ) {
				$items[] = wper_checklist_item( 'vuln:plugin-updates', self::CAT, '플러그인 최신 유지', 'skip', 15, '업데이트 정보 미수신 — 확인 불가' );
			} else {
				$items[] = wper_checklist_item(
					'vuln:plugin-updates', self::CAT, '플러그인 최신 유지',
					0 === $pending ? 'pass' : ( $pending <= 2 ? 'warn' : 'fail' ),
					15,
					0 === $pending ? '전부 최신' : sprintf( '업데이트 대기 %d개', $pending )
				);
			}
		} else {
			// 중간 커서: 누적만 저장 (pending 상태는 채점에서 제외된다).
			$items[] = wper_checklist_item( 'vuln:plugins', self::CAT, '플러그인 알려진 취약점', 'skip', 40, sprintf( '진행 중 %d/%d', $cursor + 1, $total ), $acc );
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
			$items[] = wper_checklist_item( 'vuln:themes', self::CAT, '테마 알려진 취약점', 'fail', 20, implode( ', ', $vuln ) );
		} elseif ( $miss === count( $slugs ) ) {
			$items[] = wper_checklist_item( 'vuln:themes', self::CAT, '테마 알려진 취약점', 'skip', 20, 'API 조회 실패 — 확인 불가' );
		} else {
			$items[] = wper_checklist_item( 'vuln:themes', self::CAT, '테마 알려진 취약점', 'pass', 20, implode( ' · ', $slugs ) . ': 해당 CVE 없음' );
		}

		$upd     = get_site_transient( 'update_themes' );
		$pending = is_object( $upd ) && ! empty( $upd->response ) ? count( $upd->response ) : 0;
		if ( ! is_object( $upd ) ) {
			$items[] = wper_checklist_item( 'vuln:theme-updates', self::CAT, '테마 최신 유지', 'skip', 10, '업데이트 정보 미수신 — 확인 불가' );
		} else {
			$items[] = wper_checklist_item(
				'vuln:theme-updates', self::CAT, '테마 최신 유지',
				0 === $pending ? 'pass' : 'warn',
				10,
				0 === $pending ? '전부 최신' : sprintf( '업데이트 대기 %d개', $pending )
			);
		}

		return [ 'items' => $items, 'events' => [ '테마 취약점 조회: ' . implode( ', ', $slugs ) ], 'done' => true ];
	}

	/* ----------------------------------------------------------- 프론트 JS */

	private static function run_js( array $ctx ): array {
		$res = WPER_Checklist_HTTP::get( $ctx['urls']['home'] ?? home_url( '/' ) );

		if ( ! $res['ok'] ) {
			$item = wper_checklist_item( 'vuln:js', self::CAT, '프론트 JS 라이브러리', 'skip', 25, '랜딩 수신 실패 — 확인 불가' );
			return [ 'items' => [ $item ], 'events' => [ 'JS 검사: 수신 실패' ], 'done' => true ];
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
							$bad[] = sprintf( '%s %s (%s, %s 미만)', $lib, $ver, $v['cve'], $v['below'] );
							break;
						}
					}
				}
			}
		}

		if ( $bad ) {
			$item = wper_checklist_item( 'vuln:js', self::CAT, '프론트 JS 라이브러리', 'fail', 25, implode( ', ', $bad ) );
		} elseif ( in_array( '?', $found, true ) ) {
			$unk  = implode( ', ', array_keys( array_filter( $found, static fn( $v ) => '?' === $v ) ) );
			$item = wper_checklist_item( 'vuln:js', self::CAT, '프론트 JS 라이브러리', 'warn', 25, sprintf( '버전 미상: %s — 판정 불가', $unk ) );
		} else {
			$note = $found ? '감지: ' . implode( ', ', array_map( static fn( $l, $v ) => "$l $v", array_keys( $found ), $found ) ) : '알려진 라이브러리 미감지';
			$item = wper_checklist_item( 'vuln:js', self::CAT, '프론트 JS 라이브러리', 'pass', 25, $note . ' — 취약 범위 아님' );
		}

		return [ 'items' => [ $item ], 'events' => [ sprintf( 'JS 라이브러리: 스크립트 %d개 검사', count( $srcs ) ) ], 'done' => true ];
	}

	/* ------------------------------------------------------ 노출면 프로브 */

	private static function run_probes( array $ctx ): array {
		$home  = untrailingslashit( $ctx['urls']['home'] ?? home_url() );
		$items = [];

		// ?author=1 열거 — 리다이렉트 위치에 사용자명이 노출되는지.
		$a = WPER_Checklist_HTTP::get( $home . '/?author=1' );
		$loc = (string) ( is_array( $a['headers']['location'] ?? null ) ? end( $a['headers']['location'] ) : ( $a['headers']['location'] ?? '' ) );
		if ( $a['code'] >= 300 && $a['code'] < 400 && str_contains( $loc, '/author/' ) ) {
			$items[] = wper_checklist_item( 'vuln:author', self::CAT, '사용자명 열거 (?author=N)', 'fail', 10, '리다이렉트로 사용자명 노출: ' . wp_parse_url( $loc, PHP_URL_PATH ) );
		} elseif ( 200 === $a['code'] && preg_match( '/\/author\/[^"\']+/', $a['body'] ) ) {
			$items[] = wper_checklist_item( 'vuln:author', self::CAT, '사용자명 열거 (?author=N)', 'warn', 10, '작성자 아카이브가 응답에 노출' );
		} else {
			$items[] = wper_checklist_item( 'vuln:author', self::CAT, '사용자명 열거 (?author=N)', 'pass', 10, sprintf( '차단됨 (HTTP %d)', $a['code'] ) );
		}

		// xmlrpc.php — GET 에 405 면 활성(POST 수락), 403/404 면 차단.
		$x = WPER_Checklist_HTTP::get( $home . '/xmlrpc.php' );
		if ( in_array( $x['code'], [ 403, 404, 410 ], true ) ) {
			$items[] = wper_checklist_item( 'vuln:xmlrpc', self::CAT, 'xmlrpc.php 차단', 'pass', 10, sprintf( '차단됨 (HTTP %d)', $x['code'] ) );
		} elseif ( 405 === $x['code'] || str_contains( $x['body'], 'XML-RPC' ) ) {
			$items[] = wper_checklist_item( 'vuln:xmlrpc', self::CAT, 'xmlrpc.php 차단', 'fail', 10, '활성 상태 — 무차별 대입 증폭 · pingback DDoS 통로' );
		} else {
			$items[] = wper_checklist_item( 'vuln:xmlrpc', self::CAT, 'xmlrpc.php 차단', 'warn', 10, sprintf( '판정 불명확 (HTTP %d)', $x['code'] ) );
		}

		// /wp-json/wp/v2/users — 비인증 사용자 목록.
		$u = WPER_Checklist_HTTP::get( $home . '/wp-json/wp/v2/users' );
		$list = json_decode( $u['body'], true );
		if ( 200 === $u['code'] && is_array( $list ) && isset( $list[0]['slug'] ) ) {
			$items[] = wper_checklist_item( 'vuln:users', self::CAT, 'REST 사용자 열거 차단', 'fail', 15, sprintf( '비인증으로 사용자 %d명 노출', count( $list ) ) );
		} elseif ( in_array( $u['code'], [ 401, 403, 404 ], true ) || ( is_array( $list ) && ! isset( $list[0]['slug'] ) ) ) {
			$items[] = wper_checklist_item( 'vuln:users', self::CAT, 'REST 사용자 열거 차단', 'pass', 15, sprintf( '차단됨 (HTTP %d)', $u['code'] ) );
		} else {
			$items[] = wper_checklist_item( 'vuln:users', self::CAT, 'REST 사용자 열거 차단', 'warn', 15, sprintf( '판정 불명확 (HTTP %d)', $u['code'] ) );
		}

		return [ 'items' => $items, 'events' => [ '노출면 프로브 3종 완료' ], 'done' => true ];
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
			$names[] = $cve ?: (string) ( $v['name'] ?? '알려진 취약점' );
		}
		$more = count( $hits ) > 3 ? sprintf( ' 외 %d건', count( $hits ) - 3 ) : '';
		return implode( ', ', $names ) . $more;
	}
}
