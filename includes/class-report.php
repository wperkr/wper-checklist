<?php
/**
 * 보고서 렌더러 — 서버측 DOM. "[WPER Recommendation (번역 적용)]" 의 실체.
 *
 * ⭐ 전 항목을 표기한다 — pass 든 fail 이든 skip 이든. 선택적 보고는 한 번 들키면
 *    나머지 수치의 신뢰까지 무너뜨린다 (계측 독트린).
 * ⭐ 추천 문안은 규칙 기반 — data/recommendations.php 의 사전 작성 원인·해결.
 *    AI 호출 없음: 결과가 항상 재현 가능하고 오프라인에서도 완전하다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPER_Checklist_Report {

	/**
	 * 보고서 HTML. $lang: ko | en.
	 */
	public static function render_html( int $run_id, string $lang = 'ko' ): string {
		WPER_Checklist_I18N::set_lang( $lang );

		$results = WPER_Checklist_Store::results( $run_id );
		$items   = $results['items'] ?? [];

		if ( ! $items ) {
			return '<p class="wper-check-empty">' . esc_html( wper_checklist_t( '결과가 없습니다.' ) ) . '</p>';
		}

		$scores = WPER_Checklist_Runner::score( $items );
		$recos  = (array) include WPER_CHECKLIST_PATH . 'data/recommendations.php';
		$post   = get_post( $run_id );

		ob_start();
		?>
		<div class="wper-check-result">

			<?php self::render_score_head( $scores, $post ); ?>
			<?php self::render_cats( $scores ); ?>

			<section class="wper-check-report">
				<?php foreach ( WPER_Checklist_Runner::CATS as $cat => $cat_label ) : ?>
					<?php
					$cat_items = array_filter( $items, static fn( $i ) => $cat === ( $i['cat'] ?? '' ) );
					if ( ! $cat_items ) {
						continue;
					}
					?>
					<h3 class="wper-check-report__cat"><?php wper_checklist_e( $cat_label ); ?>
						<span class="wper-check-report__pts"><?php echo esc_html( $scores['cats'][ $cat ]['score'] ); ?> / 200</span>
					</h3>
					<ul class="wper-check-items">
						<?php foreach ( $cat_items as $item ) : ?>
							<li class="wper-check-item wper-check-item--<?php echo esc_attr( $item['status'] ); ?>">
								<span class="wper-check-badge wper-check-badge--<?php echo esc_attr( $item['status'] ); ?>"><?php echo esc_html( self::status_label( $item['status'] ) ); ?></span>
								<span class="wper-check-item__label"><?php echo esc_html( wper_checklist_t( $item['label'] ) ); ?></span>
								<span class="wper-check-item__measured"><?php echo esc_html( WPER_Checklist_I18N::t_mixed( (string) $item['measured'] ) ); ?></span>
								<span class="wper-check-item__pts"><?php echo 'skip' === $item['status'] ? '—' : esc_html( round( $item['score'] ) . '/' . round( $item['possible'] ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endforeach; ?>
			</section>

			<?php self::render_recos( $items, $recos ); ?>
			<?php self::render_skip_note( $items ); ?>

			<footer class="wper-check-footer">
				<p><?php wper_checklist_e( '진단 도구' ); ?>: WPER Checklist v<?php echo esc_html( WPER_CHECKLIST_VERSION ); ?> · <a href="https://wper.kr" target="_blank" rel="noopener">wper.kr</a></p>
			</footer>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function render_score_head( array $scores, ?WP_Post $post ): void {
		$total = (int) $scores['total'];
		$pct   = max( 0, min( 1, $total / 1000 ) );

		// 등급 — 표시용일 뿐 점수를 대신하지 않는다.
		if ( $total >= 900 ) {
			$grade = [ 'pass', '우수' ];
		} elseif ( $total >= 700 ) {
			$grade = [ 'pass', '양호' ];
		} elseif ( $total >= 500 ) {
			$grade = [ 'warn', '주의 필요' ];
		} else {
			$grade = [ 'fail', '위험' ];
		}

		$c      = 2 * M_PI * 52; // 링 둘레.
		$offset = $c * ( 1 - $pct );
		?>
		<header class="wper-check-score">
			<div class="wper-check-ring wper-check-ring--<?php echo esc_attr( $grade[0] ); ?>">
				<svg viewBox="0 0 120 120" role="img" aria-label="<?php echo esc_attr( $total ); ?> / 1000">
					<circle class="wper-check-ring__track" cx="60" cy="60" r="52" />
					<?php // 계산된 인라인 값 예외 — 컴포넌트 클래스는 별도 존재. ?>
					<circle class="wper-check-ring__bar" cx="60" cy="60" r="52"
						stroke-dasharray="<?php echo esc_attr( round( $c, 2 ) ); ?>"
						stroke-dashoffset="<?php echo esc_attr( round( $offset, 2 ) ); ?>" />
				</svg>
				<div class="wper-check-ring__num">
					<strong><?php echo esc_html( $total ); ?></strong>
					<span>/ 1000</span>
				</div>
			</div>
			<div class="wper-check-score__meta">
				<p class="wper-check-score__grade wper-check-score__grade--<?php echo esc_attr( $grade[0] ); ?>"><?php wper_checklist_e( $grade[1] ); ?></p>
				<p class="wper-check-score__site"><?php echo esc_html( home_url() ); ?></p>
				<?php if ( $post ) : ?>
					<p class="wper-check-score__date"><?php echo esc_html( get_date_from_gmt( $post->post_date_gmt, 'Y-m-d H:i' ) ); ?></p>
				<?php endif; ?>
			</div>
		</header>
		<?php
	}

	private static function render_cats( array $scores ): void {
		?>
		<div class="wper-check-cats">
			<?php foreach ( WPER_Checklist_Runner::CATS as $cat => $cat_label ) : ?>
				<?php
				$row = $scores['cats'][ $cat ];
				$pct = (int) round( $row['score'] / 2 ); // 200 만점 → %.
				$cls = $row['score'] >= 160 ? 'ok' : ( $row['score'] >= 100 ? 'warn' : 'fail' );
				?>
				<div class="wper-check-cat">
					<div class="wper-check-cat__head">
						<span class="wper-check-cat__name"><?php wper_checklist_e( $cat_label ); ?></span>
						<span class="wper-check-cat__score"><?php echo esc_html( $row['score'] ); ?> <small>/ 200</small></span>
					</div>
					<div class="wper-check-meter">
						<div class="wper-check-meter__track">
							<div class="wper-check-meter__bar wper-check-meter__bar--<?php echo esc_attr( $cls ); ?>" style="inline-size: <?php echo esc_attr( $pct ); ?>%"></div>
						</div>
					</div>
					<p class="wper-check-cat__counts">
						<?php
						printf(
							/* 상태 개수 요약 */
							esc_html( wper_checklist_t( '통과 %1$d · 주의 %2$d · 실패 %3$d' ) ),
							(int) $row['counts']['pass'],
							(int) $row['counts']['warn'],
							(int) $row['counts']['fail']
						);
						?>
					</p>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function render_recos( array $items, array $recos ): void {
		$needs = array_filter( $items, static fn( $i ) => in_array( $i['status'], [ 'fail', 'warn' ], true ) );

		if ( ! $needs ) {
			?>
			<section class="wper-check-recos">
				<h3><?php wper_checklist_e( 'WPER 권장 조치' ); ?></h3>
				<p class="wper-check-recos__clean"><?php wper_checklist_e( '개선이 필요한 항목이 없습니다. 현재 상태를 유지하세요.' ); ?></p>
			</section>
			<?php
			return;
		}

		// fail 먼저, 그다음 warn — 배점 큰 순.
		usort(
			$needs,
			static function ( $a, $b ) {
				$rank = [ 'fail' => 0, 'warn' => 1 ];
				return [ $rank[ $a['status'] ], -$a['possible'] ] <=> [ $rank[ $b['status'] ], -$b['possible'] ];
			}
		);
		?>
		<section class="wper-check-recos">
			<h3><?php wper_checklist_e( 'WPER 권장 조치' ); ?></h3>
			<?php foreach ( $needs as $item ) : ?>
				<?php $reco = $recos[ $item['key'] ] ?? null; ?>
				<article class="wper-check-reco wper-check-reco--<?php echo esc_attr( $item['status'] ); ?>">
					<h4 class="wper-check-reco__title">
						<span class="wper-check-badge wper-check-badge--<?php echo esc_attr( $item['status'] ); ?>"><?php echo esc_html( self::status_label( $item['status'] ) ); ?></span>
						<?php echo esc_html( wper_checklist_t( $item['label'] ) ); ?>
					</h4>
					<p class="wper-check-reco__measured"><?php echo esc_html( WPER_Checklist_I18N::t_mixed( (string) $item['measured'] ) ); ?></p>
					<?php if ( $reco ) : ?>
						<p class="wper-check-reco__cause"><strong><?php wper_checklist_e( '원인' ); ?>:</strong> <?php echo esc_html( wper_checklist_t( $reco['cause'] ) ); ?></p>
						<p class="wper-check-reco__fix"><strong><?php wper_checklist_e( '해결' ); ?>:</strong> <?php echo esc_html( wper_checklist_t( $reco['fix'] ) ); ?></p>
					<?php endif; ?>
				</article>
			<?php endforeach; ?>
		</section>
		<?php
	}

	private static function render_skip_note( array $items ): void {
		$skips = array_filter( $items, static fn( $i ) => 'skip' === $i['status'] );
		if ( ! $skips ) {
			return;
		}
		?>
		<section class="wper-check-skips">
			<h4><?php wper_checklist_e( '확인 불가 항목' ); ?></h4>
			<p>
				<?php
				printf(
					/* skip 항목 수 */
					esc_html( wper_checklist_t( '%d개 항목은 이번 진단에서 측정할 수 없어 점수 분모에서 제외했습니다. 측정하지 않은 것을 통과나 실패로 표기하지 않습니다.' ) ),
					count( $skips )
				);
				?>
			</p>
			<ul>
				<?php foreach ( $skips as $item ) : ?>
					<li><?php echo esc_html( wper_checklist_t( $item['label'] ) ); ?> — <?php echo esc_html( WPER_Checklist_I18N::t_mixed( (string) $item['measured'] ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	private static function status_label( string $status ): string {
		return wper_checklist_t( [ 'pass' => '통과', 'warn' => '주의', 'fail' => '실패', 'skip' => '확인 불가' ][ $status ] ?? $status );
	}
}
