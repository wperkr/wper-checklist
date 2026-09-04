<?php
/**
 * 진단 이력 저장 — 비공개 CPT `wper_check_run`.
 *
 * ⭐ 옵션이 아니라 CPT 인 이유: 전/후 비교에는 날짜순 다건 이력이 필요하고,
 *    옵션 한 개에 배열로 쌓으면 autoload 비대(§CLAUDE.md Gotchas)가 되거나
 *    수제 인덱스를 만들게 된다 — CPT 가 그 전부를 공짜로 준다.
 * ⚠ show_in_rest 없음 — 진단 결과에는 서버 내부 사실(쿼리 수 · 설정값)이 담긴다.
 *   소비 경로는 우리 관리자 화면(권한 검사)과 WP-CLI 뿐이다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPER_Checklist_Store {

	const CPT  = 'wper_check_run';
	const KEEP = 20;

	public static function register(): void {
		register_post_type(
			self::CPT,
			[
				'labels'              => [
					'name'          => __( 'Site diagnostics', 'wper-checklist' ),
					'singular_name' => __( 'Diagnostic run', 'wper-checklist' ),
				],
				'public'              => false,
				'show_ui'             => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'supports'            => [ 'title' ],
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			]
		);
	}

	/**
	 * 새 진단 레코드. 결과는 스텝마다 merge 된다.
	 */
	public static function create( array $context ): int {
		$id = wp_insert_post(
			[
				'post_type'   => self::CPT,
				'post_status' => 'private',
				/* translators: %s: run timestamp. */
				'post_title'  => sprintf( __( 'Diagnostics %s', 'wper-checklist' ), wp_date( 'Y-m-d H:i' ) ),
			],
			true
		);

		if ( is_wp_error( $id ) ) {
			return 0;
		}

		self::write_json( $id, '_wper_check_context', $context );
		self::write_json( $id, '_wper_check_result', [ 'items' => [], 'steps_done' => [] ] );

		self::prune( self::KEEP );

		return (int) $id;
	}

	public static function context( int $id ): array {
		$raw = get_post_meta( $id, '_wper_check_context', true );
		$ctx = json_decode( (string) $raw, true );
		return is_array( $ctx ) ? $ctx : [];
	}

	public static function results( int $id ): array {
		$raw = get_post_meta( $id, '_wper_check_result', true );
		$res = json_decode( (string) $raw, true );
		return is_array( $res ) ? $res : [ 'items' => [], 'steps_done' => [] ];
	}

	/**
	 * 스텝 결과 merge — (step, cursor) 재전송에 멱등: 같은 항목 키는 덮어쓴다.
	 *
	 * @param array $items 각 [ key, cat, label, status, score, possible, measured ].
	 */
	public static function save_items( int $id, string $step_id, array $items, bool $step_done ): void {
		$res = self::results( $id );

		foreach ( $items as $item ) {
			if ( empty( $item['key'] ) ) {
				continue;
			}
			$res['items'][ $item['key'] ] = $item;
		}

		if ( $step_done ) {
			$res['steps_done'][ $step_id ] = 1;
		}

		self::write_json( $id, '_wper_check_result', $res );
	}

	/**
	 * JSON → 포스트 메타. ⚠ 반드시 wp_slash() 를 거친다 — update_post_meta 는 입력을
	 * unslash 하므로, 날 JSON 을 넣으면 문자열 안의 `\"` 이스케이프가 벗겨져 **저장은
	 * 되는데 읽기 decode 만 실패**한다 (2026-09-04 실측: 측정치에 따옴표가 처음 들어온
	 * seo:landing 스텝에서 이력 전체가 조용히 증발했다).
	 */
	private static function write_json( int $id, string $key, array $value ): void {
		update_post_meta( $id, $key, wp_slash( (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) ) );
	}

	/**
	 * 최종 점수 기록.
	 *
	 * @param array $scores [ total => int, cats => [ cat => [earned, possible, score] ] ].
	 */
	public static function finalize( int $id, array $scores ): void {
		update_post_meta( $id, '_wper_check_total', (int) $scores['total'] );
		foreach ( $scores['cats'] as $cat => $row ) {
			update_post_meta( $id, '_wper_check_cat_' . $cat, (int) $row['score'] );
		}
		update_post_meta( $id, '_wper_check_complete', '1' );
	}

	public static function is_complete( int $id ): bool {
		return '1' === get_post_meta( $id, '_wper_check_complete', true );
	}

	/**
	 * 최근 완료 진단 목록 (새것 먼저).
	 */
	public static function recent( int $n = 10 ): array {
		$posts = get_posts(
			[
				'post_type'      => self::CPT,
				'post_status'    => 'private',
				'posts_per_page' => $n,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_key'       => '_wper_check_complete', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);

		return array_map(
			static fn( $p ) => [
				'id'    => (int) $p->ID,
				'title' => $p->post_title,
				'date'  => $p->post_date,
				'total' => (int) get_post_meta( $p->ID, '_wper_check_total', true ),
			],
			$posts
		);
	}

	public static function has_history(): bool {
		return (bool) self::recent( 1 );
	}

	/**
	 * 오래된 이력 정리 — 최근 $keep 건만 남긴다.
	 */
	public static function prune( int $keep ): void {
		$posts = get_posts(
			[
				'post_type'      => self::CPT,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			]
		);

		foreach ( array_slice( $posts, $keep ) as $old_id ) {
			wp_delete_post( $old_id, true );
		}
	}
}

add_action( 'init', [ 'WPER_Checklist_Store', 'register' ] );
