<?php
/**
 * 프론트 JS 라이브러리 취약 범위 — 번들 정적 테이블.
 *
 * Lighthouse 의 "알려진 취약점이 있는 프론트엔드 라이브러리" 감사와 같은 접근이다.
 * wpvulnerability API 는 JS 라이브러리를 다루지 않으므로 이 표가 오프라인 출처다.
 * detect 는 script src 에 대한 정규식, vulns[].below 는 수정 버전(그 미만이 취약).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'jquery'        => [
		'detect' => '/\/jquery(?:[.-]\d[\d.]*)?(?:\.min|\.slim)*\.js/i',
		'vulns'  => [
			[ 'below' => '3.5.0', 'cve' => 'CVE-2020-11022/11023' ], // htmlPrefilter XSS.
			[ 'below' => '3.4.0', 'cve' => 'CVE-2019-11358' ],       // $.extend 프로토타입 오염.
			[ 'below' => '3.0.0', 'cve' => 'CVE-2015-9251' ],        // 크로스도메인 ajax XSS.
		],
	],
	'jquery-migrate' => [
		'detect' => '/\/jquery-migrate(?:[.-]\d[\d.]*)?(?:\.min)?\.js/i',
		'vulns'  => [
			[ 'below' => '1.2.0', 'cve' => 'CVE-2013-4383 계열' ],
		],
	],
	'bootstrap'     => [
		'detect' => '/\/bootstrap(?:\.bundle)?(?:[.-]\d[\d.]*)?(?:\.min)?\.js/i',
		'vulns'  => [
			[ 'since' => '4.0.0', 'below' => '4.3.1', 'cve' => 'CVE-2019-8331' ], // tooltip XSS.
			[ 'below' => '3.4.1', 'cve' => 'CVE-2019-8331' ],
		],
	],
	'lodash'        => [
		'detect' => '/\/lodash(?:[.-]\d[\d.]*)?(?:\.min)?\.js/i',
		'vulns'  => [
			[ 'below' => '4.17.21', 'cve' => 'CVE-2021-23337' ], // 명령 주입 · 프로토타입 오염.
		],
	],
	'moment'        => [
		'detect' => '/\/moment(?:[.-]\d[\d.]*)?(?:\.min)?\.js/i',
		'vulns'  => [
			[ 'below' => '2.29.4', 'cve' => 'CVE-2022-31129' ], // ReDoS.
		],
	],
	'vue'           => [
		'detect' => '/\/vue(?:[.-]\d[\d.]*)?(?:\.min|\.global|\.runtime)*\.js/i',
		'vulns'  => [
			[ 'since' => '2.0.0', 'below' => '2.7.16', 'cve' => 'CVE-2024-6783 계열' ],
		],
	],
	'underscore'    => [
		'detect' => '/\/underscore(?:[.-]\d[\d.]*)?(?:\.min)?\.js/i',
		'vulns'  => [
			[ 'since' => '1.3.2', 'below' => '1.13.0-2', 'cve' => 'CVE-2021-23358' ], // template 주입.
		],
	],
];
