/*!
 * Visitor Insights for Grav: cookie-free counting, taps, scroll depth and tap map.
 * Sets no cookies and stores nothing on the visitor's device, except an opt-out
 * flag the site owner can set on their own browser.
 */
(function () {
  'use strict';
  var cfg = window.VisitorInsights || {};
  var nav = navigator;
  var loc = location;

  // ---- who not to count ---------------------------------------------------
  function optedOut() {
    var p = cfg.ignore || 'insights-ignore';
    try {
      if (new RegExp('[?&]' + p + '=1').test(loc.search)) localStorage.setItem(p, '1');
      if (new RegExp('[?&]' + p + '=0').test(loc.search)) localStorage.removeItem(p);
      if (localStorage.getItem(p) === '1') return true;
    } catch (e) {}
    return false;
  }
  if (
    nav.webdriver ||                                   // browser driven by automation
    /[?&]insights-preview=1/.test(loc.search) ||       // the admin's tap map preview
    (cfg.gpc !== false && nav.globalPrivacyControl === true) ||
    (cfg.dnt && (nav.doNotTrack === '1' || window.doNotTrack === '1')) ||
    optedOut()
  ) return;

  var endpoint = cfg.hit || '/visitor-insights/hit';
  // fetch with keepalive survives the page closing and, in testing, was more
  // reliable than sendBeacon when sent while leaving a page. sendBeacon is the
  // fallback for browsers without keepalive.
  var keepalive = typeof Request !== 'undefined' && 'keepalive' in Request.prototype;
  function send(data) {
    var body = JSON.stringify(data);
    if (keepalive) {
      try {
        fetch(endpoint, { method: 'POST', body: body, keepalive: true, credentials: 'omit', headers: { 'Content-Type': 'text/plain' } });
        return;
      } catch (e) {}
    }
    try { if (nav.sendBeacon) nav.sendBeacon(endpoint, new Blob([body], { type: 'text/plain' })); } catch (e) {}
  }

  var path = loc.pathname;
  var device = /iPad|Tablet/i.test(nav.userAgent) ? 'tablet'
    : (/Mobi|Android|iPhone|iPod/i.test(nav.userAgent) || innerWidth < 768) ? 'phone' : 'computer';

  // ---- count a view only once the visitor has engaged ---------------------
  var counted = false;
  var queue = [];
  function countView() {
    if (counted) return;
    counted = true;
    var utm = (loc.search.match(/[?&]utm_source=([^&]+)/) || [])[1] || '';
    try { utm = decodeURIComponent(utm); } catch (e) {}
    send({ t: 'view', p: path, r: document.referrer, u: utm, w: innerWidth });
    for (var i = 0; i < queue.length; i++) send(queue[i]);
    queue = [];
  }
  var visibleFor = 0, tick = null, need = Math.max(0, cfg.engage == null ? 2 : cfg.engage) * 1000;
  function startClock() {
    if (tick || counted) return;
    tick = setInterval(function () {
      if (document.visibilityState !== 'visible') return;
      visibleFor += 250;
      if (visibleFor >= need) { clearInterval(tick); countView(); }
    }, 250);
  }
  if (need === 0) countView(); else startClock();
  ['pointerdown', 'keydown', 'scroll'].forEach(function (type) {
    addEventListener(type, function once() { countView(); removeEventListener(type, once, { passive: true }); }, { passive: true });
  });

  // ---- taps that matter ----------------------------------------------------
  function kind(a) {
    var tag = a.getAttribute('data-insights');
    if (tag) return tag;
    var h = (a.getAttribute('href') || '').toLowerCase();
    if (h.indexOf('tel:') === 0) return 'call';
    if (h.indexOf('sms:') === 0) return 'sms';
    if (h.indexOf('mailto:') === 0) return 'email';
    if (/wa\.me|whatsapp\.com/.test(h)) return 'whatsapp';
    if (/google\.[a-z.]+\/maps|maps\.google|maps\.apple|goo\.gl\/maps|waze\.com/.test(h)) return 'directions';
    if (/instagram\.com|facebook\.com|tiktok\.com|linkedin\.com|x\.com|twitter\.com|youtube\.com/.test(h)) return 'social';
    if (/\.(pdf|docx?|xlsx?|zip)(\?|$)/.test(h)) return 'download';
    return null;
  }
  function event(name) {
    var data = { t: 'event', e: name };
    if (counted) send(data); else { queue.push(data); countView(); }
  }

  // ---- scroll depth and tap map --------------------------------------------
  var heat = cfg.heatmaps !== false;
  var sections = [], reached = {}, taps = [], maxDepth = 0;

  function sectionKey(el, i) {
    return (el.getAttribute('data-insights-section') || el.id || (el.tagName.toLowerCase() + '-' + i)).replace(/[^a-z0-9_.-]/gi, '').slice(0, 40);
  }
  function sectionLabel(el, key) {
    var l = el.getAttribute('data-insights-label') || el.getAttribute('aria-label');
    if (!l) { var h = el.querySelector('h1,h2,h3'); l = h ? h.textContent : ''; }
    l = (l || key).replace(/\s+/g, ' ').trim();
    return l.slice(0, 60);
  }
  function collectSections() {
    var found;
    try { found = document.querySelectorAll(cfg.sections || 'header, section, footer, [data-insights-section]'); } catch (e) { found = []; }
    var list = [];
    for (var i = 0; i < found.length; i++) {
      var el = found[i], nested = false;
      for (var j = 0; j < list.length; j++) if (list[j].el.contains(el)) { nested = true; break; }
      if (nested || el.offsetHeight < 40) continue;
      list.push({ el: el });
    }
    list.sort(function (a, b) { return a.el.getBoundingClientRect().top - b.el.getBoundingClientRect().top; });
    for (var k = 0; k < list.length && k < 30; k++) {
      list[k].key = sectionKey(list[k].el, k);
      list[k].label = sectionLabel(list[k].el, list[k].key);
    }
    return list.slice(0, 30);
  }
  function measure() {
    var doc = document.documentElement;
    var bottom = scrollY + innerHeight;
    var height = Math.max(doc.scrollHeight, document.body ? document.body.scrollHeight : 0) || 1;
    maxDepth = Math.max(maxDepth, Math.min(100, Math.round(bottom / height * 100)));
    for (var i = 0; i < sections.length; i++) {
      var r = sections[i].el.getBoundingClientRect();
      // reached = at least a third of it, or 200px, has been on screen
      var seen = Math.min(r.bottom, innerHeight) - Math.max(r.top, 0);
      if (seen > Math.min(200, r.height / 3)) reached[sections[i].key] = 1;
    }
  }

  if (heat) {
    var ready = function () { sections = collectSections(); measure(); };
    if (document.readyState === 'loading') addEventListener('DOMContentLoaded', ready); else ready();
    var pending = false;
    addEventListener('scroll', function () {
      if (pending) return;
      pending = true;
      requestAnimationFrame(function () { pending = false; measure(); });
    }, { passive: true });
  }

  addEventListener('click', function (e) {
    var t = e.target;
    var a = t && t.closest ? t.closest('a') : null;
    if (a) { var k = kind(a); if (k) event(k); }
    if (!heat || taps.length >= 30) return;
    for (var i = 0; i < sections.length; i++) {
      var el = sections[i].el;
      if (el.contains(t)) {
        var r = el.getBoundingClientRect();
        if (r.width > 0 && r.height > 0) {
          taps.push([sections[i].key, +((e.clientX - r.left) / r.width).toFixed(3), +((e.clientY - r.top) / r.height).toFixed(3)]);
        }
        break;
      }
    }
  }, true);

  // send the visit's scroll and taps once, when the visitor leaves
  var flushed = false;
  function flush() {
    if (flushed || !counted || !heat || !sections.length) return;
    flushed = true;
    measure();
    send({
      t: 'session', p: path, d: device, m: maxDepth,
      s: sections.map(function (s) { return { key: s.key, label: s.label }; }),
      v: Object.keys(reached), x: taps
    });
  }
  addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') flush(); });
  addEventListener('pagehide', flush);
})();
