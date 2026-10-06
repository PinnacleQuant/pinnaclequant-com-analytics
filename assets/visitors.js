(function () {
	'use strict';

	var cfg = window.PQANALYTICS_CFG;
	if (!cfg || !cfg.hit || !window.fetch || !window.JSON) {
		return;
	}
	window.PQANALYTICS_ACTIVE = true;
	window.PQANALYTICS_BEAT_MS = cfg.interval || 20000;

	function extra(kind) {
		var detail = { kind: kind, data: {} };
		try {
			document.dispatchEvent(new CustomEvent('pqanalytics:before', { detail: detail }));
		} catch (e) {}
		return detail.data;
	}

	function after(kind, d) {
		try {
			document.dispatchEvent(new CustomEvent('pqanalytics:after', { detail: { kind: kind, data: d || {} } }));
		} catch (e) {}
	}

	function hasKeys(o) {
		for (var k in o) {
			if (Object.prototype.hasOwnProperty.call(o, k)) {
				return true;
			}
		}
		return false;
	}

	function getCookie(name) {
		var parts = document.cookie.split(';');
		for (var i = 0; i < parts.length; i++) {
			var p = parts[i].replace(/^\s+/, '');
			if (p.indexOf(name + '=') === 0) {
				return p.substring(name.length + 1);
			}
		}
		return '';
	}

	function setCookie(name, value, days) {
		var d = new Date();
		d.setTime(d.getTime() + days * 864e5);
		var s = name + '=' + value + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
		if (location.protocol === 'https:') {
			s += '; Secure';
		}
		document.cookie = s;
	}

	function randomId() {
		var hex = '0123456789abcdef', out = '';
		if (window.crypto && crypto.getRandomValues) {
			var buf = new Uint8Array(16);
			crypto.getRandomValues(buf);
			for (var i = 0; i < 16; i++) {
				out += hex[buf[i] >> 4] + hex[buf[i] & 15];
			}
			return out;
		}
		for (var j = 0; j < 32; j++) {
			out += hex[Math.floor(Math.random() * 16)];
		}
		return out;
	}

	var vid = getCookie('pqanalytics_vid');
	if (!/^[a-f0-9]{32}$/.test(vid)) {
		vid = getCookie('pqa_vid');
	}
	if (!/^[a-f0-9]{32}$/.test(vid)) {
		vid = randomId();
	}
	setCookie('pqanalytics_vid', vid, 730);

	var payload = {
		vid: vid,
		url: location.href,
		ref: document.referrer || '',
		t: (document.title || '').substring(0, 255),
		sw: screen.width || 0,
		sh: screen.height || 0,
		dpr: window.devicePixelRatio || 1,
		vw: window.innerWidth || 0,
		vh: window.innerHeight || 0,
		lang: navigator.language || '',
		m: (window.PQANALYTICS_CFG && PQANALYTICS_CFG.m) || ''
	};

	function post(url, body, keepalive) {
		return fetch(url, {
			method: 'POST',
			headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
			body: body,
			credentials: 'omit',
			keepalive: !!keepalive
		});
	}

	function postAjax(action, body) {
		var url = cfg.ajax + (cfg.ajax.indexOf('?') === -1 ? '?' : '&') + 'action=' + action;
		return post(url, body, true);
	}

	function sendHit() {
		var x = extra('hit');
		payload.x = hasKeys(x) ? x : undefined;
		var body = JSON.stringify(payload);
		return post(cfg.hit, body).then(function (r) {
			if (!r.ok) {
				throw new Error('rest');
			}
			return r.json();
		}).then(function (d) {
			return { d: d, ajax: false };
		}).catch(function () {
			if (!cfg.ajax) {
				return null;
			}
			return postAjax('pqanalytics_hit', body).then(function (r) {
				return r.ok ? r.json() : null;
			}).then(function (d) {
				return { d: d, ajax: true };
			});
		});
	}

	var beatBody = '';
	var beatAjax = false;
	var last = 0;
	var lastSignal = 0;
	var started = false;
	var sessionMs = cfg.session || 1800000;

	function expired(d) {
		if (d && d.expired) {
			beatBody = '';
			track();
			return true;
		}
		return false;
	}

	function beat() {
		if (!beatBody || document.visibilityState !== 'visible') {
			return;
		}
		var now = Date.now();
		if (now - last < 5000) {
			return;
		}
		if (lastSignal && now - lastSignal > sessionMs) {
			beatBody = '';
			track();
			return;
		}
		last = now;
		lastSignal = now;
		var x = extra('beat');
		var body = beatBody;
		if (hasKeys(x)) {
			var parsed = JSON.parse(beatBody);
			parsed.x = x;
			body = JSON.stringify(parsed);
		}
		try {
			if (beatAjax) {
				postAjax('pqanalytics_beat', body).then(function (r) {
					return r.ok ? r.json() : null;
				}).then(function (d) {
					if (!expired(d)) {
						after('beat', d);
					}
				}).catch(function () {});
			} else if (navigator.sendBeacon && !hasKeys(x)) {
				navigator.sendBeacon(cfg.beat, new Blob([beatBody], { type: 'text/plain;charset=UTF-8' }));
			} else {
				post(cfg.beat, body, true).then(function (r) {
					return r.ok ? r.json() : null;
				}).then(function (d) {
					if (!expired(d)) {
						after('beat', d);
					}
				}).catch(function () {});
			}
		} catch (e) {}
	}

	function startHeartbeat() {
		if (started) {
			return;
		}
		started = true;
		setInterval(beat, cfg.interval || 20000);
		document.addEventListener('visibilitychange', function () {
			if (document.visibilityState === 'visible') {
				beat();
			}
		});
		window.addEventListener('pagehide', function () {
			last = 0;
			if (!beatBody) {
				return;
			}
			try {
				if (!beatAjax && navigator.sendBeacon) {
					navigator.sendBeacon(cfg.beat, new Blob([beatBody], { type: 'text/plain;charset=UTF-8' }));
				} else {
					beat();
				}
			} catch (e) {}
		});
	}

	function track() {
		payload.url = location.href;
		payload.t = (document.title || '').substring(0, 255);
		sendHit().then(function (res) {
			if (res && res.d) {
				after('hit', res.d);
			}
			if (res && res.d && res.d.v && res.d.h) {
				beatBody = JSON.stringify({ vid: vid, v: res.d.v, h: res.d.h });
				beatAjax = res.ajax;
				last = 0;
				lastSignal = Date.now();
				startHeartbeat();
			}
		}).catch(function () {});
	}

	var lastPath = location.pathname + location.search;
	function changed() {
		var now = location.pathname + location.search;
		if (now === lastPath) {
			return;
		}
		lastPath = now;
		payload.ref = '';
		setTimeout(track, 150);
	}
	if (window.history && history.pushState) {
		var push = history.pushState, replace = history.replaceState;
		history.pushState = function () {
			var r = push.apply(this, arguments);
			changed();
			return r;
		};
		history.replaceState = function () {
			var r = replace.apply(this, arguments);
			changed();
			return r;
		};
		window.addEventListener('popstate', changed);
	}

	function start() {
		window.PQANALYTICS_HIT_SENT = true;
		track();
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		setTimeout(start, 0);
	}
})();
