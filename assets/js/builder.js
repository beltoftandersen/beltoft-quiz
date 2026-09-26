/**
 * Beltoft Quiz — admin builder. Vanilla JS; state is the quiz config object.
 */
(function () {
	'use strict';

	var B = window.bgq_builder;
	var root = document.getElementById('bgq-builder');
	if (!B || !root) { return; }
	var t = B.i18n;
	var state = B.config;
	var errors = {};
	var dirty = false;

	function uid(prefix) { return prefix + '_' + Math.random().toString(36).slice(2, 8); }
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
	function field(label, input, path) {
		var wrap = el('div', { class: 'bgq-field' + (errors[path] ? ' has-error' : '') }, [el('label', { text: label, for: input.id }), input]);
		if (errors[path]) { wrap.appendChild(el('p', { class: 'bgq-field__error', text: errors[path] })); }
		return wrap;
	}
	function input(type, value, onchange, extra) {
		var attrs = Object.assign({ type: type, id: uid('f'), class: type === 'checkbox' ? '' : 'regular-text' }, extra || {});
		var node = el('input', attrs);
		if (type === 'checkbox') { node.checked = !!value; } else { node.value = value === undefined || value === null ? '' : value; }
		node.addEventListener(type === 'checkbox' || type === 'color' ? 'change' : 'input', function () {
			onchange(type === 'checkbox' ? node.checked : (type === 'number' ? Number(node.value) : node.value));
			markDirty();
		});
		return node;
	}
	function select(options, value, onchange) {
		var node = el('select', { id: uid('s') });
		Object.keys(options).forEach(function (k) { node.appendChild(el('option', { value: k, text: options[k], selected: k === value })); });
		node.addEventListener('change', function () { onchange(node.value); markDirty(); });
		return node;
	}
	function textarea(value, onchange, rows) {
		var node = el('textarea', { id: uid('t'), rows: rows || 3, class: 'large-text' });
		node.value = value || '';
		node.addEventListener('input', function () { onchange(node.value); markDirty(); });
		return node;
	}
	function markDirty() { dirty = true; }

	function imagePicker(getId, setId) {
		var wrap = el('div', { class: 'bgq-image' });
		function draw() {
			wrap.innerHTML = '';
			var id = getId();
			if (id) { wrap.appendChild(el('img', { src: '', 'data-id': id, alt: '', class: 'bgq-image__preview' })); fetchThumb(id, wrap); }
			wrap.appendChild(el('button', { type: 'button', class: 'button', text: id ? t.remove_image : t.choose_image, onclick: function () {
				if (id) { setId(0); markDirty(); draw(); return; }
				var frame = wp.media({ title: t.choose_image, multiple: false, library: { type: 'image' } });
				frame.on('select', function () { var att = frame.state().get('selection').first().toJSON(); setId(att.id); markDirty(); draw(); });
				frame.open();
			} }));
		}
		draw();
		return wrap;
	}
	function fetchThumb(id, wrap) {
		if (!window.wp || !wp.media) { return; }
		var att = wp.media.attachment(id);
		att.fetch().then(function () {
			var img = wrap.querySelector('img');
			var sizes = att.get('sizes');
			if (img) { img.src = (sizes && sizes.thumbnail ? sizes.thumbnail.url : att.get('url')) || ''; }
		});
	}

	function moveItem(arr, i, dir) {
		var j = i + dir;
		if (j < 0 || j >= arr.length) { return; }
		var tmp = arr[i]; arr[i] = arr[j]; arr[j] = tmp;
		markDirty(); render();
	}

	/* ---------- sections ---------- */

	function sectionHeader() {
		var title = input('text', state.title, function (v) { state.title = v; });
		title.className = 'bgq-title-input';
		title.placeholder = t.title;
		var status = select({ draft: t.draft, publish: t.published }, state.status === 'publish' ? 'publish' : 'draft', function (v) { state.status = v; });
		var saveBtn = el('button', { type: 'button', class: 'button button-primary bgq-save', text: t.save, onclick: save });
		return el('div', { class: 'bgq-header' }, [
			title,
			el('div', { class: 'bgq-header__actions' }, [status, saveBtn, el('span', { class: 'bgq-save-status', id: 'bgq-save-status' })])
		]);
	}

	function sectionSettings() {
		var s = state.settings;
		var body = [
			field(t.mode, select({ score: t.mode_score, outcome: t.mode_outcome }, state.mode, function (v) { state.mode = v; render(); }), 'mode')
		];
		if (state.mode === 'score') {
			body.push(field(t.pass_mark, input('number', s.pass_mark, function (v) { s.pass_mark = v; }, { min: 0, max: 100 }), 'settings.pass_mark'));
		}
		body.push(field(t.timer, input('number', s.timer, function (v) { s.timer = v; }, { min: 0 }), 'settings.timer'));
		body.push(checkbox(t.shuffle_q, s.shuffle_questions, function (v) { s.shuffle_questions = v; }));
		body.push(checkbox(t.shuffle_a, s.shuffle_answers, function (v) { s.shuffle_answers = v; }));
		body.push(checkbox(t.one_attempt, s.one_attempt, function (v) { s.one_attempt = v; }));
		body.push(checkbox(t.require_email, s.require_email, function (v) { s.require_email = v; render(); }));
		if (s.require_email) { body.push(field(t.consent_text, textarea(s.consent_text, function (v) { s.consent_text = v; }, 2), 'settings.consent_text')); }
		body.push(field(t.accent, input('color', s.accent, function (v) { s.accent = v; }, { class: '' }), 'settings.accent'));
		var labels = el('div', { class: 'bgq-labels' });
		Object.keys(B.default_labels).forEach(function (k) {
			labels.appendChild(field(B.default_labels[k], input('text', s.labels[k], function (v) { s.labels[k] = v; }, { class: 'regular-text' }), 'settings.labels.' + k));
		});
		body.push(el('details', { class: 'bgq-details' }, [el('summary', { text: t.labels }), labels]));
		return section(t.settings, body, null, 'settings');
	}
	function checkbox(label, value, onchange) {
		var node = input('checkbox', value, onchange);
		return el('div', { class: 'bgq-field bgq-field--check' }, [el('label', { for: node.id }, [node, ' ' + label])]);
	}
	function section(title, children, extra, key) {
		return el('div', { class: 'bgq-section', 'data-section': key || '' }, [el('h2', { text: title }), el('div', { class: 'bgq-section__body' }, children)].concat(extra || []));
	}

	function sectionQuestions() {
		var list = el('div', { class: 'bgq-items' });
		state.questions.forEach(function (q, qi) {
			var path = 'questions.' + qi;
			var card = el('div', { class: 'bgq-item' + (errors[path + '.text'] || errors[path + '.answers'] ? ' has-error' : '') });
			card.appendChild(el('div', { class: 'bgq-item__bar' }, [
				el('strong', { text: (qi + 1) + '.' }),
				el('span', { class: 'bgq-item__tools' }, [
					el('button', { type: 'button', class: 'button-link', text: t.move_up, onclick: function () { moveItem(state.questions, qi, -1); } }),
					el('button', { type: 'button', class: 'button-link', text: t.move_down, onclick: function () { moveItem(state.questions, qi, 1); } }),
					el('button', { type: 'button', class: 'button-link bgq-remove', text: t.remove, onclick: function () { state.questions.splice(qi, 1); markDirty(); render(); } })
				])
			]));
			card.appendChild(field(t.question_text, input('text', q.text, function (v) { q.text = v; }, { class: 'large-text' }), path + '.text'));
			card.appendChild(field(t.question_type, select({ single: t.single, multiple: t.multiple }, q.type, function (v) { q.type = v; }), path + '.type'));
			card.appendChild(field(t.image, imagePicker(function () { return q.image_id; }, function (v) { q.image_id = v; }), path + '.image'));

			var answers = el('div', { class: 'bgq-answers-edit' });
			q.answers.forEach(function (a, ai) {
				var row = el('div', { class: 'bgq-answer-row' });
				var text = input('text', a.text, function (v) { a.text = v; }, { class: 'regular-text', placeholder: t.answer_text });
				row.appendChild(text);
				if (state.mode === 'score') {
					var c = input('checkbox', a.correct, function (v) { a.correct = v; });
					row.appendChild(el('label', { class: 'bgq-inline', for: c.id }, [c, ' ' + t.correct]));
				} else {
					a.points = a.points || {};
					state.results.forEach(function (r) {
						var p = input('number', a.points[r.id] || 0, function (v) { if (v > 0) { a.points[r.id] = v; } else { delete a.points[r.id]; } }, { min: 0, class: 'small-text', title: t.points_for + ' ' + r.title });
						row.appendChild(el('label', { class: 'bgq-inline bgq-points' }, [el('span', { text: r.title || ('#' + r.id) }), p]));
					});
				}
				row.appendChild(el('button', { type: 'button', class: 'button-link bgq-remove', text: t.remove, onclick: function () { q.answers.splice(ai, 1); markDirty(); render(); } }));
				answers.appendChild(row);
			});
			var answersField = el('div', { class: 'bgq-field' + (errors[path + '.answers'] ? ' has-error' : '') }, [el('label', { text: t.answers }), answers]);
			if (errors[path + '.answers']) { answersField.appendChild(el('p', { class: 'bgq-field__error', text: errors[path + '.answers'] })); }
			answersField.appendChild(el('button', { type: 'button', class: 'button', text: t.add_answer, onclick: function () { q.answers.push({ id: uid('a'), text: '', correct: false, points: {} }); markDirty(); render(); } }));
			card.appendChild(answersField);
			list.appendChild(card);
		});
		var add = el('button', { type: 'button', class: 'button button-secondary', text: t.add_question, onclick: function () {
			state.questions.push({ id: uid('q'), text: '', image_id: 0, type: 'single', answers: [{ id: uid('a'), text: '', correct: true, points: {} }, { id: uid('a'), text: '', correct: false, points: {} }] });
			markDirty(); render();
		} });
		return section(t.questions, [errorFor('questions'), list, add], null, 'questions');
	}

	function sectionResults() {
		var list = el('div', { class: 'bgq-items' });
		state.results.forEach(function (r, ri) {
			var path = 'results.' + ri;
			var card = el('div', { class: 'bgq-item' + (errors[path + '.title'] || errors[path + '.range'] ? ' has-error' : '') });
			card.appendChild(el('div', { class: 'bgq-item__bar' }, [
				el('strong', { text: (ri + 1) + '.' }),
				el('span', { class: 'bgq-item__tools' }, [
					el('button', { type: 'button', class: 'button-link', text: t.move_up, onclick: function () { moveItem(state.results, ri, -1); } }),
					el('button', { type: 'button', class: 'button-link', text: t.move_down, onclick: function () { moveItem(state.results, ri, 1); } }),
					el('button', { type: 'button', class: 'button-link bgq-remove', text: t.remove, onclick: function () { state.results.splice(ri, 1); markDirty(); render(); } })
				])
			]));
			card.appendChild(field(t.result_title, input('text', r.title, function (v) { r.title = v; }, { class: 'large-text' }), path + '.title'));
			card.appendChild(field(t.result_text, textarea(r.text, function (v) { r.text = v; }, 4), path + '.text'));
			if (state.mode === 'score') {
				var range = el('div', { class: 'bgq-range' }, [
					input('number', r.min === undefined ? 0 : r.min, function (v) { r.min = v; }, { min: 0, max: 100, class: 'small-text' }),
					' – ',
					input('number', r.max === undefined ? 100 : r.max, function (v) { r.max = v; }, { min: 0, max: 100, class: 'small-text' })
				]);
				card.appendChild(field(t.score_range, range, path + '.range'));
			}
			card.appendChild(field(t.image, imagePicker(function () { return r.image_id; }, function (v) { r.image_id = v; }), path + '.image'));
			card.appendChild(field(t.button_label, input('text', r.button_label, function (v) { r.button_label = v; }), path + '.button_label'));
			card.appendChild(field(t.button_url, input('url', r.button_url, function (v) { r.button_url = v; }, { class: 'large-text' }), path + '.button_url'));
			if (B.woocommerce_active) { card.appendChild(field(t.product, productPicker(r), path + '.product_id')); }
			list.appendChild(card);
		});
		var add = el('button', { type: 'button', class: 'button button-secondary', text: t.add_result, onclick: function () {
			state.results.push({ id: uid('r'), title: '', text: '', image_id: 0, button_label: '', button_url: '', product_id: 0, min: 0, max: 100 });
			markDirty(); render();
		} });
		return section(t.results, [errorFor('results'), list, add], null, 'results');
	}

	function productPicker(r) {
		var wrap = el('div', { class: 'bgq-product-picker' });
		var chosen = el('span', { class: 'bgq-product-picker__chosen', text: r.product_id ? '#' + r.product_id : t.product_none });
		var search = el('input', { type: 'search', class: 'regular-text', placeholder: t.product_search });
		var results = el('ul', { class: 'bgq-product-picker__results' });
		var timer;
		search.addEventListener('input', function () {
			clearTimeout(timer);
			var term = search.value.trim();
			if (term.length < 2) { results.innerHTML = ''; return; }
			timer = setTimeout(function () {
				window.fetch(B.product_search_url + '?search=' + encodeURIComponent(term) + '&per_page=8', { credentials: 'same-origin' })
					.then(function (res) { return res.json(); })
					.then(function (items) {
						results.innerHTML = '';
						(items || []).forEach(function (p) {
							results.appendChild(el('li', {}, [el('button', { type: 'button', class: 'button-link', text: p.name + ' (#' + p.id + ')', onclick: function () {
								r.product_id = p.id; chosen.textContent = p.name + ' (#' + p.id + ')'; results.innerHTML = ''; search.value = ''; markDirty();
							} })]));
						});
					}).catch(function () { results.innerHTML = ''; });
			}, 250);
		});
		var clear = el('button', { type: 'button', class: 'button-link', text: t.remove, onclick: function () { r.product_id = 0; chosen.textContent = t.product_none; markDirty(); } });
		if (r.product_id) { fetchProductName(r.product_id, chosen); }
		wrap.appendChild(el('div', {}, [chosen, ' ', clear]));
		wrap.appendChild(search);
		wrap.appendChild(results);
		return wrap;
	}
	function fetchProductName(id, node) {
		window.fetch(B.product_search_url + '/' + id, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (p) { if (p && p.name) { node.textContent = p.name + ' (#' + p.id + ')'; } }).catch(function () {});
	}

	function sectionReward() {
		if (!B.gift_cards_active) { return null; }
		var rw = state.reward;
		var body = [checkbox(t.reward_enable, rw.enabled, function (v) { rw.enabled = v; render(); })];
		if (rw.enabled) {
			body.push(el('p', { class: 'description', text: t.reward_note }));
			body.push(field(t.reward_amount, input('number', rw.amount, function (v) { rw.amount = v; }, { min: 0, step: '0.01', class: 'small-text' }), 'reward.amount'));
			body.push(field(t.reward_expiry, input('number', rw.expiry_days, function (v) { rw.expiry_days = v; }, { min: 0, class: 'small-text' }), 'reward.expiry_days'));
			var when = { always: t.reward_always };
			if (state.mode === 'score') { when.pass = t.reward_pass; } else { when.outcome = t.reward_outcome; }
			body.push(field(t.reward_when, select(when, when[rw.condition] ? rw.condition : 'always', function (v) { rw.condition = v; render(); }), 'reward.condition'));
			if (rw.condition === 'outcome') {
				var list = el('div', { class: 'bgq-check-list' });
				state.results.forEach(function (r) {
					var c = input('checkbox', (rw.outcome_ids || []).indexOf(r.id) !== -1, function (v) {
						rw.outcome_ids = (rw.outcome_ids || []).filter(function (id) { return id !== r.id; });
						if (v) { rw.outcome_ids.push(r.id); }
					});
					list.appendChild(el('label', { for: c.id }, [c, ' ' + (r.title || r.id)]));
				});
				body.push(list);
			}
		}
		return section(t.reward, body, null, 'reward');
	}

	function sectionEmbed() {
		var code = el('code', { text: B.shortcode });
		var copy = el('button', { type: 'button', class: 'button', text: t.copy, onclick: function () {
			if (navigator.clipboard) { navigator.clipboard.writeText(B.shortcode).then(function () { copy.textContent = t.copied; setTimeout(function () { copy.textContent = t.copy; }, 1500); }); }
		} });
		var exportBtn = el('button', { type: 'button', class: 'button', text: t.export, onclick: function () {
			var blob = new Blob([JSON.stringify(exportable(), null, 2)], { type: 'application/json' });
			var a = el('a', { href: URL.createObjectURL(blob), download: 'quiz-' + B.quiz_id + '.json' });
			document.body.appendChild(a); a.click(); a.remove();
		} });
		var file = el('input', { type: 'file', accept: 'application/json', class: 'bgq-import' });
		file.addEventListener('change', function () {
			var f = file.files[0]; if (!f) { return; }
			var reader = new FileReader();
			reader.onload = function () {
				try {
					var data = JSON.parse(reader.result);
					if (!data || !data.questions) { throw new Error('bad'); }
					['mode', 'settings', 'questions', 'results', 'reward'].forEach(function (k) { if (data[k] !== undefined) { state[k] = data[k]; } });
					markDirty(); render();
				} catch (e) { window.alert(t.import_bad); }
			};
			reader.readAsText(f);
		});
		return section(t.embed, [el('p', { class: 'description', text: t.embed_help }), el('p', {}, [code, ' ', copy])], [
			el('div', { class: 'bgq-section__body' }, [el('h3', { text: t.import_export }), exportBtn, ' ', el('label', { class: 'button' }, [t.import, file])])
		], 'embed');
	}

	function exportable() {
		return { mode: state.mode, settings: state.settings, questions: state.questions, results: state.results, reward: state.reward };
	}

	function errorFor(path) {
		return errors[path] ? el('p', { class: 'bgq-field__error', text: errors[path] }) : null;
	}
	function errorSummary() {
		var keys = Object.keys(errors);
		if (!keys.length) { return null; }
		return el('div', { class: 'notice notice-error bgq-errors' }, [el('p', { text: t.field_errors }), el('ul', {}, keys.map(function (k) { return el('li', { text: k + ': ' + errors[k] }); }))]);
	}

	/* ---------- save ---------- */

	function save() {
		var status = document.getElementById('bgq-save-status');
		var btn = root.querySelector('.bgq-save');
		btn.disabled = true; status.textContent = t.saving; status.className = 'bgq-save-status';
		var body = Object.assign({ title: state.title, status: state.status }, exportable());
		window.fetch(B.rest_url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': B.nonce }, body: JSON.stringify(body) })
			.then(function (r) { return r.json().then(function (json) { return { ok: r.ok, json: json }; }); })
			.then(function (res) {
				btn.disabled = false;
				if (!res.ok) {
					errors = (res.json && res.json.data && res.json.data.errors) || {};
					render();
					setStatus((res.json && res.json.message) || t.save_failed, 'is-error');
					return;
				}
				errors = {}; dirty = false;
				['mode', 'settings', 'questions', 'results', 'reward', 'title', 'status'].forEach(function (k) { state[k] = res.json[k]; });
				render();
				setStatus(t.saved, 'is-ok');
			})
			.catch(function () { btn.disabled = false; setStatus(t.network_error, 'is-error'); });
	}
	function setStatus(text, cls) {
		var node = document.getElementById('bgq-save-status');
		if (node) { node.textContent = text; node.className = 'bgq-save-status ' + (cls || ''); }
	}

	function render() {
		var scrollY = window.scrollY;
		root.innerHTML = '';
		root.appendChild(sectionHeader());
		var summary = errorSummary(); if (summary) { root.appendChild(summary); }
		root.appendChild(sectionSettings());
		root.appendChild(sectionQuestions());
		root.appendChild(sectionResults());
		var reward = sectionReward(); if (reward) { root.appendChild(reward); }
		root.appendChild(sectionEmbed());
		root.appendChild(el('p', {}, [el('a', { href: B.list_url, text: '← ' + t.back_to_list })]));
		window.scrollTo(0, scrollY);
	}

	window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = t.unsaved; } });
	render();
})();
