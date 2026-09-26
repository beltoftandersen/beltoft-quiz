/**
 * Beltoft Quiz — front-end player. No dependencies.
 */
(function () {
	'use strict';

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		attrs = attrs || {};
		Object.keys(attrs).forEach(function (k) {
			if (k === 'text') { node.textContent = attrs[k]; }
			else if (k === 'html') { node.innerHTML = attrs[k]; }
			else if (k === 'class') { node.className = attrs[k]; }
			else if (k.indexOf('on') === 0) { node.addEventListener(k.slice(2), attrs[k]); }
			else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) { node.setAttribute(k, attrs[k] === true ? '' : attrs[k]); }
		});
		(children || []).forEach(function (c) { if (c) { node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
		return node;
	}

	function sprintf(str) {
		var args = Array.prototype.slice.call(arguments, 1), i = 0;
		return String(str).replace(/%(\d+\$)?s/g, function (m, pos) { return pos ? args[parseInt(pos, 10) - 1] : args[i++]; }).replace(/%%/g, '%');
	}

	// Seeded PRNG so the server can reproduce the shuffle from the token seed.
	function mulberry32(seedHex) {
		var a = parseInt(String(seedHex).slice(0, 8), 16) >>> 0;
		return function () {
			a |= 0; a = a + 0x6D2B79F5 | 0;
			var t = Math.imul(a ^ a >>> 15, 1 | a);
			t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
			return ((t ^ t >>> 14) >>> 0) / 4294967296;
		};
	}
	function shuffle(arr, rnd) {
		var a = arr.slice();
		for (var i = a.length - 1; i > 0; i--) { var j = Math.floor(rnd() * (i + 1)); var t = a[i]; a[i] = a[j]; a[j] = t; }
		return a;
	}
	function formatTime(s) {
		var m = Math.floor(s / 60), r = s % 60;
		return m + ':' + (r < 10 ? '0' : '') + r;
	}

	function Quiz(root, data) {
		this.root = root;
		this.data = data;
		this.cfg = data.config;
		this.i18n = data.i18n;
		this.labels = this.cfg.settings.labels;
		this.reset();
		this.renderStart();
	}

	Quiz.prototype.reset = function () {
		this.token = null;
		this.startedAt = 0;
		this.questions = this.cfg.questions.slice();
		this.answers = {};
		this.index = 0;
		this.remaining = parseInt(this.cfg.settings.timer, 10) || 0;
		this.stopTimer();
	};

	Quiz.prototype.mount = function (screen) {
		var old = this.root.querySelector('.bgq-screen');
		if (old) { old.remove(); }
		this.root.appendChild(screen);
		var focus = screen.querySelector('[data-bgq-focus]') || screen.querySelector('h2, h3');
		if (focus) { focus.setAttribute('tabindex', '-1'); focus.focus({ preventScroll: false }); }
	};

	Quiz.prototype.renderStart = function () {
		var self = this, s = this.cfg.settings;
		var meta = [sprintf(this.i18n.questions, this.questions.length)];
		if (s.timer > 0) { meta.push(sprintf(this.i18n.time_limit, formatTime(s.timer))); }
		this.mount(el('div', { class: 'bgq-screen bgq-start' }, [
			el('h2', { class: 'bgq-title', text: this.data.title, 'data-bgq-focus': true }),
			el('p', { class: 'bgq-meta', text: meta.join(' · ') }),
			el('button', { type: 'button', class: 'bgq-btn bgq-btn--primary', text: this.labels.start, onclick: function () { self.start(); } })
		]));
	};

	// The token is fetched when the visitor presses Start, so page caches never share one and the timer starts now.
	Quiz.prototype.start = function () {
		var self = this;
		var btn = this.root.querySelector('.bgq-start .bgq-btn--primary');
		if (btn) { btn.disabled = true; }
		fetch(this.data.token_url, { credentials: 'same-origin', headers: this.headers(), cache: 'no-store' })
			.then(function (r) { return r.ok ? r.json() : Promise.reject(); })
			.then(function (token) {
				self.token = token;
				self.data.seed = token.seed;
				self.reshuffle();
				self.startedAt = Date.now();
				if (self.cfg.settings.timer > 0) { self.startTimer(); }
				self.renderQuestion();
			})
			.catch(function () { self.renderError(self.i18n.error, true); });
	};

	Quiz.prototype.reshuffle = function () {
		var rnd = mulberry32(this.data.seed);
		var questions = this.cfg.questions.slice();
		if (this.cfg.settings.shuffle_questions) { questions = shuffle(questions, rnd); }
		if (this.cfg.settings.shuffle_answers) {
			questions = questions.map(function (q) { return Object.assign({}, q, { answers: shuffle(q.answers, rnd) }); });
		}
		this.questions = questions;
	};

	Quiz.prototype.headers = function () {
		var h = { 'Content-Type': 'application/json' };
		if (this.data.nonce) { h['X-WP-Nonce'] = this.data.nonce; }
		return h;
	};

	// Countdown derived from the clock, not from tick counts, so background-tab throttling cannot drift it.
	Quiz.prototype.startTimer = function () {
		var self = this, total = parseInt(this.cfg.settings.timer, 10);
		this.remaining = total;
		this.timerId = setInterval(function () {
			self.remaining = total - Math.floor((Date.now() - self.startedAt) / 1000);
			var out = self.root.querySelector('.bgq-timer-value');
			if (out) { out.textContent = formatTime(Math.max(0, self.remaining)); }
			if (self.remaining <= 0) { self.stopTimer(); self.timesUp(); }
		}, 1000);
	};
	Quiz.prototype.stopTimer = function () { if (this.timerId) { clearInterval(this.timerId); this.timerId = null; } };

	Quiz.prototype.timesUp = function () {
		if (this.cfg.settings.require_email) { this.renderEmail(this.i18n.times_up); return; }
		this.submit({});
	};

	Quiz.prototype.timerNode = function () {
		if (!(this.cfg.settings.timer > 0)) { return null; }
		return el('div', { class: 'bgq-timer', role: 'timer', 'aria-live': 'polite' }, [
			el('span', { class: 'bgq-timer-label', text: this.i18n.time_left + ' ' }),
			el('span', { class: 'bgq-timer-value', text: formatTime(Math.max(0, this.remaining)) })
		]);
	};

	Quiz.prototype.renderQuestion = function () {
		var self = this, q = this.questions[this.index], total = this.questions.length;
		var chosen = this.answers[q.id] || [];
		var multiple = q.type === 'multiple';
		var list = el('div', { class: 'bgq-answers', role: multiple ? 'group' : 'radiogroup', 'aria-labelledby': 'bgq-q-' + q.id });

		q.answers.forEach(function (a) {
			var selected = chosen.indexOf(a.id) !== -1;
			var btn = el('button', {
				type: 'button', class: 'bgq-answer' + (selected ? ' is-selected' : ''),
				role: multiple ? 'checkbox' : 'radio', 'aria-checked': selected ? 'true' : 'false', 'data-answer': a.id
			}, [el('span', { class: 'bgq-answer__mark', 'aria-hidden': 'true' }), el('span', { class: 'bgq-answer__text', text: a.text })]);
			btn.addEventListener('click', function () { self.choose(q, a.id, multiple); });
			list.appendChild(btn);
		});

		var error = el('p', { class: 'bgq-error', role: 'alert', hidden: true });
		var nav = el('div', { class: 'bgq-nav' }, [
			this.index > 0 ? el('button', { type: 'button', class: 'bgq-btn bgq-btn--ghost', text: this.labels.back, onclick: function () { self.index -= 1; self.renderQuestion(); } }) : null,
			el('button', { type: 'button', class: 'bgq-btn bgq-btn--primary', text: this.index === total - 1 ? this.labels.submit : this.labels.next, onclick: function () {
				if (!(self.answers[q.id] || []).length) { error.textContent = self.i18n.select_answer; error.hidden = false; return; }
				self.next();
			} })
		]);

		var pct = Math.round(((this.index) / total) * 100);
		this.mount(el('div', { class: 'bgq-screen bgq-question' }, [
			el('div', { class: 'bgq-progress', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(pct) }, [el('span', { class: 'bgq-progress__bar', style: 'width:' + pct + '%' })]),
			el('div', { class: 'bgq-topline' }, [
				el('span', { class: 'bgq-count', text: sprintf(this.i18n.question_of, this.index + 1, total) }),
				this.timerNode()
			]),
			q.image ? el('img', { class: 'bgq-question__image', src: q.image, alt: '' }) : null,
			el('h2', { class: 'bgq-question__text', id: 'bgq-q-' + q.id, text: q.text, 'data-bgq-focus': true }),
			list, error, nav
		]));
	};

	Quiz.prototype.choose = function (q, answerId, multiple) {
		var current = this.answers[q.id] || [];
		if (multiple) {
			current = current.indexOf(answerId) === -1 ? current.concat([answerId]) : current.filter(function (id) { return id !== answerId; });
		} else {
			current = [answerId];
		}
		this.answers[q.id] = current;
		var self = this;
		this.root.querySelectorAll('.bgq-answer').forEach(function (b) {
			var on = self.answers[q.id].indexOf(b.getAttribute('data-answer')) !== -1;
			b.classList.toggle('is-selected', on);
			b.setAttribute('aria-checked', on ? 'true' : 'false');
		});
		var error = this.root.querySelector('.bgq-error');
		if (error) { error.hidden = true; }
	};

	Quiz.prototype.next = function () {
		if (this.index < this.questions.length - 1) { this.index += 1; this.renderQuestion(); return; }
		if (this.cfg.settings.require_email) { this.renderEmail(); return; }
		this.submit({});
	};

	Quiz.prototype.renderEmail = function (notice) {
		var self = this, s = this.cfg.settings;
		var name = el('input', { type: 'text', class: 'bgq-input', id: 'bgq-name-' + this.data.id, autocomplete: 'name' });
		var email = el('input', { type: 'email', class: 'bgq-input', id: 'bgq-email-' + this.data.id, autocomplete: 'email', required: true });
		var consent = s.consent_text ? el('input', { type: 'checkbox', id: 'bgq-consent-' + this.data.id }) : null;
		var error = el('p', { class: 'bgq-error', role: 'alert', hidden: true });
		var form = el('form', { class: 'bgq-form', novalidate: true, onsubmit: function (e) {
			e.preventDefault();
			var value = email.value.trim();
			if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) { error.textContent = self.i18n.email_invalid; error.hidden = false; email.focus(); return; }
			if (consent && !consent.checked) { error.textContent = self.i18n.consent_req; error.hidden = false; consent.focus(); return; }
			self.submit({ email: value, name: name.value.trim(), consent: consent ? consent.checked : false });
		} }, [
			notice ? el('p', { class: 'bgq-meta', text: notice }) : null,
			el('label', { class: 'bgq-label', for: name.id, text: this.i18n.name }), name,
			el('label', { class: 'bgq-label', for: email.id, text: this.i18n.email }), email,
			consent ? el('label', { class: 'bgq-consent', for: consent.id }, [consent, el('span', { html: s.consent_text })]) : null,
			error,
			el('div', { class: 'bgq-nav' }, [el('button', { type: 'submit', class: 'bgq-btn bgq-btn--primary', text: this.labels.submit })])
		]);
		this.mount(el('div', { class: 'bgq-screen bgq-details' }, [
			el('div', { class: 'bgq-topline' }, [el('span', { class: 'bgq-count', text: '' }), this.timerNode()]),
			el('h2', { class: 'bgq-question__text', text: this.i18n.your_details, 'data-bgq-focus': true }),
			form
		]));
	};

	Quiz.prototype.submit = function (extra) {
		var self = this;
		this.stopTimer();
		this.mount(el('div', { class: 'bgq-screen bgq-loading', 'aria-live': 'polite' }, [el('p', { class: 'bgq-meta', text: this.i18n.sending })]));
		var body = Object.assign({ quiz_id: this.data.id, token: this.token, answers: this.answers }, extra || {});
		fetch(this.data.rest_url, { method: 'POST', headers: this.headers(), credentials: 'same-origin', body: JSON.stringify(body) })
			.then(function (r) { return r.json().then(function (json) { return { ok: r.ok, status: r.status, json: json }; }); })
			.then(function (res) {
				if (!res.ok) { self.renderError(res.json && res.json.message ? res.json.message : self.i18n.error, res.status === 403 || res.status === 410); return; }
				self.renderResult(res.json);
			})
			.catch(function () { self.renderError(self.i18n.error); });
	};

	// A stale session (403/410) needs a fresh page; other errors restart in place.
	Quiz.prototype.renderError = function (message, reloadOnRetry) {
		var self = this;
		this.stopTimer();
		this.mount(el('div', { class: 'bgq-screen bgq-failed' }, [
			el('p', { class: 'bgq-error', role: 'alert', text: message }),
			el('button', { type: 'button', class: 'bgq-btn bgq-btn--primary', text: this.labels.retry, onclick: function () {
				if (reloadOnRetry) { window.location.reload(); return; }
				self.reset(); self.renderStart();
			} })
		]));
	};

	Quiz.prototype.renderResult = function (res) {
		var self = this, r = res.result || {}, mode = this.cfg.mode, parts = [];
		if (res.already) { parts.push(el('p', { class: 'bgq-meta', text: this.i18n.already })); }
		if (mode === 'score') {
			parts.push(el('p', { class: 'bgq-score' + (res.passed ? ' is-pass' : ' is-fail'), text: sprintf(this.i18n.score_text, Math.round(res.score), res.correct_count, res.total_count) }));
		}
		parts.push(el('h2', { class: 'bgq-result__title', text: r.title || '', 'data-bgq-focus': true }));
		if (r.image) { parts.push(el('img', { class: 'bgq-result__image', src: r.image, alt: '' })); }
		if (r.text) { parts.push(el('div', { class: 'bgq-result__text', html: r.text })); }
		if (r.product) {
			var p = r.product;
			parts.push(el('div', { class: 'bgq-product' }, [
				p.image ? el('img', { class: 'bgq-product__image', src: p.image, alt: '' }) : null,
				el('div', { class: 'bgq-product__body' }, [
					el('h3', { class: 'bgq-product__name', text: p.name }),
					el('div', { class: 'bgq-product__price', html: p.price_html }),
					el('div', { class: 'bgq-product__actions' }, [
						p.add_to_cart_url ? el('a', { class: 'bgq-btn bgq-btn--primary', href: p.add_to_cart_url, text: this.i18n.add_to_cart }) : null,
						el('a', { class: 'bgq-btn bgq-btn--ghost', href: p.url, text: this.i18n.view_product })
					])
				])
			]));
		}
		if (res.reward && res.reward.code) {
			parts.push(el('div', { class: 'bgq-reward' }, [
				el('p', { class: 'bgq-reward__label', text: sprintf(this.i18n.reward, '') }),
				el('code', { class: 'bgq-reward__code', text: res.reward.code }),
				el('p', { class: 'bgq-meta', text: this.i18n.reward_sent })
			]));
		}
		var actions = [];
		if (r.button_url && r.button_label) { actions.push(el('a', { class: 'bgq-btn bgq-btn--primary', href: r.button_url, text: r.button_label })); }
		if (!this.cfg.settings.one_attempt) { actions.push(el('button', { type: 'button', class: 'bgq-btn bgq-btn--ghost', text: this.labels.retry, onclick: function () { window.location.reload(); } })); }
		if (actions.length) { parts.push(el('div', { class: 'bgq-nav' }, actions)); }
		this.mount(el('div', { class: 'bgq-screen bgq-result', 'aria-live': 'polite' }, parts));
	};

	document.querySelectorAll('.bgq[data-quiz]').forEach(function (root) {
		var data = window['bgq_data_' + root.getAttribute('data-quiz')];
		if (data) { new Quiz(root, data); }
	});
})();
