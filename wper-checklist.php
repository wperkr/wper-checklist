<?php
/**
 * Plugin Name: WPER Checklist
 * Plugin URI: https://github.com/wperkr/wper-checklist
 * Description: WordPress site health diagnostics — checks response time, database queries, SEO, vulnerabilities and server configuration, and produces a report scored out of 1000.
 * Version: 1.2.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: WPER
 * Author URI: https://wper.kr
 * License: GPL-3.0
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: wper-checklist
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access not allowed.
}

define( 'WPER_CHECKLIST_VERSION', '1.2.0' );
define( 'WPER_CHECKLIST_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPER_CHECKLIST_URL', plugin_dir_url( __FILE__ ) );

/**
 * 언어팩 로드 (1.1.0 — 자체 카탈로그 + KO/EN 토글을 표준 gettext 로 교체).
 *
 * ⭐ 소스 문자열은 **영어**이고 한국어는 `languages/wper-checklist-ko_KR.mo` 가 준다.
 *    화면 언어를 정하는 것은 요청 파라미터가 아니라 **사이트/사용자 로케일**이다 —
 *    관리자가 언어를 고르는 토글이 없어야 Weglot · Loco Translate · translate.w.org
 *    같은 표준 도구가 그대로 붙는다.
 *
 * ⚠ `init` 보다 이르게 부르지 않는다 — 그보다 이른 시점에는 로케일이 확정되지 않아
 *    번역이 조용히 빠진다.
 */
function wper_checklist_load_textdomain() {
	load_plugin_textdomain( 'wper-checklist', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'wper_checklist_load_textdomain' );

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
require_once WPER_CHECKLIST_PATH . 'includes/admin-menu.php';
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
