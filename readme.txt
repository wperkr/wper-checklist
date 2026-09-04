=== WPER Checklist ===
Contributors: wper
Tags: health check, performance, seo, security, diagnostics
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.html

워드프레스 사이트 건강 진단 — 응답 속도 · DB 쿼리 · SEO · 취약점 · 서버 설정을 검사해 1000점 만점 리포트를 만듭니다.

== Description ==

관리자 메뉴 **WPER › 사이트 진단** 에서 버튼 하나로 5개 영역을 검사합니다. (wper 계열 플러그인은 공용 WPER 대메뉴 아래에 모입니다)

* **응답 속도** — 랜딩 · 아카이브 · 글 · 고정 페이지를 3회 측정해 중앙값으로 판정 (단발 수치를 쓰지 않습니다)
* **DB 쿼리** — 비캐시 경로의 쿼리 수 · DB 시간 · 슬로/중복 쿼리 · 오브젝트 캐시
* **SEO** — Lighthouse SEO 감사에 준하는 정적 HTML 분석 (title · description · canonical · 구조화 데이터 · 사이트맵 등 14항목)
* **WP 취약점** — 코어 · 플러그인 · 테마의 알려진 CVE(wpvulnerability.net) + 프론트 JS 라이브러리 + 노출면 프로브
* **서버 설정** — PHP · wp-config · 보안 헤더 · 민감 경로 · DB 변수

총점 1000점. **측정하지 못한 항목은 "확인 불가" 로 표기하고 점수 분모에서 제외합니다** — 측정하지 않은 것을 통과나 실패로 표기하지 않습니다.

**[WPER Recommendation (번역 적용)]** 버튼으로 실패 항목마다 원인·해결 문안이 담긴 상세 보고서를 열 수 있고, 보고서는 한국어/영어로 전환됩니다.

WP-CLI: `wp wper checklist run [--format=json]`, `wp wper checklist history`

== 요구 사항 ==

* PHP 8.1 이상
* 진단은 자기 사이트로의 루프백 HTTP 요청을 사용합니다 — **PHP-FPM 워커가 2개 이상**이어야 합니다 (`pm.max_children >= 2`). 워커가 1개면 진단 요청이 자기 자신을 기다리다 타임아웃됩니다
* nginx FastCGI 캐시를 쓰는 경우 `wper-checklist/v1` REST 경로를 캐시 우회 목록에 넣어 주세요
* 취약점 조회는 wpvulnerability.net 무료 API 를 사용합니다 (키 불요). 외부 통신이 차단된 환경에서는 해당 항목이 "확인 불가" 로 표기됩니다

== 개인정보 ==

* 진단 결과는 사이트 DB 에만 저장됩니다 (최근 20건)
* 외부로 전송되는 것은 wpvulnerability.net 에 대한 슬러그·버전 조회뿐입니다
* DB 캡처는 쿼리 **개수와 시간만** 응답 헤더로 전달하며 SQL 원문은 네트워크로 내보내지 않습니다

== Changelog ==

= 1.0.0 =
* 최초 릴리스 — 5개 영역 · 1000점 진단 · ko/en 보고서
