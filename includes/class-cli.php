<?php
/**
 * WP-CLI — 헤드리스 진단. REST 와 같은 Runner 를 쓴다 (채점 단일 출처).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPER_Checklist_CLI {

	/**
	 * 전체 진단을 순차 실행한다.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table(기본) 또는 json.
	 *
	 * 출력 언어는 사이트 로케일을 따른다 (1.1.0 — `--lang` 제거).
	 *
	 * ## EXAMPLES
	 *
	 *     wp wper checklist run
	 *     wp wper checklist run --format=json
	 *
	 * @subcommand run
	 */
	public function run( $args, $assoc_args ) {
		$format = $assoc_args['format'] ?? 'table';

		$created = WPER_Checklist_Runner::create_run();
		if ( isset( $created['error'] ) ) {
			WP_CLI::error( $created['error'] );
		}

		$run_id = (int) $created['run_id'];
		$final  = null;

		foreach ( $created['manifest'] as $step ) {
			$cursor = 0;
			$total  = (int) ( $step['total'] ?? 1 );

			do {
				$out = WPER_Checklist_Runner::run_step( $run_id, $step['step'], $cursor );

				if ( isset( $out['error'] ) ) {
					WP_CLI::warning( $step['step'] . ': ' . $out['error'] );
					break;
				}

				foreach ( $out['events'] as $event ) {
					WP_CLI::log( '  ' . $event );
				}

				$cursor = (int) ( $out['cursor'] ?? 0 );
				$final  = $out;
			} while ( empty( $out['done'] ) && $cursor <= $total + 1 );
		}

		if ( ! $final || empty( $final['scores'] ) ) {
			WP_CLI::error( __( 'The diagnostic run did not complete.', 'wper-checklist' ) );
		}

		$scores = $final['scores'];

		if ( 'json' === $format ) {
			$results = WPER_Checklist_Store::results( $run_id );
			WP_CLI::log( (string) wp_json_encode( [ 'run_id' => $run_id, 'scores' => $scores, 'items' => array_values( $results['items'] ) ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
			return;
		}

		// 표 머리글도 번역 대상이다 — 컬럼 키와 헤더 목록이 같은 문자열이어야 하므로
		// 한 번만 만들어 두 곳에서 쓴다 (직접 두 번 쓰면 번역이 갈리는 순간 표가 빈다).
		$head = [
			'area'  => __( 'Area', 'wper-checklist' ),
			'score' => __( 'Score', 'wper-checklist' ),
			'pass'  => __( 'Pass', 'wper-checklist' ),
			'warn'  => __( 'Warn', 'wper-checklist' ),
			'fail'  => __( 'Fail', 'wper-checklist' ),
			'skip'  => __( 'Unverifiable', 'wper-checklist' ),
		];

		$context = WPER_Checklist_Store::context( $run_id );
		$per     = (int) ( $scores['per'] ?? 200 );

		$rows = [];
		foreach ( WPER_Checklist_Runner::cat_labels( $context ) as $cat => $label ) {
			$row    = $scores['cats'][ $cat ];
			$rows[] = [
				$head['area']  => $label,
				$head['score'] => $row['score'] . ' / ' . $per,
				$head['pass']  => $row['counts']['pass'],
				$head['warn']  => $row['counts']['warn'],
				$head['fail']  => $row['counts']['fail'],
				$head['skip']  => $row['counts']['skip'],
			];
		}

		// 진단 대상을 먼저 밝힌다 — 확장이 다른 사이트를 대상으로 걸어 둘 수 있으므로,
		// 어느 주소를 잰 표인지 모른 채 숫자만 읽는 일이 없어야 한다.
		WP_CLI::log( untrailingslashit( (string) ( $context['urls']['home'] ?? home_url() ) ) );

		\WP_CLI\Utils\format_items( 'table', $rows, array_values( $head ) );
		/* translators: 1: total score out of 1000, 2: diagnostic run id. */
		WP_CLI::success( sprintf( __( 'Total %1$d / 1000 (run #%2$d)', 'wper-checklist' ), $scores['total'], $run_id ) );
	}

	/**
	 * 지난 진단 이력.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wper checklist history
	 *
	 * @subcommand history
	 */
	public function history( $args, $assoc_args ) {
		$rows = WPER_Checklist_Store::recent( 20 );

		if ( ! $rows ) {
			WP_CLI::log( __( 'No diagnostic history yet.', 'wper-checklist' ) );
			return;
		}

		$date  = __( 'Date', 'wper-checklist' );
		$total = __( 'Total', 'wper-checklist' );

		\WP_CLI\Utils\format_items(
			'table',
			array_map(
				static fn( $r ) => [ 'ID' => $r['id'], $date => $r['date'], $total => $r['total'] . ' / 1000' ],
				$rows
			),
			[ 'ID', $date, $total ]
		);
	}
}
