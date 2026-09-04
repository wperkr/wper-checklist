<?php
/**
 * 자체 완결 번역 — "[번역 적용]" 토글의 실체.
 *
 * ⭐ 카탈로그 키는 **한국어 원문 그대로**다. 구조를 복제한 언어별 파일을 만들면
 *    원문을 고칠 때마다 두 파일이 어긋나고 어긋난 사실을 아무도 모른다 — 원문이
 *    키면 고친 그 문장만 한국어로 떨어져서 무엇이 낡았는지 바로 보인다.
 *
 * ⚠ 독립 플러그인이다. wper-core 의 wper_t()/WPER_I18N 을 호출하지 않는다 —
 *   배포 대상 사이트에는 그 플러그인이 없다. 규약(원문 키)만 동일하게 따른다.
 * ⚠ gettext(.po/.mo)를 쓰지 않는 이유: 언어가 ko→en 하나뿐이고, 보고서 언어는
 *   사이트 로케일이 아니라 **요청 파라미터**가 정한다 — 관리자가 한국어 워드프레스
 *   에서 영어 보고서를 뽑아 해외 고객에게 전달하는 흐름이 기본 사용례다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPER_Checklist_I18N {

	/** @var string 현재 요청의 보고서 언어. */
	private static $lang = 'ko';

	/** @var array<string,string>|null 로드된 카탈로그. */
	private static $catalog = null;

	/**
	 * 허용 언어만 받는다 — 그 외 값은 조용히 ko 로.
	 */
	public static function set_lang( string $lang ): void {
		self::$lang = in_array( $lang, [ 'ko', 'en' ], true ) ? $lang : 'ko';
	}

	public static function get_lang(): string {
		return self::$lang;
	}

	/**
	 * 번역. ko 는 항등이고, en 은 카탈로그 조회 실패 시 원문 유지 —
	 * 번역이 빠진 문장은 한국어로 노출되어 스스로 신고한다.
	 */
	public static function t( string $ko ): string {
		if ( 'ko' === self::$lang ) {
			return $ko;
		}

		if ( null === self::$catalog ) {
			$file          = WPER_CHECKLIST_PATH . 'languages/en.php';
			self::$catalog = file_exists( $file ) ? (array) include $file : [];
		}

		$key = trim( $ko );

		if ( isset( self::$catalog[ $key ] ) ) {
			// 원문의 앞뒤 공백은 보존한다 (키는 trim 비교).
			return str_replace( $key, self::$catalog[ $key ], $ko );
		}

		return $ko;
	}

	/**
	 * 합성 문자열(측정치) 번역 — "184ms (3회 중앙값)" 처럼 숫자와 섞인 문장은
	 * 통짜 키 조회가 불가능하므로, 카탈로그의 **구문 조각**을 긴 것부터 치환한다.
	 * 번역이 없는 조각은 한국어로 남아 스스로 신고한다 (통짜 키와 같은 원리).
	 */
	public static function t_mixed( string $text ): string {
		if ( 'ko' === self::$lang || '' === $text ) {
			return $text;
		}

		self::t( '' ); // 카탈로그 로드 보장.

		static $sorted = null;
		if ( null === $sorted ) {
			$sorted = self::$catalog;
			uksort( $sorted, static fn( $a, $b ) => mb_strlen( $b ) <=> mb_strlen( $a ) );
		}

		foreach ( $sorted as $ko => $en ) {
			if ( str_contains( $text, $ko ) ) {
				$text = str_replace( $ko, $en, $text );
			}
		}

		return $text;
	}
}

/**
 * 템플릿 단축.
 */
function wper_checklist_t( string $ko ): string {
	return WPER_Checklist_I18N::t( $ko );
}

/**
 * echo + esc_html 단축.
 */
function wper_checklist_e( string $ko ): void {
	echo esc_html( WPER_Checklist_I18N::t( $ko ) );
}
