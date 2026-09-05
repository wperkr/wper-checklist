<?php
/**
 * 보고서 렌더러 — 서버측 DOM. "[WPER Recommendation]" 버튼이 펼치는 실체.
 *
 * ⭐ 전 항목을 표기한다 — pass 든 fail 이든 skip 이든. 선택적 보고는 한 번 들키면
 *    나머지 수치의 신뢰까지 무너뜨린다 (계측 독트린).
 * ⭐ 추천 문안은 규칙 기반 — data/recommendations.php 의 사전 작성 원인·해결.
 *    AI 호출 없음: 결과가 항상 재현 가능하고 오프라인에서도 완전하다.
 * ⭐ 언어는 **사이트 로케일**이 정한다 (1.1.0 — 보고서 언어 토글 제거). 문자열은
 *    전부 표준 gettext 를 탄다.
 *
 * ⚠ 항목 라벨은 저장된 값이 아니라 Runner::item_label() 로 다시 그린다 — 진단 결과는
 *   실행 시점 언어로 DB 에 남으므로, 저장값을 그대로 쓰면 로케일을 바꾼 사이트에서
 *   머리말과 항목명의 언어가 갈린다. 반면 `measured` 는 숫자가 섞인 합성 문자열이라
 *   재번역이 불가능하고 실행 시점 언어로 고정된다 — "그때 관측된 기록" 의 정직한 형태다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPER_Checklist_Report {

	/**
	 * 보고서 HTML — 현재 로케일로 렌더한다.
	 */
	public static function render_html( int $run_id ): string {
		$results = WPER_Checklist_Store::results( $run_id );
		$items   = $results['items'] ?? [];

		if ( ! $items ) {
			return '<p class="wper-check-empty">' . esc_html__( 'No results yet.', 'wper-checklist' ) . '</p>';
		}

		// ⭐ 컨텍스트가 채점 · 라벨 · 대상 표기의 단일 근거다. 진단마다 카테고리 구성이
		//    다를 수 있으므로, 보고서는 언제나 **그 진단이 실제로 돌던 구성**으로 그린다.
		$context = WPER_Checklist_Store::context( $run_id );
		$scores  = WPER_Checklist_Runner::score( $items, $context );
		$recos   = (array) apply_filters(
			'wper_checklist_recommendations',
			(array) include WPER_CHECKLIST_PATH . 'data/recommendations.php'
		);
		$post    = get_post( $run_id );

		ob_start();
		?>
		<div class="wper-check-result">

			<?php
			/*
			 * Summary band. The score ring, the per-category cards and the detail toggle
			 * that JS injects after `.wper-check-cats` all land in this one box, so the
			 * headline reads as a single unit instead of three stacked strips.
			 */
			?>
			<div class="wper-check-summary">
				<?php self::render_score_head( $scores, $post, $context ); ?>
				<?php self::render_cats( $scores, $context ); ?>
			</div>

			<section class="wper-check-report">
				<?php foreach ( WPER_Checklist_Runner::cat_labels( $context ) as $cat => $cat_label ) : ?>
					<?php
					$cat_items = array_filter( $items, static fn( $i ) => $cat === ( $i['cat'] ?? '' ) );
					if ( ! $cat_items ) {
						continue;
					}
					?>
					<h3 class="wper-check-report__cat"><?php echo esc_html( $cat_label ); ?>
						<span class="wper-check-report__pts"><?php echo esc_html( $scores['cats'][ $cat ]['score'] ); ?> / <?php echo esc_html( $scores['per'] ); ?></span>
					</h3>
					<ul class="wper-check-items">
						<?php foreach ( $cat_items as $item ) : ?>
							<li class="wper-check-item wper-check-item--<?php echo esc_attr( $item['status'] ); ?>">
								<span class="wper-check-badge wper-check-badge--<?php echo esc_attr( $item['status'] ); ?>"><?php echo esc_html( self::status_label( $item['status'] ) ); ?></span>
								<span class="wper-check-item__label"><?php echo esc_html( WPER_Checklist_Runner::item_label( (string) $item['key'], (string) $item['label'] ) ); ?></span>
								<span class="wper-check-item__measured"><?php echo esc_html( (string) $item['measured'] ); ?></span>
								<span class="wper-check-item__pts"><?php echo 'skip' === $item['status'] ? '—' : esc_html( round( $item['score'] ) . '/' . round( $item['possible'] ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endforeach; ?>
			</section>

			<?php self::render_recos( $items, $recos ); ?>
			<?php self::render_skip_note( $items ); ?>

			<footer class="wper-check-footer">
				<p><?php esc_html_e( 'Diagnostic tool', 'wper-checklist' ); ?>: WPER Checklist v<?php echo esc_html( WPER_CHECKLIST_VERSION ); ?> · <a href="https://wper.kr" target="_blank" rel="noopener">wper.kr</a></p>
			</footer>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function render_score_head( array $scores, ?WP_Post $post, array $context = [] ): void {
		$total = (int) $scores['total'];
		$pct   = max( 0, min( 1, $total / 1000 ) );

		// 등급 — 표시용일 뿐 점수를 대신하지 않는다.
		if ( $total >= 900 ) {
			$grade = [ 'pass', __( 'Excellent', 'wper-checklist' ) ];
		} elseif ( $total >= 700 ) {
			$grade = [ 'pass', __( 'Good', 'wper-checklist' ) ];
		} elseif ( $total >= 500 ) {
			$grade = [ 'warn', __( 'Needs attention', 'wper-checklist' ) ];
		} else {
			$grade = [ 'fail', __( 'At risk', 'wper-checklist' ) ];
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
				<p class="wper-check-score__grade wper-check-score__grade--<?php echo esc_attr( $grade[0] ); ?>"><?php echo esc_html( $grade[1] ); ?></p>
				<?php // ⚠ 진단 대상은 언제나 컨텍스트가 답한다. home_url() 로 그리면 다른 ?>
				<?php //   사이트를 잰 기록이 우리 주소로 표기돼 리포트 전체가 거짓이 된다. ?>
				<p class="wper-check-score__site"><?php echo esc_html( untrailingslashit( (string) ( $context['urls']['home'] ?? home_url() ) ) ); ?></p>
				<?php if ( $post ) : ?>
					<p class="wper-check-score__date"><?php echo esc_html( get_date_from_gmt( $post->post_date_gmt, 'Y-m-d H:i' ) ); ?></p>
				<?php endif; ?>
			</div>
		</header>
		<?php
	}

	private static function render_cats( array $scores, array $context = [] ): void {
		$per = max( 1, (int) ( $scores['per'] ?? 200 ) );
		?>
		<div class="wper-check-cats">
			<?php foreach ( WPER_Checklist_Runner::cat_labels( $context ) as $cat => $cat_label ) : ?>
				<?php
				$row   = $scores['cats'][ $cat ];
				$ratio = $row['score'] / $per;
				$pct   = (int) round( $ratio * 100 );
				$cls   = $ratio >= 0.8 ? 'ok' : ( $ratio >= 0.5 ? 'warn' : 'fail' );
				?>
				<div class="wper-check-cat">
					<div class="wper-check-cat__head">
						<span class="wper-check-cat__name"><?php echo esc_html( $cat_label ); ?></span>
						<span class="wper-check-cat__score"><?php echo esc_html( $row['score'] ); ?> <small>/ <?php echo esc_html( $per ); ?></small></span>
					</div>
					<div class="wper-check-meter">
						<div class="wper-check-meter__track">
							<div class="wper-check-meter__bar wper-check-meter__bar--<?php echo esc_attr( $cls ); ?>" style="inline-size: <?php echo esc_attr( $pct ); ?>%"></div>
						</div>
					</div>
					<p class="wper-check-cat__counts">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: pass count, 2: warning count, 3: fail count. */
								__( 'Pass %1$d · Warn %2$d · Fail %3$d', 'wper-checklist' ),
								(int) $row['counts']['pass'],
								(int) $row['counts']['warn'],
								(int) $row['counts']['fail']
							)
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
				<h3><?php esc_html_e( 'WPER recommendations', 'wper-checklist' ); ?></h3>
				<p class="wper-check-recos__clean"><?php esc_html_e( 'Nothing needs improvement. Keep it as it is.', 'wper-checklist' ); ?></p>
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
			<h3><?php esc_html_e( 'WPER recommendations', 'wper-checklist' ); ?></h3>
			<?php foreach ( $needs as $item ) : ?>
				<?php $reco = $recos[ $item['key'] ] ?? null; ?>
				<article class="wper-check-reco wper-check-reco--<?php echo esc_attr( $item['status'] ); ?>">
					<h4 class="wper-check-reco__title">
						<span class="wper-check-badge wper-check-badge--<?php echo esc_attr( $item['status'] ); ?>"><?php echo esc_html( self::status_label( $item['status'] ) ); ?></span>
						<?php echo esc_html( WPER_Checklist_Runner::item_label( (string) $item['key'], (string) $item['label'] ) ); ?>
					</h4>
					<p class="wper-check-reco__measured"><?php echo esc_html( (string) $item['measured'] ); ?></p>
					<?php if ( $reco ) : ?>
						<p class="wper-check-reco__cause"><strong><?php esc_html_e( 'Cause', 'wper-checklist' ); ?>:</strong> <?php echo esc_html( $reco['cause'] ); ?></p>
						<p class="wper-check-reco__fix"><strong><?php esc_html_e( 'Fix', 'wper-checklist' ); ?>:</strong> <?php echo esc_html( $reco['fix'] ); ?></p>
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
			<h4><?php esc_html_e( 'Items that could not be verified', 'wper-checklist' ); ?></h4>
			<p>
				<?php
				printf(
					esc_html(
						/* translators: %d: number of items that could not be measured. */
						_n(
							'%d item could not be measured in this run, so it was excluded from the score denominator. We never report an unmeasured item as a pass or a failure.',
							'%d items could not be measured in this run, so they were excluded from the score denominator. We never report an unmeasured item as a pass or a failure.',
							count( $skips ),
							'wper-checklist'
						)
					),
					count( $skips )
				);
				?>
			</p>
			<ul>
				<?php foreach ( $skips as $item ) : ?>
					<li><?php echo esc_html( WPER_Checklist_Runner::item_label( (string) $item['key'], (string) $item['label'] ) ); ?> — <?php echo esc_html( (string) $item['measured'] ); ?></li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	private static function status_label( string $status ): string {
		$labels = [
			'pass' => __( 'Pass', 'wper-checklist' ),
			'warn' => __( 'Warn', 'wper-checklist' ),
			'fail' => __( 'Fail', 'wper-checklist' ),
			'skip' => __( 'Unverifiable', 'wper-checklist' ),
		];

		return $labels[ $status ] ?? $status;
	}
}
