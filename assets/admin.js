(function () {
	'use strict';

	var A = window.PQANALYTICS_ADMIN;
	if (!A) {
		return;
	}
	var T = A.i18n;

	function esc(s) {
		return String(s === null || s === undefined ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	function api(path, method, body) {
		var opts = {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': A.nonce, 'Accept': 'application/json' }
		};
		if (body) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}
		var url = A.root + path;
		if (A.root.indexOf('?') !== -1 && path.indexOf('?') !== -1) {
			url = A.root + path.replace('?', '&');
		}
		return fetch(url, opts).then(function (r) {
			return r.json().then(function (data) {
				if (!r.ok) {
					var err = new Error((data && data.message) || T.error);
					err.data = data;
					throw err;
				}
				return data;
			});
		});
	}

	function qs(sel, root) {
		return (root || document).querySelector(sel);
	}
	function qsa(sel, root) {
		return Array.prototype.slice.call((root || document).querySelectorAll(sel));
	}
	function fmt(n) {
		return Number(n || 0).toLocaleString();
	}
	function pct(part, total) {
		if (!total) {
			return '';
		}
		return Math.round(part / total * 100) + '%';
	}
	function pathOf(url, withQuery) {
		try {
			var u = new URL(url);
			return (u.pathname + (withQuery ? u.search : '')) || '/';
		} catch (e) {
			return url;
		}
	}
	function hostOf(url) {
		try {
			return new URL(url).host.replace(/^www\./, '');
		} catch (e) {
			return '';
		}
	}
	function pathLabel(p, url) {
		return p === '/' ? (hostOf(url) || '/') : p;
	}
	function copyText(text, btn) {
		var done = function () {
			var old = btn.textContent;
			btn.textContent = T.copied;
			setTimeout(function () { btn.textContent = old; }, 1500);
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text); done(); });
		} else {
			fallbackCopy(text);
			done();
		}
	}
	function fallbackCopy(text) {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.setAttribute('readonly', '');
		ta.style.position = 'fixed';
		ta.style.top = '-1000px';
		document.body.appendChild(ta);
		ta.select();
		try { document.execCommand('copy'); } catch (e) {}
		document.body.removeChild(ta);
	}

	document.addEventListener('click', function (e) {
		var copy = e.target.closest && e.target.closest('.pqanalytics-copy');
		if (copy) {
			copyText(copy.getAttribute('data-copy'), copy);
		}
	});

	function spark(key, values, nowIndex, labels) {
		var svg = qs('.pqanalytics-spark[data-spark="' + key + '"]');
		if (!svg) {
			return;
		}
		var n = values.length;
		if (!n) {
			svg.innerHTML = '';
			return;
		}
		var W = 100, H = 34, gap = 1.2, w = Math.max(1.5, (W - gap * (n - 1)) / n);
		var max = 0;
		values.forEach(function (v) { if (v > max) { max = v; } });
		var html = '';
		values.forEach(function (v, i) {
			var h = max ? Math.max(1.5, (v / max) * (H - 2)) : 1.5;
			var x = i * (w + gap);
			html += '<rect x="' + x.toFixed(2) + '" y="' + (H - h).toFixed(2) + '" width="' + w.toFixed(2) + '" height="' + h.toFixed(2) + '" rx="1"' + (i === nowIndex ? ' class="is-now"' : '') + '><title>' + esc(labels[i]) + ': ' + fmt(v) + '</title></rect>';
		});
		svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
		svg.setAttribute('preserveAspectRatio', 'none');
		svg.innerHTML = html;
	}

	function bar(v, max) {
		if (!max) {
			return '';
		}
		return '<div class="pqanalytics-bar"><i style="width:' + Math.max(2, Math.round(v / max * 100)) + '%"></i></div>';
	}

	function maxOf(rows, key) {
		var m = 0;
		rows.forEach(function (r) { var v = parseInt(r[key], 10) || 0; if (v > m) { m = v; } });
		return m;
	}

	function geoDownload(btn) {
		var out = qs('#pqanalytics-geo-auto');
		var notice = qs('#pqanalytics-geo-notice');
		var mb = function (b) { return (b / 1048576).toFixed(0); };
		function finish(text, ok) {
			var lead = qs('#pqanalytics-geo-text');
			btn.disabled = false;
			if (notice) {
				notice.className = 'notice notice-' + (ok ? 'success' : 'warning') + ' inline';
			}
			if (ok && lead) {
				lead.textContent = text;
				btn.style.display = 'none';
				out.textContent = '';
				return;
			}
			out.textContent = text;
		}
		function run(kinds) {
			if (!kinds.length) {
				finish(T.geoDone, true);
				return;
			}
			var kind = kinds[0];
			api('geo/step', 'POST', { kind: kind }).then(function (d) {
				if (d.busy) {
					out.textContent = T.geoWaiting + '…';
					setTimeout(function () { run(kinds); }, 5000);
					return;
				}
				if (d.error) {
					finish(T.geoFailed + ' (' + d.error + ')', false);
					return;
				}
				if (d.done) {
					run(kinds.slice(1));
					return;
				}
				out.textContent = T.geoWorking + ' ' + mb(d.received) + (d.total ? ' / ' + mb(d.total) : '') + ' MB' + (d.total ? ' (' + Math.min(99, Math.round(d.received / d.total * 100)) + '%)' : '');
				run(kinds);
			}).catch(function (err) {
				finish(T.geoFailed + ' (' + (err.message || T.error) + ')', false);
			});
		}
		btn.disabled = true;
		out.textContent = T.geoWorking;
		api('geo/status').then(function (d) {
			var todo = [];
			(d.status || []).forEach(function (s) {
				if (!s.installed) {
					todo.push(s.kind);
				}
			});
			run(todo);
		}).catch(function (err) {
			finish(T.geoFailed + ' (' + (err.message || T.error) + ')', false);
		});
	}

	document.addEventListener('click', function (e) {
		var start = e.target.closest && e.target.closest('#pqanalytics-geo-start');
		if (start) {
			geoDownload(start);
		}
	});

	if (A.screen === 'visitors') {
		var state = { filter: 'all', search: '', page: 1, timer: null, busy: false, expanded: {}, history: {}, charts: 0, in: 'all', unique: 0, page1: '', page2: '', from: '', to: '', tfrom: '', tto: '' };
		var chartsEvery = A.chartsEvery || 300000;

		function filterQuery() {
			return 'filter=' + encodeURIComponent(state.filter) + '&search=' + encodeURIComponent(state.search) + '&in=' + encodeURIComponent(state.in) + '&unique=' + (state.unique ? 1 : 0) +
				'&page1=' + encodeURIComponent(state.page1) + '&page2=' + encodeURIComponent(state.page2) + '&from=' + encodeURIComponent(state.from) + '&to=' + encodeURIComponent(state.to) +
				'&tfrom=' + encodeURIComponent(state.tfrom) + '&tto=' + encodeURIComponent(state.tto);
		}

		function hasFilters() {
			return !!(state.search || state.unique || state.page1 || state.page2 || state.from || state.to || state.tfrom || state.tto || state.filter !== 'all');
		}

		function updateExport() {
			var link = qs('#pqanalytics-export');
			if (!link || !A.exportUrl) {
				return;
			}
			link.href = hasFilters() ? A.exportUrl + '&' + filterQuery() : A.exportUrl;
			link.textContent = !hasFilters() ? T.export30 : ((state.from || state.to) ? T.exportF : T.exportF30);
		}

		function updateZone(z) {
			var el = qs('#pqanalytics-zone');
			if (!el || !z) {
				return;
			}
			el.textContent = T.timesIn.replace('%s', z.name + (z.abbr ? ' (' + z.abbr + ')' : ''));
		}

		function loadOverview() {
			if (state.busy) {
				return;
			}
			state.busy = true;
			var full = !state.charts || Date.now() - state.charts >= chartsEvery;
			var q = 'overview?' + filterQuery() + '&page=' + state.page + (full ? '' : '&light=1') + (pathsLoaded ? '' : '&paths=1');
			api(q).then(function (d) {
				renderCards(d.cards);
				if (d.hourly) { renderHourly(d.hourly); }
				if (d.daily) { renderDaily(d.daily); }
				if (d.screens) { renderScreens(d.screens); }
				if (d.paths) { pathsLoaded = renderPaths(d.paths); }
				if (d.hourly && d.daily && d.screens) { state.charts = Date.now(); }
				renderVisitors(d.visitors);
				updateZone(d.visitors && d.visitors.zone);
				updateExport();
				refreshExpanded();
				var u = qs('#pqanalytics-updated');
				if (u) {
					u.textContent = T.updated + ' ' + d.now;
				}
			}).catch(function (err) {
				var u = qs('#pqanalytics-updated');
				if (u) {
					u.textContent = err.message || T.error;
				}
			}).then(function () {
				state.busy = false;
			});
		}

		function renderCards(c) {
			var map = { live: c.live, today: c.today.u, yesterday: c.yesterday.u, d7: c.d7.u, d30: c.d30.u, bots: c.bots.u };
			Object.keys(map).forEach(function (k) {
				var el = qs('[data-card="' + k + '"]');
				if (el) {
					el.textContent = fmt(map[k]);
				}
			});
			var subs = { today: c.today.p, yesterday: c.yesterday.p, d7: c.d7.p, d30: c.d30.p, bots: c.bots.p };
			Object.keys(subs).forEach(function (k) {
				var el = qs('[data-sub="' + k + '"]');
				if (el) {
					el.textContent = fmt(subs[k]) + ' ' + (subs[k] === 1 ? T.page : T.pages);
				}
			});
			var bn = qs('[data-card="bounce"]'), bs = qs('[data-sub="bounce"]');
			if (bn) {
				bn.textContent = (c.d30.b || 0) + '%';
			}
			if (bs) {
				bs.textContent = T.bounceSub.replace('%1$s', (c.today.b || 0) + '%').replace('%2$s', (c.d7.b || 0) + '%');
			}
		}

		function renderHourly(h) {
			var body = qs('#pqanalytics-hourly tbody');
			if (!body) {
				return;
			}
			var html = '', shown = h.rows.filter(function (r) { return r.h <= h.current_hour; });
			var m = maxOf(shown, 'u');
			shown.forEach(function (r) {
				var label = (r.h < 10 ? '0' : '') + r.h + ':00';
				html += '<tr' + (r.h === h.current_hour ? ' class="pqanalytics-now"' : '') + '><td>' + label + bar(r.u, m) + '</td><td>' + fmt(r.u) + '</td><td>' + fmt(r.p) + '</td></tr>';
			});
			body.innerHTML = html;
			spark('today', shown.map(function (r) { return parseInt(r.u, 10) || 0; }), shown.length - 1, shown.map(function (r) { return (r.h < 10 ? '0' : '') + r.h + ':00'; }));
		}

		function renderDaily(days) {
			var body = qs('#pqanalytics-daily tbody');
			if (!body) {
				return;
			}
			var html = '', m = maxOf(days, 'u');
			days.forEach(function (r, i) {
				html += '<tr' + (i === 0 ? ' class="pqanalytics-now"' : '') + '><td>' + esc(r.label) + bar(r.u, m) + '</td><td>' + fmt(r.u) + '</td><td>' + fmt(r.p) + '</td><td>' + fmt(r.b) + '</td></tr>';
			});
			body.innerHTML = html;
			var chrono = days.slice().reverse();
			var labels = chrono.map(function (r) { return r.label; });
			spark('d30', chrono.map(function (r) { return parseInt(r.u, 10) || 0; }), chrono.length - 1, labels);
			var last7 = chrono.slice(-7);
			spark('d7', last7.map(function (r) { return parseInt(r.u, 10) || 0; }), last7.length - 1, labels.slice(-7));
			spark('bots', chrono.map(function (r) { return parseInt(r.b, 10) || 0; }), chrono.length - 1, labels);
		}

		function renderScreens(s) {
			var cb = qs('#pqanalytics-classes tbody');
			if (cb) {
				var ch = '', cm = maxOf(s.classes, 'u');
				s.classes.forEach(function (r) {
					ch += '<tr><td>' + esc(r['class']) + bar(r.u, cm) + '</td><td>' + fmt(r.u) + '</td><td>' + pct(r.u, s.total) + '</td></tr>';
				});
				cb.innerHTML = ch || '<tr><td colspan="3">–</td></tr>';
			}
			var rb = qs('#pqanalytics-res tbody');
			if (rb) {
				var rh = '', rm = maxOf(s.resolutions, 'u');
				s.resolutions.forEach(function (r) {
					rh += '<tr><td>' + esc(r.physical) + ' <span class="pqanalytics-muted">' + esc(r['class']) + '</span>' + bar(r.u, rm) + '</td><td>' + esc(r.dpr) + '×</td><td>' + fmt(r.u) + '</td><td>' + pct(r.u, s.total) + '</td></tr>';
				});
				rb.innerHTML = rh || '<tr><td colspan="4">–</td></tr>';
			}
		}

		var pathsLoaded = false;
		function renderPaths(paths) {
			var list = qs('#pqanalytics-paths');
			if (!list) {
				return true;
			}
			var active = document.activeElement;
			if (active && (active.id === 'pqanalytics-page1' || active.id === 'pqanalytics-page2')) {
				return false;
			}
			var html = '';
			paths.forEach(function (p) {
				html += '<option value="' + esc(p.p) + '" label="' + esc(p.n === 1 ? T.visit1 : T.visitsN.replace('%s', fmt(p.n))) + '"></option>';
			});
			list.innerHTML = html;
			return true;
		}

		function visitorCell(r) {
			if (r.is_bot) {
				return '<span class="pqanalytics-tag pqanalytics-tag-bot">' + esc(r.bot_name || 'Bot') + '</span>';
			}
			var out = '';
			if (r.member) {
				out += '<span class="pqanalytics-tag pqanalytics-tag-member">' + T.member + '</span> <strong>' + esc(r.member) + '</strong><br>';
			}
			if (r.name) {
				out += '<strong>' + esc(r.name) + '</strong><br>';
			}
			if (r.email) {
				out += '<span class="pqanalytics-muted">' + esc(r.email) + '</span><br>';
			}
			var total = Math.max(parseInt(r.visits_total, 10) || 0, r.visit_no);
			if (r.visit_no > 1) {
				out += '<span class="pqanalytics-tag pqanalytics-tag-returning">' + T.returning + '</span>';
			} else {
				out += '<span class="pqanalytics-tag pqanalytics-tag-new">' + T.new + '</span>';
			}
			if (total > 1) {
				out += '<div class="pqanalytics-muted">' + T.visitOf.replace('%1$s', fmt(r.visit_no)).replace('%2$s', fmt(total)) + (r.first_seen ? ', ' + T.firstSeen + ' ' + esc(r.first_seen) : '') + '</div>';
				if (r.vid) {
					out += '<button type="button" class="button-link pqanalytics-history" data-vid="' + esc(r.vid) + '" data-id="' + r.id + '">' + (state.history[r.id] ? T.hidePast : T.pastVisits) + '</button>';
				}
			}
			return out;
		}

		function sourceCell(r) {
			var out = esc(r.source || 'Direct');
			if (r.source_type && r.source_type !== 'direct' && r.source_type !== 'bot') {
				out += ' <span class="pqanalytics-muted">' + esc(r.source_type) + '</span>';
			}
			if (r.keyword) {
				out += '<div class="pqanalytics-muted">' + T.keyword + ': ' + esc(r.keyword) + '</div>';
			}
			if (r.campaign) {
				out += '<div class="pqanalytics-muted">' + T.campaign + ': ' + esc(r.campaign) + '</div>';
			}
			if (r.referrer) {
				out += '<div class="pqanalytics-muted pqanalytics-ref" title="' + esc(r.referrer) + '">' + esc(r.referrer.replace(/^https?:\/\//, '').substring(0, 60)) + '</div>';
			}
			return out;
		}

		function locationCell(r) {
			var loc = r.location ? esc(r.location) : '<span class="pqanalytics-muted">–</span>';
			if (r.postal) {
				loc += '<div class="pqanalytics-muted">' + esc(r.postal) + '</div>';
			}
			return loc;
		}

		function pageLine(h, current) {
			var p = pathOf(h.url || '');
			return '<li class="' + (current ? 'pqanalytics-current' : '') + '">' +
				(current ? '<span class="pqanalytics-arrow-now" title="' + T.current + '">&gt;</span> ' : '') +
				'<span class="pqanalytics-muted">' + esc(h.time) + '</span> ' +
				'<a href="' + esc(h.url) + '" target="_blank" rel="noopener" title="' + esc(pathOf(h.url || '', true)) + '">' + esc(p.length > 50 ? p.substring(0, 50) + '…' : pathLabel(p, h.url)) + '</a>' +
				(h.duration && !current ? ' <span class="pqanalytics-muted">' + h.duration + T.seconds + '</span>' : '') +
				'</li>';
		}

		function pageCell(r) {
			var hits = r.recent || [];
			if (!hits.length) {
				var p = pathOf(r.url || '');
				return '<a href="' + esc(r.url) + '" target="_blank" rel="noopener">' + esc(p.length > 50 ? p.substring(0, 50) + '…' : pathLabel(p, r.url)) + '</a>';
			}
			var out = '<ul class="pqanalytics-recent">';
			hits.forEach(function (h, i) {
				out += pageLine(h, r.live && i === hits.length - 1);
			});
			out += '</ul>';
			if (r.more > 0 || state.expanded[r.id]) {
				out += '<button type="button" class="button-link pqanalytics-path" data-id="' + r.id + '">' + (state.expanded[r.id] ? T.hideAll : T.viewAll) + '</button>';
			}
			return out;
		}

		function screenCell(r) {
			if (r.is_bot) {
				return '<span class="pqanalytics-muted">–</span>';
			}
			var out = esc(r.screen || '');
			if (r.resolution) {
				out += '<div class="pqanalytics-muted">' + esc(r.resolution) + (r.dpr && r.dpr !== '1' ? ' @' + esc(r.dpr) + '×' : '') + '</div>';
			}
			return out;
		}

		function chatLink(r) {
			if (!A.chatUrl || r.is_bot) {
				return '';
			}
			var href = A.chatUrl;
			if (A.chatByVid && r.vid) {
				href += (href.indexOf('?') === -1 ? '?' : '&') + 'vid=' + encodeURIComponent(r.vid);
			}
			return '<a class="button button-small pqanalytics-chat-link" href="' + esc(href) + '">' + T.chat + '</a> ';
		}

		function noteBox(r) {
			if (!r.vid) {
				return '';
			}
			return '<input type="text" class="pqanalytics-note" maxlength="60" placeholder="' + esc(T.note) + '" value="' + esc(r.label || '') + '" data-saved="' + esc(r.label || '') + '" data-vid="' + esc(r.vid) + '"' + (A.canBlock ? '' : ' readonly') + '>';
		}

		function actionsCell(r) {
			var out = chatLink(r);
			if (!A.canBlock) {
				return out + noteBox(r);
			}
			if (r.blocked) {
				out += '<button type="button" class="button button-small pqanalytics-unblock" data-ip="' + esc(r.ip) + '">' + T.unblock + '</button>';
			} else {
				out += '<button type="button" class="button button-small pqanalytics-block" data-ip="' + esc(r.ip) + '">' + T.block + '</button>';
			}
			return out + noteBox(r);
		}

		function saveNote(input) {
			var vid = input.getAttribute('data-vid');
			var value = input.value.substring(0, 60);
			if (!vid || value === (input.getAttribute('data-saved') || '')) {
				return;
			}
			api('note', 'POST', { vid: vid, note: value }).then(function (d) {
				var saved = d && typeof d.note === 'string' ? d.note : value;
				qsa('.pqanalytics-note[data-vid="' + vid + '"]').forEach(function (box) {
					box.value = saved;
					box.setAttribute('data-saved', saved);
				});
				input.classList.add('pqanalytics-note-saved');
				setTimeout(function () { input.classList.remove('pqanalytics-note-saved'); }, 1200);
			}).catch(function () {
				input.classList.add('pqanalytics-note-error');
				setTimeout(function () { input.classList.remove('pqanalytics-note-error'); }, 1500);
			});
		}

		document.addEventListener('change', function (e) {
			if (e.target && e.target.classList && e.target.classList.contains('pqanalytics-note') && A.canBlock) {
				saveNote(e.target);
			}
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && e.target && e.target.classList && e.target.classList.contains('pqanalytics-note')) {
				e.preventDefault();
				e.target.blur();
			}
		});

		function renderVisitors(v) {
			var body = qs('#pqanalytics-visitors tbody');
			var count = qs('#pqanalytics-count');
			if (count) {
				count.textContent = fmt(v.total);
			}
			var editing = document.activeElement;
			if (editing && editing.classList && editing.classList.contains('pqanalytics-note') && body.contains(editing)) {
				return;
			}
			if (!v.rows.length) {
				body.innerHTML = '<tr><td colspan="10" class="pqanalytics-empty">' + (!hasFilters() ? T.noRows : T.noRowsF) + '</td></tr>';
			} else {
				var html = '';
				v.rows.forEach(function (r) {
					html += '<tr data-id="' + r.id + '" class="' + (r.live ? 'pqanalytics-live' : '') + (r.blocked ? ' pqanalytics-is-blocked' : '') + '">' +
						'<td class="pqanalytics-col-live">' + (r.live ? '<span class="pqanalytics-dot" title="' + T.live + '"></span>' : '') + '</td>' +
						'<td class="pqanalytics-nowrap">' + esc(r.time) + '</td>' +
						'<td class="pqanalytics-ip"><code>' + esc(r.ip) + '</code>' + (r.blocked ? '<div class="pqanalytics-tag pqanalytics-tag-blocked">' + T.blocked + '</div>' : '') + '<div class="pqanalytics-actions">' + actionsCell(r) + '</div></td>' +
						'<td>' + locationCell(r) + '</td>' +
						'<td>' + visitorCell(r) + '</td>' +
						'<td>' + sourceCell(r) + '</td>' +
						'<td class="pqanalytics-page">' + pageCell(r) + '</td>' +
						'<td>' + screenCell(r) + '</td>' +
						'<td>' + esc(r.browser) + (r.os ? '<div class="pqanalytics-muted">' + esc(r.os) + '</div>' : '') + '</td>' +
						'<td>' + (r.isp ? esc(r.isp) : '<span class="pqanalytics-muted">–</span>') + '</td>' +
						'</tr>';
					if (state.expanded[r.id]) {
						html += '<tr class="pqanalytics-path-row" data-for="' + r.id + '"><td colspan="10">' + state.expanded[r.id] + '</td></tr>';
					}
					if (state.history[r.id]) {
						html += '<tr class="pqanalytics-history-row" data-for="' + r.id + '"><td colspan="10">' + state.history[r.id] + '</td></tr>';
					}
				});
				body.innerHTML = html;
			}
			var pager = qs('#pqanalytics-pager');
			if (pager) {
				if (v.pages <= 1) {
					pager.innerHTML = '';
				} else {
					pager.innerHTML =
						'<button type="button" class="button" data-page="' + (v.page - 1) + '" ' + (v.page <= 1 ? 'disabled' : '') + '>&lsaquo;</button> ' +
						'<span>' + v.page + ' / ' + v.pages + '</span> ' +
						'<button type="button" class="button" data-page="' + (v.page + 1) + '" ' + (v.page >= v.pages ? 'disabled' : '') + '>&rsaquo;</button>';
				}
			}
		}

		function refreshExpanded() {
			Object.keys(state.expanded).forEach(function (id) {
				var row = qs('.pqanalytics-path-row[data-for="' + id + '"] td');
				if (!row) {
					return;
				}
				var tr = qs('tr[data-id="' + id + '"]');
				api('visit/' + id + '/path').then(function (d) {
					var html = pathHtml(d.hits, tr && tr.classList.contains('pqanalytics-live'));
					if (html !== state.expanded[id]) {
						state.expanded[id] = html;
						row.innerHTML = html;
					}
				}).catch(function () {});
			});
		}

		function pathHtml(hits, live) {
			if (!hits.length) {
				return '<span class="pqanalytics-muted">–</span>';
			}
			var html = '<ol class="pqanalytics-path-list">';
			hits.forEach(function (h, i) {
				var p = pathLabel(pathOf(h.url, true), h.url);
				var current = live && i === hits.length - 1;
				html += '<li class="' + (current ? 'pqanalytics-current' : '') + '">' + (current ? '<span class="pqanalytics-arrow-now" title="' + T.current + '">&gt;</span> ' : '') + '<span class="pqanalytics-muted">' + esc(h.time) + '</span> <a href="' + esc(h.url) + '" target="_blank" rel="noopener">' + esc(p) + '</a>' +
					(h.title ? ' <span class="pqanalytics-muted">' + esc(h.title) + '</span>' : '') +
					(current ? '' : ' <span class="pqanalytics-muted">' + h.duration + T.seconds + '</span>') + '</li>';
			});
			return html + '</ol>';
		}

		function sub(s, a, b) {
			return String(s).replace('%1$s', a).replace('%2$s', b).replace('%s', a);
		}

		function spent(seconds) {
			seconds = parseInt(seconds, 10) || 0;
			if (seconds < 60) {
				return seconds + T.seconds;
			}
			return Math.round(seconds / 60) + ' ' + T.minutes;
		}

		function historyHtml(d, currentId) {
			var html = '<div class="pqanalytics-history">';
			html += '<p class="pqanalytics-history-head"><strong>' + esc(sub(T.visitsSince, fmt(d.total), d.first_seen)) + '</strong>';
			if (d.kept < d.total) {
				html += ' <span class="pqanalytics-muted">' + esc(sub(T.keptNote, fmt(d.days), fmt(d.kept))) + '</span>';
			}
			html += '</p>';
			if (d.pages && d.pages.length) {
				html += '<div class="pqanalytics-history-block"><div class="pqanalytics-history-title">' + esc(T.topPages) + '</div><ol class="pqanalytics-top-pages">';
				d.pages.forEach(function (p) {
					var label = pathLabel(pathOf(p.url || p.path, true), p.url || p.path);
					html += '<li><a href="' + esc(p.url) + '" target="_blank" rel="noopener">' + esc(label) + '</a>' +
						(p.title ? ' <span class="pqanalytics-muted">' + esc(p.title) + '</span>' : '') +
						' <span class="pqanalytics-history-count">' + esc(sub(T.inVisits, fmt(p.visits), fmt(d.kept))) + ', ' + fmt(p.views) + ' ' + (p.views === 1 ? T.view : T.views) + ', ' + esc(spent(p.seconds)) + ' ' + T.totalTime + '</span></li>';
				});
				html += '</ol></div>';
			}
			html += '<div class="pqanalytics-history-block"><div class="pqanalytics-history-title">' + esc(T.eachVisit) + '</div>';
			d.visits.forEach(function (v) {
				var current = v.id === currentId;
				html += '<div class="pqanalytics-history-visit' + (current ? ' pqanalytics-history-current' : '') + '">';
				html += '<div class="pqanalytics-history-when"><strong>' + esc(v.date) + ', ' + esc(v.time) + '</strong> <span class="pqanalytics-muted">' + T.visit + ' #' + v.visit_no + (current ? ' (' + T.thisVisit + ')' : '') + ', ' + esc(spent(v.seconds)) + ', ' + fmt(v.pages) + ' ' + (v.pages === 1 ? T.page : T.pages) +
					(v.source ? ', ' + T.from + ' ' + esc(v.source) : '') + (v.keyword ? ' (' + esc(v.keyword) + ')' : '') + (v.campaign ? ', ' + T.campaign + ' ' + esc(v.campaign) : '') +
					(v.location ? ', ' + esc(v.location) : '') + (v.screen ? ', ' + esc(v.screen) : '') + (v.browser ? ', ' + esc(v.browser) : '') + (v.os ? ', ' + esc(v.os) : '') + '</span></div>';
				if (v.hits.length) {
					html += '<ol class="pqanalytics-path-list">';
					v.hits.forEach(function (h) {
						var p = pathLabel(pathOf(h.url, true), h.url);
						html += '<li><span class="pqanalytics-muted">' + esc(h.time) + '</span> <a href="' + esc(h.url) + '" target="_blank" rel="noopener">' + esc(p) + '</a>' +
							(h.title ? ' <span class="pqanalytics-muted">' + esc(h.title) + '</span>' : '') +
							(h.duration ? ' <span class="pqanalytics-muted">' + h.duration + T.seconds + '</span>' : '') + '</li>';
					});
					html += '</ol>';
					if (v.more > 0) {
						html += '<div class="pqanalytics-muted">' + esc(sub(T.morePages, fmt(v.more))) + '</div>';
					}
				}
				html += '</div>';
			});
			html += '</div></div>';
			return html;
		}

		document.addEventListener('click', function (e) {
			var t = e.target;
			if (!t.closest) {
				return;
			}
			var histBtn = t.closest('.pqanalytics-history');
			if (histBtn) {
				var hid = histBtn.getAttribute('data-id');
				var hvid = histBtn.getAttribute('data-vid');
				var hrow = qs('.pqanalytics-history-row[data-for="' + hid + '"]');
				if (hrow) {
					hrow.parentNode.removeChild(hrow);
					delete state.history[hid];
					histBtn.textContent = T.pastVisits;
					return;
				}
				histBtn.disabled = true;
				api('visitor/' + hvid + '/history').then(function (d) {
					histBtn.disabled = false;
					var row = histBtn.closest('tr');
					var html = historyHtml(d, parseInt(hid, 10));
					state.history[hid] = html;
					histBtn.textContent = T.hidePast;
					var tr = document.createElement('tr');
					tr.className = 'pqanalytics-history-row';
					tr.setAttribute('data-for', hid);
					tr.innerHTML = '<td colspan="10">' + html + '</td>';
					var after = qs('.pqanalytics-path-row[data-for="' + hid + '"]') || row;
					after.parentNode.insertBefore(tr, after.nextSibling);
				}).catch(function (err) { histBtn.disabled = false; window.alert(err.message || T.error); });
				return;
			}
			var filter = t.closest('.pqanalytics-filter');
			if (filter) {
				qsa('.pqanalytics-filter').forEach(function (b) { b.classList.remove('is-active'); });
				filter.classList.add('is-active');
				state.filter = filter.getAttribute('data-filter');
				state.page = 1;
				loadOverview();
				return;
			}
			var pageBtn = t.closest('#pqanalytics-pager [data-page]');
			if (pageBtn) {
				state.page = parseInt(pageBtn.getAttribute('data-page'), 10) || 1;
				loadOverview();
				return;
			}
			var pathBtn = t.closest('.pqanalytics-path');
			if (pathBtn) {
				var id = pathBtn.getAttribute('data-id');
				var existing = qs('.pqanalytics-path-row[data-for="' + id + '"]');
				if (existing) {
					existing.parentNode.removeChild(existing);
					delete state.expanded[id];
					pathBtn.textContent = T.viewAll;
					return;
				}
				api('visit/' + id + '/path').then(function (d) {
					var row = pathBtn.closest('tr');
					var html = pathHtml(d.hits, row.classList.contains('pqanalytics-live'));
					state.expanded[id] = html;
					pathBtn.textContent = T.hideAll;
					var tr = document.createElement('tr');
					tr.className = 'pqanalytics-path-row';
					tr.setAttribute('data-for', id);
					tr.innerHTML = '<td colspan="10">' + html + '</td>';
					row.parentNode.insertBefore(tr, row.nextSibling);
				}).catch(function (err) { window.alert(err.message || T.error); });
				return;
			}
			var block = t.closest('.pqanalytics-block');
			if (block) {
				var ip = block.getAttribute('data-ip');
				if (!window.confirm(T.confirm + '\n\n' + ip)) {
					return;
				}
				block.disabled = true;
				api('block', 'POST', { ip: ip, note: '' }).then(function () {
					loadOverview();
				}).catch(function (err) {
					block.disabled = false;
					window.alert(err.message || T.error);
				});
				return;
			}
			var unblock = t.closest('.pqanalytics-unblock');
			if (unblock) {
				unblock.disabled = true;
				api('unblock', 'POST', { ip: unblock.getAttribute('data-ip') }).then(function () {
					loadOverview();
				}).catch(function (err) {
					unblock.disabled = false;
					window.alert(err.message || T.error);
				});
			}
		});

		var search = qs('#pqanalytics-search');
		var debounce = null;
		function reload() {
			clearTimeout(debounce);
			if (state.busy) {
				debounce = setTimeout(reload, 300);
				return;
			}
			loadOverview();
		}
		function typed(el, key) {
			if (!el) {
				return;
			}
			el.addEventListener('input', function () {
				state[key] = el.value;
				state.page = 1;
				clearTimeout(debounce);
				debounce = setTimeout(reload, 400);
			});
		}
		function picked(el, key, read) {
			if (!el) {
				return;
			}
			el.addEventListener('change', function () {
				state[key] = read ? read(el) : el.value;
				state.page = 1;
				reload();
			});
		}
		typed(search, 'search');
		typed(qs('#pqanalytics-page1'), 'page1');
		typed(qs('#pqanalytics-page2'), 'page2');
		picked(qs('#pqanalytics-in'), 'in');
		picked(qs('#pqanalytics-unique'), 'unique', function (el) { return el.checked ? 1 : 0; });
		picked(qs('#pqanalytics-from'), 'from');
		picked(qs('#pqanalytics-to'), 'to');
		picked(qs('#pqanalytics-tfrom'), 'tfrom');
		picked(qs('#pqanalytics-tto'), 'tto');
		var clear = qs('#pqanalytics-clear');
		if (clear) {
			clear.addEventListener('click', function () {
				['search', 'page1', 'page2', 'from', 'to', 'tfrom', 'tto'].forEach(function (key) {
					state[key] = '';
					var el = qs('#pqanalytics-' + (key === 'search' ? 'search' : key));
					if (el) {
						el.value = '';
					}
				});
				state.in = 'all';
				state.unique = 0;
				var sel = qs('#pqanalytics-in'), un = qs('#pqanalytics-unique');
				if (sel) { sel.value = 'all'; }
				if (un) { un.checked = false; }
				state.page = 1;
				reload();
			});
		}

		function schedule() {
			clearInterval(state.timer);
			state.timer = setInterval(function () {
				if (document.visibilityState === 'visible') {
					loadOverview();
				}
			}, A.refresh || 15000);
		}
		document.addEventListener('visibilitychange', function () {
			if (document.visibilityState === 'visible') {
				loadOverview();
			}
		});
		loadOverview();
		schedule();
	}

	if (A.screen === 'flow') {
		var F = { range: '7', from: '', busy: false };

		function secs(s) {
			s = parseInt(s, 10) || 0;
			if (s < 60) {
				return s + ' s';
			}
			var m = Math.floor(s / 60), r = s % 60;
			return m + ' ' + T.minSec + (r ? ' ' + r + ' s' : '');
		}
		function pageLink(path) {
			return '<code>' + esc(path) + '</code>';
		}
		function fill(sel, html, cols) {
			var body = qs(sel + ' tbody');
			if (body) {
				body.innerHTML = html || '<tr><td colspan="' + cols + '" class="pqanalytics-empty">' + T.noFlow + '</td></tr>';
			}
		}

		function renderFlow(d) {
			var c = d.cards;
			qs('[data-flow="visits"]').textContent = fmt(c.visits);
			qs('[data-flow="views"]').textContent = fmt(c.views);
			qs('[data-flow="per"]').textContent = c.per;
			qs('[data-flow="single"]').textContent = c.single + '%';
			qs('[data-flow="seconds"]').textContent = secs(c.seconds);

			var total = c.visits || 0, html = '', em = maxOf(d.entries, 'n'), xm = maxOf(d.exits, 'n');
			d.entries.forEach(function (r) {
				html += '<tr><td>' + pageLink(r.path) + bar(r.n, em) + '</td><td>' + fmt(r.n) + '</td><td>' + pct(r.n, total) + '</td></tr>';
			});
			fill('#pqanalytics-entries', html, 3);

			html = '';
			d.exits.forEach(function (r) {
				html += '<tr><td>' + pageLink(r.path) + bar(r.n, xm) + '</td><td>' + fmt(r.n) + '</td><td>' + pct(r.n, total) + '</td></tr>';
			});
			fill('#pqanalytics-exits', html, 3);

			var select = qs('#pqanalytics-from');
			var current = select.value;
			while (select.options.length > 1) {
				select.remove(1);
			}
			d.pages.forEach(function (r) {
				var o = document.createElement('option');
				o.value = r.path;
				o.textContent = r.path + (r.title ? ' — ' + r.title.substring(0, 50) : '');
				select.appendChild(o);
			});
			select.value = d.from || '';
			if (select.value !== (d.from || '')) {
				select.value = '';
			}

			var nextTitle = qs('#pqanalytics-next-title');
			var beforeBox = qs('#pqanalytics-before-box');
			var leftNote = qs('#pqanalytics-left');
			html = '';
			if (d.from) {
				var sum = 0;
				d.next.forEach(function (r) { sum += parseInt(r.n, 10); });
				sum += d.left;
				nextTitle.textContent = T.nextFrom;
				var nm = maxOf(d.next, 'n');
				d.next.forEach(function (r) {
					html += '<tr><td>' + pageLink(d.from) + '</td><td>' + pageLink(r.to_path) + bar(r.n, nm) + '</td><td>' + fmt(r.n) + '</td><td>' + pct(r.n, sum) + '</td></tr>';
				});
				fill('#pqanalytics-next', html, 4);
				leftNote.textContent = fmt(d.left) + (sum ? ' (' + pct(d.left, sum) + ')' : '') + ' ' + T.leftFrom + ' ' + d.from + '.';
				var bsum = 0;
				d.before.forEach(function (r) { bsum += parseInt(r.n, 10); });
				var bhtml = '', bm = maxOf(d.before, 'n');
				d.before.forEach(function (r) {
					bhtml += '<tr><td>' + pageLink(r.from_path) + bar(r.n, bm) + '</td><td>' + fmt(r.n) + '</td><td>' + pct(r.n, bsum) + '</td></tr>';
				});
				qs('#pqanalytics-before-title').textContent = T.beforeTo;
				fill('#pqanalytics-before', bhtml, 3);
				beforeBox.hidden = false;
			} else {
				nextTitle.textContent = T.allPages;
				var all = 0, am = maxOf(d.next, 'n');
				d.next.forEach(function (r) { all += parseInt(r.n, 10); });
				d.next.forEach(function (r) {
					html += '<tr><td>' + pageLink(r.from_path) + '</td><td>' + pageLink(r.to_path) + bar(r.n, am) + '</td><td>' + fmt(r.n) + '</td><td>' + pct(r.n, all) + '</td></tr>';
				});
				fill('#pqanalytics-next', html, 4);
				leftNote.textContent = '';
				beforeBox.hidden = true;
			}

			html = '';
			d.common.forEach(function (r) {
				html += '<tr><td>' + r.path.split(' > ').map(pageLink).join(' <span class="pqanalytics-arrow">&rsaquo;</span> ') + '</td><td>' + fmt(r.n) + '</td></tr>';
			});
			fill('#pqanalytics-common', html, 2);
			qs('#pqanalytics-capped').textContent = d.capped ? T.capped : '';

			html = '';
			var vm = maxOf(d.pages, 'views');
			d.pages.forEach(function (r) {
				html += '<tr><td>' + pageLink(r.path) + (r.title ? '<div class="pqanalytics-muted">' + esc(r.title.substring(0, 80)) + '</div>' : '') + bar(r.views, vm) + '</td><td>' + fmt(r.views) + '</td><td>' + fmt(r.visits) + '</td><td>' + secs(r.time) + '</td><td>' + fmt(r.entries) + '</td><td>' + fmt(r.exits) + '</td></tr>';
			});
			fill('#pqanalytics-pages', html, 6);
		}

		function loadFlow() {
			if (F.busy) {
				return;
			}
			F.busy = true;
			var status = qs('#pqanalytics-flow-status');
			status.textContent = '…';
			api('flow?range=' + encodeURIComponent(F.range) + '&from=' + encodeURIComponent(F.from)).then(function (d) {
				renderFlow(d);
				status.textContent = '';
			}).catch(function (err) {
				status.textContent = err.message || T.error;
			}).then(function () {
				F.busy = false;
			});
		}

		document.addEventListener('click', function (e) {
			var b = e.target.closest && e.target.closest('.pqanalytics-range');
			if (!b) {
				return;
			}
			qsa('.pqanalytics-range').forEach(function (x) { x.classList.remove('is-active'); });
			b.classList.add('is-active');
			F.range = b.getAttribute('data-range');
			loadFlow();
		});
		qs('#pqanalytics-from').addEventListener('change', function () {
			F.from = this.value;
			loadFlow();
		});
		loadFlow();
	}

	if (A.screen === 'settings') {

		var sample = qs('#pqanalytics-report-sample');
		if (sample) {
			sample.addEventListener('click', function () {
				var msg = qs('#pqanalytics-report-msg');
				var to = qs('#pqanalytics-report-to') ? qs('#pqanalytics-report-to').value : '';
				var kind = qs('#pqanalytics-report') ? qs('#pqanalytics-report').value : 'daily';
				sample.disabled = true;
				msg.className = 'pqanalytics-muted';
				msg.textContent = T.sending + '…';
				api('report/sample', 'POST', { to: to, report: kind }).then(function (d) {
					msg.textContent = T.sampleSent.replace('%s', d.to);
				}).catch(function (err) {
					msg.className = 'pqanalytics-error';
					msg.textContent = err.message || T.error;
				}).then(function () {
					sample.disabled = false;
				});
			});
		}

		function geoRow(kind) {
			return qs('.pqanalytics-geo-table tr[data-kind="' + kind + '"]');
		}

		function applyStatus(status) {
			status.forEach(function (s) {
				var row = geoRow(s.kind);
				if (!row) {
					return;
				}
				var btn = qs('.pqanalytics-geo-download', row);
				var cell = qs('.pqanalytics-geo-status', row);
				if (s.installed) {
					cell.textContent = T.installed;
					btn.textContent = T.update;
				} else {
					cell.textContent = T.notInstalled;
					btn.textContent = T.download;
				}
				if (s.error) {
					cell.innerHTML += ' <span class="pqanalytics-error">' + esc(s.error) + '</span>';
				}
				btn.disabled = false;
			});
		}

		function runDownload(kind, btn) {
			var row = geoRow(kind);
			var prog = qs('.pqanalytics-geo-progress', row);
			var cell = qs('.pqanalytics-geo-status', row);
			btn.disabled = true;
			prog.textContent = T.downloading + '…';
			cell.textContent = '';

			function step() {
				api('geo/step', 'POST', { kind: kind }).then(function (d) {
					if (d.error) {
						prog.innerHTML = '<span class="pqanalytics-error">' + esc(d.error) + '</span> <button type="button" class="button button-small pqanalytics-geo-retry" data-kind="' + esc(kind) + '">' + T.retry + '</button>';
						btn.disabled = false;
						return;
					}
					if (d.done) {
						prog.textContent = '';
						applyStatus(d.status);
						btn.disabled = false;
						return;
					}
					var mb = function (b) { return (b / 1048576).toFixed(0); };
					prog.textContent = T.downloading + ' ' + mb(d.received) + (d.total ? ' / ' + mb(d.total) : '') + ' MB' + (d.total ? ' (' + Math.min(99, Math.round(d.received / d.total * 100)) + '%)' : '');
					if (d.total && d.received >= d.total) {
						prog.textContent = T.extracting;
					}
					step();
				}).catch(function (err) {
					prog.innerHTML = '<span class="pqanalytics-error">' + esc(err.message || T.error) + '</span> <button type="button" class="button button-small pqanalytics-geo-retry" data-kind="' + esc(kind) + '">' + T.retry + '</button>';
					btn.disabled = false;
				});
			}
			step();
		}

		document.addEventListener('click', function (e) {
			var t = e.target;
			if (!t.closest) {
				return;
			}
			var dl = t.closest('.pqanalytics-geo-download');
			if (dl) {
				runDownload(dl.getAttribute('data-kind'), dl);
				return;
			}
			var retry = t.closest('.pqanalytics-geo-retry');
			if (retry) {
				var kind = retry.getAttribute('data-kind');
				var row = geoRow(kind);
				api('geo/reset', 'POST', { kind: kind }).then(function () {
					runDownload(kind, qs('.pqanalytics-geo-download', row));
				});
				return;
			}
			if (t.id === 'pqanalytics-add-my-ip') {
				var ta = qs('#pqanalytics-own-ips');
				var ip = t.getAttribute('data-ip');
				if (ta && ip && ta.value.indexOf(ip) === -1) {
					ta.value = (ta.value.trim() ? ta.value.trim().replace(/,\s*$/, '') + ', ' : '') + ip;
				}
				return;
			}
			if (t.id === 'pqanalytics-block-add') {
				var input = qs('#pqanalytics-block-ip');
				var msg = qs('#pqanalytics-block-msg');
				var addr = input.value.trim();
				if (!addr) {
					return;
				}
				t.disabled = true;
				api('block', 'POST', { ip: addr, note: 'Added in Settings' }).then(function (d) {
					input.value = '';
					msg.textContent = '';
					renderBlocked(d.blocked);
				}).catch(function (err) {
					msg.textContent = err.message || T.error;
				}).then(function () { t.disabled = false; });
				return;
			}
			var unblock = t.closest('.pqanalytics-unblock');
			if (unblock) {
				unblock.disabled = true;
				api('unblock', 'POST', { ip: unblock.getAttribute('data-ip') }).then(function (d) {
					renderBlocked(d.blocked);
				}).catch(function (err) {
					unblock.disabled = false;
					window.alert(err.message || T.error);
				});
			}
		});

		function renderBlocked(list) {
			var body = qs('#pqanalytics-blocked-table tbody');
			var ips = Object.keys(list || {});
			if (!ips.length) {
				body.innerHTML = '<tr class="pqanalytics-empty"><td colspan="3">–</td></tr>';
				return;
			}
			var html = '';
			ips.forEach(function (ip) {
				html += '<tr data-ip="' + esc(ip) + '"><td><code>' + esc(ip) + '</code></td><td>' + esc(list[ip]) + '</td><td><button type="button" class="button button-small pqanalytics-unblock" data-ip="' + esc(ip) + '">' + T.unblock + '</button></td></tr>';
			});
			body.innerHTML = html;
		}
	}
})();
