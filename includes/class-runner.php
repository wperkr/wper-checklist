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

	/**
	 * 기본 카테고리 키 — 순서가 곧 보고서 · 진행 UI 의 표시 순서다.
	 *
	 * ⚠ 라벨을 상수에 두지 않는다. PHP 상수는 함수 호출을 담을 수 없어 `__()` 가
	 *   불가능하고, 상수에 한국어를 박으면 그 순간 언어팩 밖으로 문자열이 새어 나간다.
	 *   라벨은 categories() 가 소유한다.
	 */
	const CAT_KEYS = [ 'latency', 'db', 'seo', 'vuln', 'server' ];

	/**
	 * 카테고리 정의 — `키 => 라벨`. **개수가 곧 배점이다**: 카테고리당 만점은
	 * 1000 / 개수 이므로 5개면 200점, 4개면 250점이고 총점은 언제나 1000 이다.
	 *
	 * ⭐ 확장 지점 `wper_checklist_categories` 는 **진단 1건의 컨텍스트를 함께 받는다.**
	 *    컨텍스트로 갈리게 두는 것이 핵심이다 — 지난 진단의 보고서를 다시 그릴 때는
	 *    *그때의* 구성으로 채점해야 하고, 지금 화면의 설정으로 채점하면 과거 기록이
	 *    조용히 다른 점수가 된다. 빈 컨텍스트는 "지금 시작할 진단" 을 뜻한다.
	 *
	 * @param array $context 진단 컨텍스트 (Store::context). 비었으면 신규 진단.
	 * @return array<string,string>
	 */
	public static function categories( array $context = [] ): array {
		$default = [
			'latency' => __( 'Response time', 'wper-checklist' ),
			'db'      => __( 'Database queries', 'wper-checklist' ),
			'seo'     => __( 'SEO', 'wper-checklist' ),
			'vuln'    => __( 'WordPress vulnerabilities', 'wper-checklist' ),
			'server'  => __( 'Server configuration', 'wper-checklist' ),
		];

		$cats = apply_filters( 'wper_checklist_categories', $default, $context );

		// 빈 배열이 오면 0 나눗셈이 아니라 기본 구성으로 되돌린다 — 확장의 실수가
		// 채점기를 무너뜨리지 않게.
		return is_array( $cats ) && $cats ? $cats : $default;
	}

	/**
	 * 카테고리 라벨 (현재 로케일).
	 *
	 * @param array $context 진단 컨텍스트. 비었으면 신규 진단 기준.
	 * @return array<string,string>
	 */
	public static function cat_labels( array $context = [] ): array {
		return self::categories( $context );
	}

	/**
	 * 검사 항목 라벨 — 항목 키(ASCII) → 현재 로케일 라벨.
	 *
	 * ⭐ 보고서는 **저장된 라벨이 아니라 이 표**로 그린다. 진단 결과는 실행 시점의
	 *    언어로 DB 에 남는데, 그것을 그대로 출력하면 로케일을 바꾼 사이트에서
	 *    "머리말은 영어인데 항목명만 한국어" 인 화면이 된다. 키는 ASCII 로 불변이므로
	 *    과거 기록도 현재 언어로 다시 그려진다.
	 *
	 * @return array<string,string>
	 */
	public static function item_labels(): array {
		$labels = [
			// latency
			'latency:home'        => __( 'Landing response time', 'wper-checklist' ),
			'latency:archive'     => __( 'Archive response time', 'wper-checklist' ),
			'latency:single'      => __( 'Post response time', 'wper-checklist' ),
			'latency:page'        => __( 'Page response time', 'wper-checklist' ),
			'latency:product'     => __( 'Product/service response time', 'wper-checklist' ),
			'latency:stability'   => __( 'Response time stability', 'wper-checklist' ),
			'latency:size'        => __( 'Landing HTML size', 'wper-checklist' ),
			'latency:redirects'   => __( 'No redirect chain', 'wper-checklist' ),

			// db
			'db:home'             => __( 'Landing query count', 'wper-checklist' ),
			'db:single'           => __( 'Post query count', 'wper-checklist' ),
			'db:archive'          => __( 'Archive query count', 'wper-checklist' ),
			'db:time'             => __( 'Landing database time', 'wper-checklist' ),
			'db:slow'             => __( 'Slow queries (>20ms)', 'wper-checklist' ),
			'db:dupes'            => __( 'Duplicate queries (N+1)', 'wper-checklist' ),
			'db:objcache'         => __( 'Object cache', 'wper-checklist' ),

			// seo
			'seo:title'           => __( 'Document title', 'wper-checklist' ),
			'seo:description'     => __( 'Meta description', 'wper-checklist' ),
			'seo:h1'              => __( 'Single h1', 'wper-checklist' ),
			'seo:indexable'       => __( 'Indexable', 'wper-checklist' ),
			'seo:canonical'       => __( 'Canonical URL', 'wper-checklist' ),
			'seo:lang'            => __( 'html lang attribute', 'wper-checklist' ),
			'seo:viewport'        => __( 'viewport meta tag', 'wper-checklist' ),
			'seo:alt'             => __( 'Image alt text', 'wper-checklist' ),
			'seo:linktext'        => __( 'Descriptive link text', 'wper-checklist' ),
			'seo:jsonld'          => __( 'Structured data (JSON-LD)', 'wper-checklist' ),
			'seo:hreflang'        => __( 'hreflang', 'wper-checklist' ),
			'seo:og'              => __( 'Open Graph tags', 'wper-checklist' ),
			'seo:https'           => __( 'HTTPS / mixed content', 'wper-checklist' ),
			'seo:sitemap'         => __( 'Sitemap reachable', 'wper-checklist' ),

			// vuln
			'vuln:core'           => __( 'WordPress core vulnerabilities', 'wper-checklist' ),
			'vuln:core-latest'    => __( 'Core up to date', 'wper-checklist' ),
			'vuln:plugins'        => __( 'Plugin vulnerabilities', 'wper-checklist' ),
			'vuln:plugin-updates' => __( 'Plugin updates', 'wper-checklist' ),
			'vuln:themes'         => __( 'Theme vulnerabilities', 'wper-checklist' ),
			'vuln:theme-updates'  => __( 'Theme updates', 'wper-checklist' ),
			'vuln:js'             => __( 'Front-end JS libraries', 'wper-checklist' ),
			'vuln:author'         => __( 'Author enumeration blocked', 'wper-checklist' ),
			'vuln:xmlrpc'         => __( 'xmlrpc.php blocked', 'wper-checklist' ),
			'vuln:users'          => __( 'REST user list blocked', 'wper-checklist' ),

			// server
			'server:php-version'      => __( 'PHP version supported', 'wper-checklist' ),
			'server:expose-php'       => __( 'expose_php off', 'wper-checklist' ),
			'server:disable-functions' => __( 'Shell functions disabled', 'wper-checklist' ),
			'server:curl-alive'       => __( 'curl_exec available', 'wper-checklist' ),
			'server:url-fopen'        => __( 'allow_url_fopen off', 'wper-checklist' ),
			'server:display-errors'   => __( 'display_errors off', 'wper-checklist' ),
			'server:open-basedir'     => __( 'open_basedir set', 'wper-checklist' ),
			'server:wp-debug'         => __( 'WP_DEBUG off', 'wper-checklist' ),
			'server:file-edit'        => __( 'DISALLOW_FILE_EDIT on', 'wper-checklist' ),
			'server:db-charset'       => __( 'DB_CHARSET utf8mb4', 'wper-checklist' ),
			'server:sec-headers'      => __( 'Security response headers', 'wper-checklist' ),
			'server:tokens'           => __( 'Server version hidden', 'wper-checklist' ),
			'server:https'            => __( 'HTTPS enforced', 'wper-checklist' ),
			'server:files'            => __( 'Sensitive paths blocked', 'wper-checklist' ),
			'server:db-vars'          => __( 'Database server settings', 'wper-checklist' ),
		];

		// 확장이 추가한 검사 항목도 같은 규약을 따르게 한다 — 키(ASCII)로 다시 그려야
		// 로케일을 바꾼 뒤에도 머리말과 항목명의 언어가 갈리지 않는다.
		return (array) apply_filters( 'wper_checklist_item_labels', $labels );
	}

	/**
	 * 항목 라벨 조회 — 표에 없으면 저장된 라벨로 폴백한다 (구버전 기록 호환).
	 */
	public static function item_label( string $key, string $stored = '' ): string {
		$labels = self::item_labels();

		return $labels[ $key ] ?? $stored;
	}

	/**
	 * 진단 시작 — URL 샘플링 · 매니페스트 · 토큰 발급.
	 *
	 * ⭐ 확장 지점 3개가 여기 모여 있다. 대상 URL(`wper_checklist_urls`) 과 실행할
	 *    스텝 목록(`wper_checklist_manifest`) 을 갈아 끼우면 **같은 러너로 다른 진단**
	 *    이 돌아간다 — 러너는 무엇을 재는지 알 필요가 없다.
	 * ⚠ `wper_checklist_run_context` 는 키를 **추가**하는 자리다. urls · manifest 는
	 *   필터 뒤에 되돌려 놓는다 — 그 둘이 사라지면 스텝 판정 자체가 무너진다.
	 *
	 * @param array $args 호출자가 넘기는 임의 인자 (필터로 그대로 전달된다).
	 */
	public static function create_run( array $args = [] ): array {
		$urls     = (array) apply_filters( 'wper_checklist_urls', self::sample_urls(), $args );
		$manifest = (array) apply_filters( 'wper_checklist_manifest', self::manifest( $urls ), $urls, $args );

		$context = (array) apply_filters(
			'wper_checklist_run_context',
			[ 'urls' => $urls, 'manifest' => wp_list_pluck( $manifest, 'step' ) ],
			$urls,
			$args
		);

		$context['urls']     = $urls;
		$context['manifest'] = wp_list_pluck( $manifest, 'step' );

		$run_id = WPER_Checklist_Store::create( $context );

		if ( ! $run_id ) {
			return [ 'error' => __( 'Could not create the diagnostic record.', 'wper-checklist' ) ];
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
			/* translators: %s: diagnostic step id. */
			return [ 'error' => sprintf( __( 'Unknown step: %s', 'wper-checklist' ), $step_id ) ];
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
			/* translators: %s: diagnostic step id. */
			return [ 'error' => sprintf( __( 'No handler for step: %s', 'wper-checklist' ), $step_id ) ];
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
			$scores = self::score( $results['items'], $context );
			WPER_Checklist_Store::finalize( $run_id, $scores );
			WPER_Checklist_Capture::burn();
			$response['scores'] = $scores;
		} elseif ( $complete ) {
			$response['scores'] = self::score( $results['items'], $context );
		}

		return $response;
	}

	/**
	 * 채점 — skip 은 분모 제외, 카테고리 = earned/possible × (1000 / 카테고리 수).
	 *
	 * ⚠ 카테고리 만점을 상수 200 으로 박지 않는다. 진단 구성이 5개일 때만 맞는 숫자라,
	 *   4개짜리 구성에서는 총점이 800 에 갇힌다 — 만점이 1000 이라는 약속이 깨진다.
	 *
	 * @param array $context 진단 컨텍스트 (카테고리 구성의 근거).
	 */
	public static function score( array $items, array $context = [] ): array {
		$labels = self::categories( $context );
		$per    = 1000 / max( 1, count( $labels ) );

		$cats = [];
		foreach ( array_keys( $labels ) as $cat ) {
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
			$row['score'] = $row['possible'] > 0 ? (int) round( $row['earned'] / $row['possible'] * $per ) : 0;
			$total       += $row['score'];
		}
		unset( $row );

		return [ 'total' => $total, 'per' => (int) round( $per ), 'cats' => $cats ];
	}

	/* ---------------------------------------------------------------- 내부 */

	/**
	 * 스텝 접두사 → 검사 클래스.
	 *
	 * ⭐ 확장은 **접두사를 등록**한다 (`wper_checklist_dispatch`). 클래스 이름을 직접
	 *    바꿔치기하는 대신 표를 늘리는 형태라, 기본 스텝의 담당자는 그대로 남는다.
	 * ⚠ 존재하지 않는 클래스가 오면 치명적 오류가 아니라 "핸들러 없음" 으로 떨군다 —
	 *   확장의 오타가 진단 전체를 죽이지 않게.
	 */
	private static function dispatch_class( string $step_id ): ?string {
		$prefix = strtok( $step_id, ':' );

		$map = (array) apply_filters(
			'wper_checklist_dispatch',
			[
				'latency' => 'WPER_Checklist_Check_Latency',
				'db'      => 'WPER_Checklist_Check_DB',
				'seo'     => 'WPER_Checklist_Check_SEO',
				'vuln'    => 'WPER_Checklist_Check_Vuln_WP',
				'server'  => 'WPER_Checklist_Check_Server',
			],
			$step_id
		);

		$class = $map[ $prefix ] ?? null;

		return ( is_string( $class ) && class_exists( $class ) && method_exists( $class, 'run' ) ) ? $class : null;
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
			[ 'step' => 'latency:home', 'cat' => 'latency', 'label' => __( 'Measuring landing response (3 runs)', 'wper-checklist' ) ],
			[ 'step' => 'latency:archive', 'cat' => 'latency', 'label' => __( 'Measuring archive response', 'wper-checklist' ) ],
			[ 'step' => 'latency:single', 'cat' => 'latency', 'label' => __( 'Measuring post response', 'wper-checklist' ) ],
			[ 'step' => 'latency:page', 'cat' => 'latency', 'label' => __( 'Measuring page response', 'wper-checklist' ) ],
			[ 'step' => 'latency:product', 'cat' => 'latency', 'label' => __( 'Measuring product/service response', 'wper-checklist' ) ],
			[ 'step' => 'latency:redirects', 'cat' => 'latency', 'label' => __( 'Checking redirect chains', 'wper-checklist' ) ],
			[ 'step' => 'db:home', 'cat' => 'db', 'label' => __( 'Capturing landing queries (3 runs)', 'wper-checklist' ) ],
			[ 'step' => 'db:single', 'cat' => 'db', 'label' => __( 'Capturing post queries', 'wper-checklist' ) ],
			[ 'step' => 'db:archive', 'cat' => 'db', 'label' => __( 'Capturing archive queries', 'wper-checklist' ) ],
			[ 'step' => 'db:analyze', 'cat' => 'db', 'label' => __( 'Analyzing query patterns', 'wper-checklist' ) ],
			[ 'step' => 'seo:landing', 'cat' => 'seo', 'label' => __( 'Analyzing landing SEO', 'wper-checklist' ) ],
			[ 'step' => 'seo:site', 'cat' => 'seo', 'label' => __( 'robots.txt · sitemap', 'wper-checklist' ) ],
			[ 'step' => 'vuln:core', 'cat' => 'vuln', 'label' => __( 'Looking up core vulnerabilities', 'wper-checklist' ) ],
			[ 'step' => 'vuln:plugins', 'cat' => 'vuln', 'label' => __( 'Looking up plugin vulnerabilities', 'wper-checklist' ), 'total' => max( 1, count( (array) get_option( 'active_plugins', [] ) ) ) ],
			[ 'step' => 'vuln:themes', 'cat' => 'vuln', 'label' => __( 'Looking up theme vulnerabilities', 'wper-checklist' ) ],
			[ 'step' => 'vuln:js', 'cat' => 'vuln', 'label' => __( 'Front-end JS libraries', 'wper-checklist' ) ],
			[ 'step' => 'vuln:probes', 'cat' => 'vuln', 'label' => __( 'Exposure probes', 'wper-checklist' ) ],
			[ 'step' => 'server:php', 'cat' => 'server', 'label' => __( 'Checking PHP settings', 'wper-checklist' ) ],
			[ 'step' => 'server:wp', 'cat' => 'server', 'label' => __( 'Checking wp-config', 'wper-checklist' ) ],
			[ 'step' => 'server:headers', 'cat' => 'server', 'label' => __( 'Security response headers', 'wper-checklist' ) ],
			[ 'step' => 'server:files', 'cat' => 'server', 'label' => __( 'Sensitive path probes', 'wper-checklist' ) ],
			[ 'step' => 'server:db', 'cat' => 'server', 'label' => __( 'Database server variables', 'wper-checklist' ) ],
		];

		return $steps;
	}
}
