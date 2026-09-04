<?php
/**
 * 오케스트레이션 — 매니페스트 · 스텝 디스패치 · 채점의 단일 출처 (REST 와 CLI 가 공유).
 *
 * ⭐ 채점 규약 (계측 독트린): skip(확인 불가) 항목은 **분모에서 제외**한다.
 *    카테고리 점수 = earned / possible × 200. 측정하지 못한 것을 0점 처리하면
 *    네트워크 사정이 점수를 왜곡하고, 만점 처리하면 거짓말이 된다 — 제외가 정직하다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 검사 항목 팩토리.
 *
 * @param string      $status  pass | warn | fail | skip.
 * @param float|null  $earned  부분 점수 (null 이면 pass=만점 · warn=절반 · fail/skip=0).
 */
function wper_checklist_item( string $key, string $cat, string $label, string $status, float $weight, string $measured = '', array $raw = [], ?float $earned = null ): array {
	if ( null === $earned ) {
		$earned = [ 'pass' => $weight, 'warn' => $weight / 2, 'fail' => 0.0, 'skip' => 0.0 ][ $status ] ?? 0.0;
	}

	return [
		'key'      => $key,
		'cat'      => $cat,
		'label'    => $label,
		'status'   => $status,
		'score'    => round( (float) $earned, 1 ),
		'possible' => $weight,
		'measured' => $measured,
		'raw'      => $raw,
	];
}

final class WPER_Checklist_Runner {

	const CATS = [
		'latency' => '응답 속도',
		'db'      => 'DB 쿼리',
		'seo'     => 'SEO',
		'vuln'    => 'WP 취약점',
		'server'  => '서버 설정',
	];

	/**
	 * 진단 시작 — URL 샘플링 · 매니페스트 · 토큰 발급.
	 */
	public static function create_run(): array {
		$urls     = self::sample_urls();
		$manifest = self::manifest( $urls );

		$run_id = WPER_Checklist_Store::create( [ 'urls' => $urls, 'manifest' => wp_list_pluck( $manifest, 'step' ) ] );

		if ( ! $run_id ) {
			return [ 'error' => '진단 레코드 생성 실패' ];
		}

		WPER_Checklist_Capture::mint( $run_id );

		return [ 'run_id' => $run_id, 'manifest' => $manifest ];
	}

	/**
	 * 스텝 1개 실행. (step, cursor) 재전송에 멱등.
	 */
	public static function run_step( int $run_id, string $step_id, int $cursor = 0 ): array {
		$context = WPER_Checklist_Store::context( $run_id );
		$results = WPER_Checklist_Store::results( $run_id );

		if ( empty( $context['manifest'] ) || ! in_array( $step_id, $context['manifest'], true ) ) {
			return [ 'error' => '알 수 없는 스텝: ' . $step_id ];
		}

		$token = get_transient( WPER_Checklist_Capture::TOKEN_OPT );

		$ctx = [
			'urls'   => $context['urls'] ?? [],
			'token'  => is_array( $token ) ? ( $token['token'] ?? '' ) : '',
			'cursor' => $cursor,
			'items'  => $results['items'] ?? [],
		];

		$class = self::dispatch_class( $step_id );
		if ( null === $class ) {
			return [ 'error' => '핸들러 없음: ' . $step_id ];
		}

		$out = $class::run( $step_id, $ctx );

		WPER_Checklist_Store::save_items( $run_id, $step_id, $out['items'] ?? [], (bool) ( $out['done'] ?? true ) );

		// 전체 진행률 + 완료 판정.
		$results  = WPER_Checklist_Store::results( $run_id );
		$done_n   = count( $results['steps_done'] ?? [] );
		$total_n  = count( $context['manifest'] );
		$complete = $done_n >= $total_n;

		$response = [
			'done'     => (bool) ( $out['done'] ?? true ),
			'cursor'   => $out['cursor'] ?? null,
			'events'   => $out['events'] ?? [],
			'progress' => [ 'done' => $done_n, 'total' => $total_n ],
			'complete' => $complete,
		];

		if ( $complete && ! WPER_Checklist_Store::is_complete( $run_id ) ) {
			$scores = self::score( $results['items'] );
			WPER_Checklist_Store::finalize( $run_id, $scores );
			WPER_Checklist_Capture::burn();
			$response['scores'] = $scores;
		} elseif ( $complete ) {
			$response['scores'] = self::score( $results['items'] );
		}

		return $response;
	}

