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
	 * [--lang=<lang>]
	 * : 보고서 언어 ko(기본) 또는 en. json 출력에는 영향 없음.
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
			WP_CLI::error( '진단이 완료되지 않았습니다.' );
		}

		$scores = $final['scores'];

		if ( 'json' === $format ) {
			$results = WPER_Checklist_Store::results( $run_id );
			WP_CLI::log( (string) wp_json_encode( [ 'run_id' => $run_id, 'scores' => $scores, 'items' => array_values( $results['items'] ) ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
			return;
		}

		$rows = [];
		foreach ( WPER_Checklist_Runner::CATS as $cat => $label ) {
			$row    = $scores['cats'][ $cat ];
			$rows[] = [
				'영역'   => $label,
				'점수'   => $row['score'] . ' / 200',
				'통과'   => $row['counts']['pass'],
				'주의'   => $row['counts']['warn'],
				'실패'   => $row['counts']['fail'],
				'확인불가' => $row['counts']['skip'],
			];
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ '영역', '점수', '통과', '주의', '실패', '확인불가' ] );
		WP_CLI::success( sprintf( '총점 %d / 1000 (run #%d)', $scores['total'], $run_id ) );
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
			WP_CLI::log( '이력이 없습니다.' );
			return;
		}

		\WP_CLI\Utils\format_items(
			'table',
			array_map(
				static fn( $r ) => [ 'ID' => $r['id'], '일시' => $r['date'], '총점' => $r['total'] . ' / 1000' ],
				$rows
			),
			[ 'ID', '일시', '총점' ]
		);
	}
}
