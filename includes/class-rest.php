<?php
/**
 * REST — wper-checklist/v1.
 *
 * ⭐ 여기의 permission_callback 은 진짜 권한 검사다. 결제 웹훅류의 `__return_true`
 *    는 "외부 서버가 서명으로 인증하는 경우" 의 예외이고, 이 라우트의 호출자는
 *    로그인한 관리자 브라우저다 — 쿠키 인증 + X-WP-Nonce + manage_options.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPER_Checklist_REST {

	const NS = 'wper-checklist/v1';

	public static function register_routes(): void {
		$perm = static fn() => current_user_can( 'manage_options' );

		register_rest_route(
			self::NS,
			'/run',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'create_run' ],
				'permission_callback' => $perm,
			]
		);

		register_rest_route(
			self::NS,
			'/step',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'run_step' ],
				'permission_callback' => $perm,
				'args'                => [
					'run_id' => [ 'required' => true, 'type' => 'integer' ],
					'step'   => [ 'required' => true, 'type' => 'string' ],
					'cursor' => [ 'required' => false, 'type' => 'integer', 'default' => 0 ],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/report/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'report' ],
				'permission_callback' => $perm,
				'args'                => [
					'lang' => [ 'required' => false, 'type' => 'string', 'default' => 'ko' ],
				],
			]
		);
	}

	public static function create_run( WP_REST_Request $request ): WP_REST_Response {
		$out = WPER_Checklist_Runner::create_run();

		if ( isset( $out['error'] ) ) {
			return new WP_REST_Response( $out, 500 );
		}

		return new WP_REST_Response( $out, 200 );
	}

	public static function run_step( WP_REST_Request $request ): WP_REST_Response {
		$run_id  = (int) $request->get_param( 'run_id' );
		$step_id = sanitize_text_field( (string) $request->get_param( 'step' ) );
		$cursor  = (int) $request->get_param( 'cursor' );

		$post = get_post( $run_id );
		if ( ! $post || WPER_Checklist_Store::CPT !== $post->post_type ) {
			return new WP_REST_Response( [ 'error' => '진단 레코드 없음' ], 404 );
		}

		$out = WPER_Checklist_Runner::run_step( $run_id, $step_id, $cursor );

		return new WP_REST_Response( $out, isset( $out['error'] ) ? 400 : 200 );
	}

	public static function report( WP_REST_Request $request ): WP_REST_Response {
		$run_id = (int) $request->get_param( 'id' );
		$lang   = sanitize_key( (string) $request->get_param( 'lang' ) );

		$post = get_post( $run_id );
		if ( ! $post || WPER_Checklist_Store::CPT !== $post->post_type ) {
			return new WP_REST_Response( [ 'error' => '진단 레코드 없음' ], 404 );
		}

		$results = WPER_Checklist_Store::results( $run_id );

		return new WP_REST_Response(
			[
				'html'   => WPER_Checklist_Report::render_html( $run_id, $lang ),
				'scores' => WPER_Checklist_Runner::score( $results['items'] ?? [] ),
			],
			200
		);
	}
}

add_action( 'rest_api_init', [ 'WPER_Checklist_REST', 'register_routes' ] );