	/**
	 * 채점 — skip 은 분모 제외, 카테고리 = earned/possible × 200.
	 */
	public static function score( array $items ): array {
		$cats = [];
		foreach ( array_keys( self::CATS ) as $cat ) {
			$cats[ $cat ] = [ 'earned' => 0.0, 'possible' => 0.0, 'score' => 0, 'counts' => [ 'pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0 ] ];
		}

		foreach ( $items as $item ) {
			$cat = $item['cat'] ?? '';
			if ( ! isset( $cats[ $cat ] ) ) {
				continue;
			}
			$status = $item['status'] ?? 'skip';
			$cats[ $cat ]['counts'][ $status ] = ( $cats[ $cat ]['counts'][ $status ] ?? 0 ) + 1;

			if ( 'skip' === $status ) {
				continue; // 확인 불가 — 분모 제외.
			}
			$cats[ $cat ]['earned']   += (float) $item['score'];
			$cats[ $cat ]['possible'] += (float) $item['possible'];
		}

		$total = 0;
		foreach ( $cats as $cat => &$row ) {
			$row['score'] = $row['possible'] > 0 ? (int) round( $row['earned'] / $row['possible'] * 200 ) : 0;
			$total       += $row['score'];
		}
		unset( $row );

		return [ 'total' => $total, 'cats' => $cats ];
	}

	/* ---------------------------------------------------------------- 내부 */

	private static function dispatch_class( string $step_id ): ?string {
		$prefix = strtok( $step_id, ':' );
		return [
			'latency' => 'WPER_Checklist_Check_Latency',
			'db'      => 'WPER_Checklist_Check_DB',
			'seo'     => 'WPER_Checklist_Check_SEO',
			'vuln'    => 'WPER_Checklist_Check_Vuln_WP',
			'server'  => 'WPER_Checklist_Check_Server',
		][ $prefix ] ?? null;
	}

	/**
	 * URL 샘플러 — 랜딩 · 아카이브 · 글 · 고정 페이지 · 상품(없으면 skip).
	 */
	private static function sample_urls(): array {
		$urls = [
			'home'    => home_url( '/' ),
			'archive' => '',
			'single'  => '',
			'page'    => '',
			'product' => '',
		];

		// 아카이브: 글 목록 페이지 → 없으면 글이 가장 많은 카테고리.
		$posts_page = (int) get_option( 'page_for_posts' );
		if ( $posts_page ) {
			$urls['archive'] = (string) get_permalink( $posts_page );
		} else {
			$cats = get_categories( [ 'orderby' => 'count', 'order' => 'DESC', 'number' => 1, 'hide_empty' => true ] );
			if ( $cats ) {
				$link = get_term_link( $cats[0] );
				if ( ! is_wp_error( $link ) ) {
					$urls['archive'] = $link;
				}
			}
		}

		// 최신 글.
		$posts = get_posts( [ 'numberposts' => 1, 'post_status' => 'publish' ] );
		if ( $posts ) {
			$urls['single'] = (string) get_permalink( $posts[0] );
		}

		// 프론트/글 목록이 아닌 고정 페이지.
		$front = (int) get_option( 'page_on_front' );
		$pages = get_posts(
			[
				'post_type'    => 'page',
				'numberposts'  => 1,
				'post_status'  => 'publish',
				'post__not_in' => array_filter( [ $front, $posts_page ] ),
			]
		);
		if ( $pages ) {
			$urls['page'] = (string) get_permalink( $pages[0] );
		}

		// 상품·서비스 계열 CPT 아카이브 — 없으면 빈 값(해당 스텝이 skip 처리).
		$cpts = get_post_types( [ 'public' => true, '_builtin' => false, 'has_archive' => true ], 'names' );
		usort( $cpts, static fn( $a, $b ) => ( 'product' === $b ) <=> ( 'product' === $a ) ); // WooCommerce 우선.
		foreach ( $cpts as $cpt ) {
			$count = wp_count_posts( $cpt );
			if ( ! empty( $count->publish ) ) {
				$link = get_post_type_archive_link( $cpt );
				if ( $link ) {
					$urls['product'] = $link;
					break;
				}
			}
		}

		return $urls;
	}

	/**
	 * 매니페스트 — 진행 UI 가 그대로 그린다. paginated 스텝은 total 을 명시.
	 */
	private static function manifest( array $urls ): array {
		$steps = [
			[ 'step' => 'latency:home', 'cat' => 'latency', 'label' => '랜딩 응답 측정 (3회)' ],
			[ 'step' => 'latency:archive', 'cat' => 'latency', 'label' => '아카이브 응답 측정' ],
			[ 'step' => 'latency:single', 'cat' => 'latency', 'label' => '글 페이지 응답 측정' ],
			[ 'step' => 'latency:page', 'cat' => 'latency', 'label' => '고정 페이지 응답 측정' ],
			[ 'step' => 'latency:product', 'cat' => 'latency', 'label' => '상품·서비스 응답 측정' ],
			[ 'step' => 'latency:redirects', 'cat' => 'latency', 'label' => '리다이렉트 체인 검사' ],
			[ 'step' => 'db:home', 'cat' => 'db', 'label' => '랜딩 쿼리 캡처 (3회)' ],
			[ 'step' => 'db:single', 'cat' => 'db', 'label' => '글 페이지 쿼리 캡처' ],
			[ 'step' => 'db:archive', 'cat' => 'db', 'label' => '아카이브 쿼리 캡처' ],
			[ 'step' => 'db:analyze', 'cat' => 'db', 'label' => '쿼리 패턴 분석' ],
			[ 'step' => 'seo:landing', 'cat' => 'seo', 'label' => '랜딩 SEO 분석' ],
			[ 'step' => 'seo:site', 'cat' => 'seo', 'label' => 'robots.txt · 사이트맵' ],
			[ 'step' => 'vuln:core', 'cat' => 'vuln', 'label' => '코어 취약점 조회' ],
			[ 'step' => 'vuln:plugins', 'cat' => 'vuln', 'label' => '플러그인 취약점 조회', 'total' => max( 1, count( (array) get_option( 'active_plugins', [] ) ) ) ],
			[ 'step' => 'vuln:themes', 'cat' => 'vuln', 'label' => '테마 취약점 조회' ],
			[ 'step' => 'vuln:js', 'cat' => 'vuln', 'label' => '프론트 JS 라이브러리' ],
			[ 'step' => 'vuln:probes', 'cat' => 'vuln', 'label' => '노출면 프로브' ],
			[ 'step' => 'server:php', 'cat' => 'server', 'label' => 'PHP 설정 검사' ],
			[ 'step' => 'server:wp', 'cat' => 'server', 'label' => 'wp-config 검사' ],
			[ 'step' => 'server:headers', 'cat' => 'server', 'label' => '보안 응답 헤더' ],
			[ 'step' => 'server:files', 'cat' => 'server', 'label' => '민감 경로 프로브' ],
			[ 'step' => 'server:db', 'cat' => 'server', 'label' => 'DB 서버 변수' ],
		];

		return $steps;
	}
}
