<?php
/**
 * WPER 공용 최상위 관리자 메뉴 — wper 계열 플러그인이 함께 쓰는 부모.
 *
 * ⭐ 규약: wper 계열 플러그인은 각자 **이 파일의 동일 사본**을 갖는다. 전부
 *    `function_exists` 가드라 먼저 로드된 쪽이 정의를 가져가고, 어떤 조합으로
 *    설치돼도 최상위 `WPER` 메뉴는 정확히 하나만 생긴다 — 독립 배포 플러그인
 *    (wper-checklist · wper-simple-admin-menu)이 wper-core 없이도 서야 하므로
 *    공용 라이브러리 대신 사본 규약을 쓴다.
 *
 * ⚠ 사본 v2 — 고치면 다섯 곳을 함께 고친다:
 *   wper-core/includes/admin-menu.php
 *   wper-contact/includes/admin-menu.php
 *   wper-payments/includes/admin-menu.php
 *   wper-checklist/includes/admin-menu.php
 *   wper-simple-admin-menu/includes/admin-menu.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wper_admin_menu_ensure' ) ) {

	/**
	 * 최상위 WPER 메뉴가 없으면 만든다. admin_menu 우선순위 5 —
	 * CPT(`show_in_menu => 'wper'`) 서브메뉴는 코어가 그 전에 $submenu 에 쌓아 두고,
	 * 화면(add_submenu_page)들은 이 뒤(기본 10+)에 합류한다.
	 */
	function wper_admin_menu_ensure(): void {
		global $admin_page_hooks;

		if ( ! empty( $admin_page_hooks['wper'] ) ) {
			return;
		}

		add_menu_page(
			'WPER',
			'WPER',
			'manage_options',
			'wper',
			'wper_admin_menu_dashboard',
			'dashicons-shield',
			3 // 알림판 바로 아래 — 지원 센터 브랜드 자리.
		);
	}

	/**
	 * WPER 대시보드 — 등록된 하위 화면을 카드로 나열한다.
	 * 목록은 $submenu['wper'] 를 그대로 읽으므로 어떤 플러그인 조합이든 스스로 맞다.
	 */
	function wper_admin_menu_dashboard(): void {
		global $submenu;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '권한이 없습니다.' );
		}

		$entries = [];
		foreach ( (array) ( $submenu['wper'] ?? [] ) as $item ) {
			if ( 'wper' === ( $item[2] ?? '' ) || ! current_user_can( $item[1] ?? 'manage_options' ) ) {
				continue;
			}
			$slug      = (string) $item[2];
			$url       = str_contains( $slug, '.php' ) ? admin_url( $slug ) : admin_url( 'admin.php?page=' . $slug );
			$entries[] = [ 'title' => wp_strip_all_tags( (string) $item[0] ), 'url' => $url ];
		}
		?>
		<div class="wrap">
			<h1>WPER — 워드프레스 지원 센터</h1>
			<p>설치된 WPER 도구입니다. 문제가 막히면 <a href="https://wper.kr" target="_blank" rel="noopener">wper.kr</a> 에서 유료 해결을 의뢰할 수 있습니다.</p>

			<?php if ( $entries ) : ?>
				<table class="widefat striped" style="max-width: 640px;">
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( $entry['url'] ); ?>"><strong><?php echo esc_html( $entry['title'] ); ?></strong></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p>등록된 도구가 없습니다.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	add_action( 'admin_menu', 'wper_admin_menu_ensure', 5 );
}
