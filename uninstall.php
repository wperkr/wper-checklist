<?php
/**
 * 삭제 시 정리 — 진단 이력 · 트랜션트를 전부 지운다. 흔적을 남기지 않는다.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 진단 이력 CPT 전체 삭제.
$wper_checklist_runs = get_posts(
	[
		'post_type'      => 'wper_check_run',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	]
);

foreach ( $wper_checklist_runs as $wper_checklist_run_id ) {
	wp_delete_post( $wper_checklist_run_id, true );
}

// 캡처 토큰 · 데이터.
delete_transient( 'wper_checklist_cap_token' );
delete_transient( 'wper_checklist_cap_data' );

// 취약점 API 캐시 (wper_checklist_vapi_* — 키가 해시라 목록 조회로 지운다).
global $wpdb;
$wper_checklist_names = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient%wper_checklist_%'"
);
foreach ( $wper_checklist_names as $wper_checklist_name ) {
	delete_option( $wper_checklist_name );
}
