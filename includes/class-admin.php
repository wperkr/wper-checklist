<?php
/**
 * 관리자 화면 — WPER › 진단. 화면은 하나다.
 *
 * ⭐ 부모는 wper 계열 공용 최상위 메뉴 `wper` 다 (includes/admin-menu.php 의 사본 규약).
 *    단독 설치 사이트에서도 이 플러그인 자신이 그 메뉴를 만들므로 부모는 항상 있다.
 *
 * ⭐ 진입 화면에는 "진단 시작" 버튼만 둔다 (+ 이력이 있으면 지난 결과 링크).
 *    진행 · 점수 · 보고서는 전부 JS 가 같은 화면에 그린다.
 * ⚠ 자산은 이 화면 훅에서만 enqueue — 다른 관리자 화면에 CSS/JS 를 흘리지 않는다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPER_Checklist_Admin {

	const PAGE = 'wper-checklist';

	/** @var string add_management_page 가 돌려준 훅 접미. */
	private static $hook = '';

	public static function register_menu(): void {
		self::$hook = add_submenu_page(
			'wper',
			__( 'WPER Checklist', 'wper-checklist' ),
			__( 'Site health', 'wper-checklist' ),
			'manage_options',
			self::PAGE,
			[ __CLASS__, 'render' ]
		);
	}

	public static function enqueue( string $hook ): void {
		if ( $hook !== self::$hook ) {
			return;
		}

		wp_enqueue_style( 'wper-checklist', WPER_CHECKLIST_URL . 'assets/dist/admin.css', [], WPER_CHECKLIST_VERSION );
		wp_enqueue_script( 'wper-checklist', WPER_CHECKLIST_URL . 'assets/js/admin.js', [], WPER_CHECKLIST_VERSION, true );

		wp_localize_script(
			'wper-checklist',
			'wperChecklist',
			[
				'root'  => esc_url_raw( rest_url( WPER_Checklist_REST::NS ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				// JS 는 문자열을 갖지 않는다 — 전부 여기서 번역해 내려보낸다.
				// (관리자 화면 JS 하나뿐이라 wp_set_script_translations 의 JSON 파일
				//  배선을 들이는 것보다 이 편이 단순하고, 언어팩 하나로 끝난다.)
				'i18n'  => [
					'running'   => __( 'Running diagnostics…', 'wper-checklist' ),
					'preparing' => __( 'Preparing…', 'wper-checklist' ),
					'complete'  => __( 'Diagnostics complete', 'wper-checklist' ),
					'error'     => __( 'Something went wrong. Please refresh the page and try again.', 'wper-checklist' ),
					'reco'      => __( 'WPER Recommendation', 'wper-checklist' ),
					'loading'   => __( 'Building the report…', 'wper-checklist' ),
					'cats'      => WPER_Checklist_Runner::cat_labels(),
				],
			]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wper-checklist' ) );
		}

		$recent = WPER_Checklist_Store::recent( 5 );
		?>
		<div class="wrap wper-checklist">
			<h1 class="wper-checklist__title"><?php esc_html_e( 'WPER Checklist', 'wper-checklist' ); ?></h1>
			<p class="wper-checklist__lede"><?php esc_html_e( 'Response time · database queries · SEO · vulnerabilities · server configuration — five areas, scored out of 1000.', 'wper-checklist' ); ?></p>

			<div class="wper-checklist__stage" id="wper-check-stage">
				<div class="wper-checklist__intro">
					<button type="button" class="wper-check-start js-check-start"><?php esc_html_e( 'Start diagnostics', 'wper-checklist' ); ?></button>

					<?php if ( $recent ) : ?>
						<div class="wper-check-history">
							<h2 class="wper-check-history__title"><?php esc_html_e( 'Previous results', 'wper-checklist' ); ?></h2>
							<ul>
								<?php foreach ( $recent as $row ) : ?>
									<li>
										<button type="button" class="wper-check-history__open js-check-open" data-run="<?php echo esc_attr( $row['id'] ); ?>">
											<?php echo esc_html( $row['title'] ); ?>
											<strong><?php echo esc_html( $row['total'] ); ?></strong> / 1000
										</button>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}
}

add_action( 'admin_menu', [ 'WPER_Checklist_Admin', 'register_menu' ] );
add_action( 'admin_enqueue_scripts', [ 'WPER_Checklist_Admin', 'enqueue' ] );
