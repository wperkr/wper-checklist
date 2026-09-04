/**
 * WPER 진단 — 관리자 화면 구동부.
 *
 * 이 저장소 최초의 JS 다 — wper 프론트엔드의 "JS 0줄" 원칙은 방문자 화면 이야기고,
 * 관리자 화면의 인터랙티브 진행 표시는 그 원칙과 무관하다. 규약은 유지한다:
 * JS 훅은 js- 접두 클래스만 잡고, 스타일 클래스(wper-*)는 절대 셀렉터로 쓰지 않는다.
 *
 * 스텝은 **순차** 실행 — 루프백 프로브가 자기 서버의 FPM 워커를 쓰므로 병렬로
 * 쏘면 워커 풀이 작은 서버에서 데드락이 난다.
 */
(function () {
	'use strict';

	var cfg = window.wperChecklist || {};
	var stage = document.getElementById('wper-check-stage');
	if (!stage || !cfg.root) {
		return;
	}

	var CAT_LABELS = { latency: '응답 속도', db: 'DB 쿼리', seo: 'SEO', vuln: 'WP 취약점', server: '서버 설정' };

	function api(path, opts) {
		opts = opts || {};
		return fetch(cfg.root + path, {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': cfg.nonce,
				'Content-Type': 'application/json'
			},
			body: opts.body ? JSON.stringify(opts.body) : undefined
		}).then(function (res) {
			if (!res.ok) {
				throw new Error('HTTP ' + res.status);
			}
			return res.json();
		});
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) { node.className = className; }
		if (text) { node.textContent = text; }
		return node;
	}

	/* ------------------------------------------------------------ 진행 패널 */

	function buildProgress(manifest) {
		var wrap = el('div', 'wper-check-progress');

		var bar = el('div', 'wper-check-progress__bar');
		bar.appendChild(el('span', 'wper-check-progress__dot'));
		bar.appendChild(el('span', 'wper-check-progress__title', cfg.i18n.running));
		var live = el('span', 'wper-check-progress__live', '0 / ' + manifest.length);
		bar.appendChild(live);
		wrap.appendChild(bar);

		var cats = el('div', 'wper-check-progress__cats');
		var meters = {};
		var totals = {};
		var done = {};

		manifest.forEach(function (step) {
			totals[step.cat] = (totals[step.cat] || 0) + 1;
			done[step.cat] = 0;
		});

		Object.keys(CAT_LABELS).forEach(function (cat) {
			if (!totals[cat]) { return; }
			var row = el('div', 'wper-check-progress__cat');
			row.appendChild(el('span', 'wper-check-progress__cat-name', CAT_LABELS[cat]));
			var meter = el('div', 'wper-check-meter');
			var track = el('div', 'wper-check-meter__track');
			var fill = el('div', 'wper-check-meter__bar');
			track.appendChild(fill);
			meter.appendChild(track);
			row.appendChild(meter);
			var count = el('span', 'wper-check-progress__cat-count', '0/' + totals[cat]);
			row.appendChild(count);
			cats.appendChild(row);
			meters[cat] = { fill: fill, count: count };
		});
		wrap.appendChild(cats);

		var log = el('ul', 'wper-check-progress__events');
		wrap.appendChild(log);

		return {
			node: wrap,
			event: function (text, cat) {
				var li = el('li', 'wper-check-progress__event', text);
				if (cat) { li.dataset.cat = cat; }
				log.appendChild(li);
				while (log.children.length > 60) { log.removeChild(log.firstChild); }
				log.scrollTop = log.scrollHeight;
			},
			stepDone: function (cat, progress) {
				if (meters[cat]) {
					done[cat] += 1;
					meters[cat].fill.style.inlineSize = Math.round(done[cat] / totals[cat] * 100) + '%';
					meters[cat].count.textContent = done[cat] + '/' + totals[cat];
				}
				if (progress) { live.textContent = progress.done + ' / ' + progress.total; }
			},
			finish: function () {
				bar.classList.add('is-complete');
				bar.querySelector('.wper-check-progress__title').textContent = cfg.i18n.complete;
			}
		};
	}

	/* -------------------------------------------------------------- 스캔 루프 */

	function runScan() {
		stage.innerHTML = '';
		stage.appendChild(el('p', 'wper-check-progress__preparing', cfg.i18n.preparing));

		api('/run', { method: 'POST' }).then(function (created) {
			var manifest = created.manifest;
			var panel = buildProgress(manifest);
			stage.innerHTML = '';
			stage.appendChild(panel.node);

			var index = 0;

			function nextStep() {
				if (index >= manifest.length) {
					panel.finish();
					showReport(created.run_id, 'ko', true);
					return;
				}
				var step = manifest[index];
				runStep(created.run_id, step, 0, panel).then(function () {
					index += 1;
					nextStep();
				}).catch(fail);
			}

			nextStep();
		}).catch(fail);
	}

	function runStep(runId, step, cursor, panel) {
		return api('/step', { method: 'POST', body: { run_id: runId, step: step.step, cursor: cursor } })
			.then(function (out) {
				if (out.error) { throw new Error(out.error); }
				(out.events || []).forEach(function (text) { panel.event(text, step.cat); });
				if (!out.done) {
					return runStep(runId, step, out.cursor || cursor + 1, panel);
				}
				panel.stepDone(step.cat, out.progress);
				return out;
			});
	}

	/* ---------------------------------------------------------------- 보고서 */

	function showReport(runId, lang, fresh) {
		var loading = el('p', 'wper-check-progress__preparing', cfg.i18n.loading);
		stage.appendChild(loading);

		api('/report/' + runId + '?lang=' + lang).then(function (out) {
			stage.innerHTML = '';

			var holder = el('div', 'wper-check-holder');
			holder.innerHTML = out.html; // 서버 렌더 HTML — 서버측에서 전량 이스케이프됨.
			stage.appendChild(holder);

			// 툴바 — 상세 보고서 토글 + 언어 전환.
			var toolbar = el('div', 'wper-check-toolbar');
			var recoBtn = el('button', 'wper-check-toolbar__reco js-check-reco', cfg.i18n.reco);
			recoBtn.type = 'button';
			toolbar.appendChild(recoBtn);

			var langWrap = el('span', 'wper-check-toolbar__lang');
			['ko', 'en'].forEach(function (code) {
				var b = el('button', 'wper-check-toolbar__lang-btn js-check-lang' + (code === lang ? ' is-active' : ''), code.toUpperCase());
				b.type = 'button';
				b.dataset.lang = code;
				b.dataset.run = runId;
				langWrap.appendChild(b);
			});
			toolbar.appendChild(langWrap);

			var head = holder.querySelector('.wper-check-cats');
			if (head && head.parentNode) {
				head.parentNode.insertBefore(toolbar, head.nextSibling);
			}

			// 상세는 접힌 채 시작 — "[WPER Recommendation (번역 적용)]" 클릭으로 펼친다.
			var detail = holder.querySelectorAll('.wper-check-report, .wper-check-recos, .wper-check-skips');
			var open = !fresh;
			detail.forEach(function (sec) { sec.hidden = !open; });
			recoBtn.setAttribute('aria-expanded', String(open));

			recoBtn.addEventListener('click', function () {
				open = !open;
				detail.forEach(function (sec) { sec.hidden = !open; });
				recoBtn.setAttribute('aria-expanded', String(open));
			});
		}).catch(fail);
	}

	function fail(err) {
		stage.innerHTML = '';
		var box = el('div', 'wper-check-error');
		box.appendChild(el('p', '', cfg.i18n.error));
		box.appendChild(el('p', 'wper-check-error__detail', String(err && err.message || err)));
		stage.appendChild(box);
	}

	/* ---------------------------------------------------------------- 배선 */

	document.addEventListener('click', function (event) {
		var start = event.target.closest('.js-check-start');
		if (start) {
			runScan();
			return;
		}

		var open = event.target.closest('.js-check-open');
		if (open) {
			showReport(parseInt(open.dataset.run, 10), 'ko', false);
			return;
		}

		var langBtn = event.target.closest('.js-check-lang');
		if (langBtn && !langBtn.classList.contains('is-active')) {
			showReport(parseInt(langBtn.dataset.run, 10), langBtn.dataset.lang, false);
		}
	});
})();
