<?php
/**
 * Plugin Name: WPER Checklist
 * Plugin URI: https://github.com/wperkr/wper-checklist
 * Description: 워드프레스 사이트 건강 진단 — 지연시간 · DB 쿼리 · SEO · 취약점 · 서버 설정을 검사해 1000점 만점 리포트를 만듭니다.
 * Version: 1.0.0
 * Requires PHP: 8.1
 * Author: WPER
 * Author URI: https://wper.kr
 * License: GPL-3.0
 * Text Domain: wper-checklist
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access not allowed.
}

define( 'WPER_CHECKLIST_VERSION', '1.0.0' );
define( 'WPER_CHECKLIST_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPER_CHECKLIST_URL', plugin_dir_url( __FILE__ ) );

require_once WPER_CHECKLIST_PATH . 'includes/i18n.php';
require_once WPER_CHECKLIST_PATH . 'includes/class-capture.php';

/*
 * ⚠ 캡처 무장은 **파일 로드 시점**이어야 한다 — 훅에 걸면 늦는다.
 *
 * wpdb 는 쿼리마다 `defined('SAVEQUERIES')` 를 다시 보므로, 메인 쿼리가 돌기 전
 * (= 플러그인 로드 중) 에 define 해야 프론트 요청의 쿼리가 잡힌다. 토큰이 없거나
 * 틀리면 maybe_arm() 은 아무 부작용 없이 즉시 돌아온다 — 평시 트래픽 오버헤드 0.
 */
WPER_Checklist_Capture::maybe_arm();

require_once WPER_CHECKLIST_PATH . 'includes/class-http-probe.php';
require_once WPER_CHECKLIST_PATH . 'includes/class-store.php';
require_once WPER_CHECKLIST_PATH . 'includes/checks/class-check-latency.php';
require_once WPER_CHECKLIST_PATH . 'includes/checks/class-check-db.php';
require_once WPER_CHECKLIST_PATH . 'includes/checks/class-check-seo.php';
require_once WPER_CHECKLIST_PATH . 'includes/checks/class-check-vuln-wp.php';
require_once WPER_CHECKLIST_PATH . 'includes/checks/class-check-server.php';
require_once WPER_CHECKLIST_PATH . 'includes/class-runner.php';
require_once WPER_CHECKLIST_PATH . 'includes/class-report.php';
require_once WPER_CHECKLIST_PATH . 'includes/class-rest.php';
require_once WPER_CHECKLIST_PATH . 'includes/class-admin.php';

/**
 * 활성화: 이력 CPT 등록. (rewrite 없음 — flush 불요)
 */
function wper_checklist_activate() {
	WPER_Checklist_Store::register();
}
register_activation_hook( __FILE__, 'wper_checklist_activate' );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WPER_CHECKLIST_PATH . 'includes/class-cli.php';
	WP_CLI::add_command( 'wper checklist', 'WPER_Checklist_CLI' );
}
