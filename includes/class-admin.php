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
			<?php
			/*
			 * ⚠ 영역 이름을 문장에 박아 두지 않는다. 카테고리 구성은 확장이 갈아 끼울 수
			 *   있으므로(§Extending), 고정 문장을 두면 "5개 영역" 이라고 적힌 화면이
			 *   4개짜리 채점표를 내놓는 상태가 된다 — 화면이 자기 결과를 부정하게 된다.
			 */
			$cats = WPER_Checklist_Runner::cat_labels();
			?>
			<p class="wper-checklist__lede">
				<?php
				printf(
					/* translators: 1: category names joined by a separator, 2: number of categories. */
					esc_html__( '%1$s — %2$d areas, scored out of 1000.', 'wper-checklist' ),
					esc_html( implode( ' · ', $cats ) ),
					count( $cats )
				);
				?>
			</p>

			<?php
			/**
			 * 화면 확장 지점 — 시작 버튼 무대 바로 앞.
			 *
			 * ⭐ 이 훅 하나로 "제목 옆 절대 위치 UI" 와 "무대 위 인라인 패널" 을 동시에
			 *    붙일 수 있다. 절대 위치는 DOM 순서를 따지지 않으므로 훅이 하나면 된다 —
			 *    `.wper-checklist` 는 position: relative 를 전제로 삼아도 좋다.
			 * ⚠ 이 플러그인은 여기에 무엇이 붙는지 알지 않는다. 확장이 없으면 아무 일도
			 *   일어나지 않고, 있으면 그 확장이 자기 자산을 스스로 등록한다.
			 */
			do_action( 'wper_checklist_before_stage' );
			?>

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
