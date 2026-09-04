<?php
/**
 * 관리자 화면 — 도구 › WPER 진단. 화면은 하나다.
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
		self::$hook = add_management_page(
			'WPER 진단',
			'WPER 진단',
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
				'i18n'  => [
					'running'   => '진단 중…',
					'preparing' => '진단 준비 중…',
					'complete'  => '진단 완료',
					'error'     => '오류가 발생했습니다. 화면을 새로고침한 뒤 다시 시도해 주세요.',
					'reco'      => 'WPER Recommendation (번역 적용)',
					'loading'   => '보고서 생성 중…',
				],
			]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '권한이 없습니다.' );
		}

		$recent = WPER_Checklist_Store::recent( 5 );
		?>
		<div class="wrap wper-checklist">
			<h1 class="wper-checklist__title">WPER 진단</h1>
			<p class="wper-checklist__lede">응답 속도 · DB 쿼리 · SEO · 취약점 · 서버 설정 — 5개 영역, 1000점 만점.</p>

			<div class="wper-checklist__stage" id="wper-check-stage">
				<div class="wper-checklist__intro">
					<button type="button" class="wper-check-start js-check-start">진단 시작</button>

					<?php if ( $recent ) : ?>
						<div class="wper-check-history">
							<h2 class="wper-check-history__title">지난 결과</h2>
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
