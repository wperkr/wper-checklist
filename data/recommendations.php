<?php
/**
 * 규칙 기반 권장 조치 — 항목 키 → [ 원인, 해결 ].
 *
 * fail/warn 항목에만 표출된다. AI 생성이 아니라 사전 작성 문안 — 같은 진단은
 * 언제나 같은 조치를 말한다 (재현 가능성이 신뢰의 근거다).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	/* ---------------------------------------------------------- ① 응답 속도 */
	'latency:home'       => [
		'cause' => '랜딩이 요청마다 PHP·DB 를 처음부터 실행하고 있을 가능성이 큽니다 — 페이지 캐시(nginx FastCGI 등)가 없거나 미스율이 높습니다.',
		'fix'   => '서버 계층 페이지 캐시(nginx FastCGI 캐시)를 켜고, 오브젝트 캐시(Redis)와 OPcache 를 함께 구성하세요. 캐시 플러그인 중첩은 피합니다.',
	],
	'latency:archive'    => [
		'cause' => '아카이브는 목록 쿼리가 무거워 캐시 없이는 느려지기 쉽습니다.',
		'fix'   => '페이지 캐시 적용 범위에 아카이브를 포함하고, 목록 쿼리에 인덱스가 타는지 확인하세요.',
	],
	'latency:single'     => [
		'cause' => '글 페이지 렌더링에 캐시가 적용되지 않거나 무거운 관련 글·위젯 쿼리가 있습니다.',
		'fix'   => '페이지 캐시를 적용하고, 관련 글 플러그인·사이드바 위젯의 쿼리 비용을 점검하세요.',
	],
	'latency:page'       => [
		'cause' => '고정 페이지가 캐시 없이 매 요청 렌더링되고 있습니다.',
		'fix'   => '페이지 캐시 적용 범위를 확인하세요.',
	],
	'latency:product'    => [
		'cause' => '상품·서비스 페이지는 동적 요소가 많아 캐시 예외가 되기 쉽습니다.',
		'fix'   => '장바구니 등 개인화 요소만 예외로 두고 나머지는 캐시하세요.',
	],
	'latency:stability'  => [
		'cause' => '측정 편차가 큽니다 — 서버 부하 변동, 캐시 미스 혼입, 또는 리소스 경합이 의심됩니다.',
		'fix'   => '트래픽이 적은 시간대에 재측정하고, 편차가 유지되면 서버 리소스(CPU·메모리)와 동거 서비스 부하를 점검하세요.',
	],
	'latency:size'       => [
		'cause' => 'HTML 페이로드가 큽니다 — 인라인 CSS/JS 과다, 페이지 빌더 마크업 비대가 흔한 원인입니다.',
		'fix'   => '불필요한 인라인 자산을 외부 파일로 분리하고 gzip/brotli 압축을 확인하세요.',
	],
	'latency:redirects'  => [
		'cause' => '리다이렉트 홉마다 왕복이 하나씩 추가되어 체감 지연이 커집니다.',
		'fix'   => '내부 링크와 canonical 주소를 최종 URL 로 통일하세요 (www/비www · 슬래시 유무).',
	],

	/* ------------------------------------------------------------ ② DB 쿼리 */
	'db:home'            => [
		'cause' => '랜딩 한 화면에 쿼리가 과다합니다 — 위젯·메뉴·옵션 조회가 캐시 없이 반복되고 있을 가능성이 큽니다.',
		'fix'   => 'Redis 오브젝트 캐시를 도입하고, Query Monitor 로 다발 지점을 찾아 줄이세요.',
	],
	'db:single'          => [
		'cause' => '글 페이지 쿼리가 많습니다 — 관련 글·조회수·댓글 플러그인이 흔한 원인입니다.',
		'fix'   => '플러그인별 쿼리 기여도를 확인하고 캐시 가능한 조회는 트랜션트로 감싸세요.',
	],
	'db:archive'         => [
		'cause' => '아카이브 목록에서 글마다 추가 쿼리가 실행되고 있을 수 있습니다 (N+1).',
		'fix'   => 'update_post_meta_cache·update_post_term_cache 활성 여부와 루프 내 개별 조회를 점검하세요.',
	],
	'db:time'            => [
		'cause' => 'DB 응답 시간이 깁니다 — 느린 쿼리 또는 DB 서버 자체의 지연입니다.',
		'fix'   => '슬로 쿼리 로그를 켜 원인 쿼리를 특정하고, 인덱스·버퍼 풀 크기를 조정하세요.',
	],
	'db:slow'            => [
		'cause' => '20ms 를 넘는 쿼리가 있습니다 — 인덱스 미스나 대형 테이블 풀스캔이 의심됩니다.',
		'fix'   => 'EXPLAIN 으로 실행 계획을 확인하고 필요한 인덱스를 추가하세요.',
	],
	'db:dupes'           => [
		'cause' => '같은 형상의 쿼리가 한 요청에서 반복됩니다 — 루프 안에서 개별 조회하는 코드(N+1)가 원인입니다.',
		'fix'   => '반복 조회를 일괄 조회로 바꾸거나 오브젝트 캐시로 흡수하세요.',
	],
	'db:objcache'        => [
		'cause' => '외부 오브젝트 캐시가 없어 반복 쿼리가 매번 DB 로 갑니다.',
		'fix'   => 'Redis 서버를 두고 redis-cache 플러그인으로 드롭인을 활성화하세요 (wp redis enable).',
	],

	/* --------------------------------------------------------------- ③ SEO */
	'seo:title'          => [
		'cause' => '문서 제목이 없거나 권장 길이(10–60자)를 벗어나 검색 결과에서 잘리거나 무시됩니다.',
		'fix'   => 'SEO 플러그인(Rank Math 등)에서 페이지 제목 템플릿을 설정하세요.',
	],
	'seo:description'    => [
		'cause' => '메타 설명이 없으면 검색엔진이 본문에서 임의 발췌합니다 — 클릭률을 통제할 수 없습니다.',
		'fix'   => '핵심 페이지부터 50–160자 요약을 직접 작성하세요.',
	],
	'seo:h1'             => [
		'cause' => 'H1 이 없거나 여러 개면 페이지 주제 신호가 흐려집니다.',
		'fix'   => '페이지당 H1 하나로 정리하고 나머지는 H2 이하로 내리세요.',
	],
	'seo:indexable'      => [
		'cause' => '랜딩이 noindex 또는 robots.txt 로 차단되어 검색에 아예 노출되지 않습니다.',
		'fix'   => '의도한 차단이 아니라면 설정 › 읽기의 검색엔진 차단 옵션과 SEO 플러그인 robots 설정을 확인하세요.',
	],
	'seo:canonical'      => [
		'cause' => 'canonical 이 없거나 다른 주소를 가리키면 중복 콘텐츠 판정과 권한 분산이 생깁니다.',
		'fix'   => 'SEO 플러그인이 자기 자신을 가리키는 canonical 을 출력하는지 확인하세요.',
	],
	'seo:lang'           => [
		'cause' => 'html lang 이 없으면 검색엔진·스크린리더가 언어를 추측해야 합니다.',
		'fix'   => '테마의 <html <?php language_attributes(); ?>> 출력을 확인하세요.',
	],
	'seo:viewport'       => [
		'cause' => 'viewport 메타가 없으면 모바일에서 데스크톱 레이아웃이 축소 렌더링됩니다 — 모바일 친화 판정 탈락.',
		'fix'   => '테마 head 에 표준 viewport 메타를 추가하세요.',
	],
	'seo:alt'            => [
		'cause' => 'alt 없는 이미지는 이미지 검색에서 제외되고 접근성 점수도 깎입니다.',
		'fix'   => '미디어 라이브러리에서 의미 있는 이미지부터 대체 텍스트를 채우세요. 장식 이미지는 빈 alt="" 가 정답입니다.',
	],
	'seo:linktext'       => [
		'cause' => '텍스트 없는 링크는 크롤러와 스크린리더에게 목적지를 설명하지 못합니다.',
		'fix'   => '아이콘 링크에 aria-label 을 붙이거나 시각적으로 숨긴 텍스트를 넣으세요.',
	],
	'seo:jsonld'         => [
		'cause' => '구조화 데이터가 없으면 리치 결과(FAQ·별점·브레드크럼) 대상에서 제외됩니다.',
		'fix'   => 'SEO 플러그인의 스키마 기능을 켜고 Article·FAQPage·BreadcrumbList 를 출력하세요.',
	],
	'seo:hreflang'       => [
		'cause' => '다국어 페이지가 있는데 hreflang 이 없으면 언어별 잘못된 판이 노출될 수 있습니다.',
		'fix'   => '단일 언어 사이트면 조치가 필요 없습니다. 다국어라면 언어판 상호 참조 + x-default 를 선언하세요.',
	],
	'seo:og'             => [
		'cause' => 'OG 메타가 없으면 SNS 공유 시 제목·이미지가 임의로 잡힙니다.',
		'fix'   => 'SEO 플러그인의 소셜 메타 출력을 켜고 대표 이미지를 지정하세요.',
	],
	'seo:https'          => [
		'cause' => 'http 사이트거나 https 페이지가 http 자산을 참조하면 브라우저 경고와 순위 불이익이 있습니다.',
		'fix'   => 'TLS 인증서를 적용하고 DB 의 http:// 참조를 일괄 치환하세요 (wp search-replace).',
	],
	'seo:sitemap'        => [
		'cause' => '사이트맵이 없거나 robots.txt 에 명시되지 않아 새 글 발견이 느립니다.',
		'fix'   => 'SEO 플러그인 사이트맵을 활성화하고 robots.txt 에 Sitemap: 줄을 추가한 뒤 서치콘솔에 제출하세요.',
	],

	/* ----------------------------------------------------------- ④ 취약점 */
	'vuln:core'          => [
		'cause' => '현재 코어 버전에 공개된 취약점이 있습니다 — 공개 취약점은 자동화 스캐너의 최우선 표적입니다.',
		'fix'   => '스테이징에서 검증 후 즉시 코어를 업데이트하세요. 업데이트 전까지는 WAF 규칙으로 완화합니다.',
	],
	'vuln:core-latest'   => [
		'cause' => '코어가 구버전입니다 — 버전이 공개되어 있어 알려진 결함의 표적이 됩니다.',
		'fix'   => '백업 후 최신 버전으로 업데이트하세요.',
	],
	'vuln:plugins'       => [
		'cause' => '알려진 취약점이 있는 플러그인이 활성 상태입니다 — 워드프레스 침해의 최다 진입로입니다.',
		'fix'   => '해당 플러그인을 즉시 업데이트하고, 수정판이 없으면 비활성화 후 대체제를 찾으세요.',
	],
	'vuln:plugin-updates' => [
		'cause' => '업데이트가 밀린 플러그인은 이미 공개된 수정 내역이 곧 공격 설명서가 됩니다.',
		'fix'   => '스테이징 검증 → 운영 반영의 주기적 업데이트 루틴을 만드세요.',
	],
	'vuln:themes'        => [
		'cause' => '테마에 알려진 취약점이 있습니다.',
		'fix'   => '테마를 업데이트하거나, 방치된 테마라면 유지보수되는 테마로 교체를 검토하세요.',
	],
	'vuln:theme-updates' => [
		'cause' => '테마 업데이트가 밀려 있습니다.',
		'fix'   => '자식 테마로 커스텀을 분리해 두면 부모 테마를 안전하게 업데이트할 수 있습니다.',
	],
	'vuln:js'            => [
		'cause' => '알려진 취약점이 있는 프론트 JS 라이브러리가 로드되고 있습니다 (XSS 등).',
		'fix'   => '해당 라이브러리를 로드하는 테마·플러그인을 업데이트하세요. 코어 번들 jQuery 는 코어 업데이트가 해결합니다.',
	],
	'vuln:author'        => [
		'cause' => '?author=N 리다이렉트로 로그인 아이디가 수집됩니다 — 무차별 대입의 절반(아이디)을 공짜로 주는 셈입니다.',
		'fix'   => '웹서버 규칙이나 보안 설정으로 author 스캔 리다이렉트를 차단하세요.',
	],
	'vuln:xmlrpc'        => [
		'cause' => 'xmlrpc.php 는 인증 시도 증폭(system.multicall)과 pingback DDoS 의 통로입니다.',
		'fix'   => '사용하는 앱이 없다면 웹서버에서 xmlrpc.php 를 차단하세요.',
	],
	'vuln:users'         => [
		'cause' => 'REST 사용자 엔드포인트가 비인증에 열려 로그인 아이디가 열거됩니다.',
		'fix'   => '웹서버에서 비인증 /wp-json/wp/v2/users 를 차단하거나 인증 요구로 제한하세요.',
	],

	/* --------------------------------------------------------- ⑤ 서버 설정 */
	'server:php-version' => [
		'cause' => '보안 지원이 끝난(또는 임박한) PHP 는 새 취약점의 수정판을 받지 못합니다.',
		'fix'   => '지원 중인 PHP 브랜치로 업그레이드하세요. 호환성은 스테이징에서 먼저 검증합니다.',
	],
	'server:expose-php'  => [
		'cause' => 'X-Powered-By 로 PHP 버전이 노출되어 버전별 취약점 표적이 됩니다.',
		'fix'   => 'php.ini 에서 expose_php = Off 로 설정하세요.',
	],
	'server:disable-functions' => [
		'cause' => '셸 실행 함수가 열려 있으면 코드 주입 한 번이 곧 서버 장악으로 이어집니다.',
		'fix'   => 'php.ini disable_functions 에 exec, shell_exec, system, passthru, proc_open, popen 을 추가하세요.',
	],
	'server:curl-alive'  => [
		'cause' => 'curl_exec 가 차단되면 워드프레스의 외부 통신(코어 업데이트 · 플러그인 설치 · 외부 API)이 전부 죽습니다 — "위험 함수 목록" 복붙의 대표 사고입니다.',
		'fix'   => 'disable_functions 에서 curl_exec 를 제거하세요.',
	],
	'server:url-fopen'   => [
		'cause' => 'allow_url_fopen 이 켜져 있으면 파일 함수가 원격 URL 을 열 수 있어 원격 포함 공격면이 넓어집니다.',
		'fix'   => 'php.ini 에서 allow_url_fopen = Off. 워드프레스는 cURL 로 통신하므로 영향이 없습니다.',
	],
	'server:display-errors' => [
		'cause' => '오류 메시지가 방문자에게 노출되면 경로·쿼리 등 내부 구조가 새어 나갑니다.',
		'fix'   => 'display_errors = Off, 대신 log_errors 로 파일에만 남기세요.',
	],
	'server:open-basedir' => [
		'cause' => '경로 제한이 없으면 침해된 PHP 가 서버 전체 파일에 접근할 수 있습니다.',
		'fix'   => 'open_basedir 를 문서 루트 + 임시 디렉터리로 한정하세요.',
	],
	'server:wp-debug'    => [
		'cause' => '운영 환경의 WP_DEBUG 는 오류를 방문자에게 노출할 수 있습니다.',
		'fix'   => 'WP_DEBUG false, 로그가 필요하면 WP_DEBUG_LOG 만 켜고 WP_DEBUG_DISPLAY 는 항상 false.',
	],
	'server:file-edit'   => [
		'cause' => '관리자 화면의 테마·플러그인 편집기는 관리자 계정 탈취 시 웹셸 설치의 최단 경로입니다.',
		'fix'   => "wp-config.php 에 define( 'DISALLOW_FILE_EDIT', true ) 를 추가하세요.",
	],
	'server:db-charset'  => [
		'cause' => 'utf8(3바이트) 은 이모지·일부 한자에서 무음 데이터 손실을 일으킵니다.',
		'fix'   => 'DB_CHARSET 을 utf8mb4 로 바꾸고 기존 테이블을 변환하세요 (백업 필수).',
	],
	'server:sec-headers' => [
		'cause' => '보안 헤더가 없으면 MIME 스니핑 · 클릭재킹 · 정보 유출 방어가 브라우저 기본값에만 의존합니다.',
		'fix'   => '웹서버에 X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy, (https 라면) HSTS 를 추가하세요.',
	],
	'server:tokens'      => [
		'cause' => '서버 소프트웨어 버전이 노출되면 버전별 알려진 취약점의 표적이 됩니다.',
		'fix'   => 'nginx 는 server_tokens off, PHP 는 expose_php Off 로 버전 표기를 지우세요.',
	],
	'server:https'       => [
		'cause' => 'http 접속이 https 로 강제되지 않으면 세션 쿠키가 평문으로 노출될 수 있습니다.',
		'fix'   => "웹서버에서 http→https 301 리다이렉트를 걸고 wp-config.php 에 define( 'FORCE_SSL_ADMIN', true ) 를 추가하세요.",
	],
	'server:files'       => [
		'cause' => '설정 파일·저장소 메타데이터·업로드 디렉터리의 PHP 실행이 웹에서 접근 가능하면 정보 유출과 원격 실행으로 직결됩니다.',
		'fix'   => '웹서버에서 wp-config.php · .git · *.sql 접근을 차단하고 uploads 디렉터리의 PHP 실행을 금지하세요.',
	],
	'server:db-vars'     => [
		'cause' => 'DB 서버가 구버전이거나 권장 설정(utf8mb4 · local_infile Off · 슬로 쿼리 로그)에서 벗어나 있습니다.',
		'fix'   => '지원 중인 MariaDB/MySQL 로 올리고 my.cnf 에서 character-set-server=utf8mb4, local_infile=0, slow_query_log=1 을 설정하세요.',
	],
];
