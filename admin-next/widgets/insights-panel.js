// Visitor Insights: dashboard panel for Grav Admin2.
(() => {
  const TAG = window.__GRAV_WIDGET_TAG || 'grav-widget-visitor-insights-panel';
  if (customElements.get(TAG)) return;

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = (n) => Number(n || 0).toLocaleString();
  const api = (path) => fetch((window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1') + path, {
    headers: { Accept: 'application/json', 'X-API-Token': window.__GRAV_API_TOKEN || '' },
  }).then((r) => { if (!r.ok) throw new Error(String(r.status)); return r.json(); }).then((j) => j.data ?? j);

  const css = `
    :host{display:block;color:inherit;font:inherit}
    .card{border:1px solid color-mix(in srgb,currentColor 14%,transparent);border-radius:12px;padding:18px}
    .head{display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap}
    h3{margin:0;font-size:15px;font-weight:600}
    .note{font-size:12px;opacity:.6}
    a.more{font-size:12.5px;color:inherit;opacity:.75}
    .kpis{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(118px,1fr));margin-top:14px}
    .kpi{background:color-mix(in srgb,currentColor 4%,transparent);border-radius:10px;padding:12px}
    .kpi b{display:block;font-size:24px;font-weight:650;line-height:1.1;font-variant-numeric:tabular-nums}
    .kpi span{display:block;font-size:12.5px;opacity:.75;margin-top:4px}
    .kpi small{display:block;font-size:11.5px;opacity:.55;margin-top:2px}
    .row{display:grid;gap:18px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));margin-top:18px}
    h4{margin:0 0 8px;font-size:12.5px;font-weight:600;opacity:.8}
    .bars{display:flex;align-items:flex-end;gap:2px;height:84px}
    .bars i{flex:1;background:var(--vi-accent,#4f6b8a);border-radius:2px 2px 0 0;min-height:2px}
    .bars i.zero{opacity:.18}
    .axis{display:flex;justify-content:space-between;font-size:11px;opacity:.5;margin-top:4px}
    ul{list-style:none;margin:0;padding:0}
    li{display:grid;grid-template-columns:1fr auto;gap:8px;font-size:13px;padding:5px 0 7px;position:relative}
    li .fill{position:absolute;left:0;bottom:1px;height:3px;border-radius:2px;background:var(--vi-accent,#4f6b8a);opacity:.55}
    .empty{font-size:13.5px;opacity:.7;margin:12px 0 0;line-height:1.5}
    .err{font-size:13px;color:#b45309;margin-top:10px}
  `;

  class InsightsPanel extends HTMLElement {
    connectedCallback() {
      this.attachShadow({ mode: 'open' });
      this.shadowRoot.innerHTML = `<style>${css}</style><div class="card"><div class="note">…</div></div>`;
      api(this.getAttribute('data-endpoint') || '/insights/summary')
        .then((d) => this.render(d))
        .catch(() => { this.card().innerHTML = '<div class="err">Couldn\'t load the numbers just now.</div>'; });
    }

    card() { return this.shadowRoot.querySelector('.card'); }

    render(d) {
      const L = d.labels || {};
      const m = d.this_month, lm = d.last_month, ev = m.events || {}, lev = lm.events || {};
      const monthName = new Date(d.month + '-01T12:00:00Z').toLocaleDateString(undefined, { month: 'long' });
      const msgs = (ev.whatsapp || 0) + (ev.email || 0) + (ev.sms || 0);
      const lmsgs = (lev.whatsapp || 0) + (lev.email || 0) + (lev.sms || 0);
      const kpi = (n, label, prev) => `<div class="kpi"><b>${fmt(n)}</b><span>${esc(label)}</span><small>${esc(L.LAST_MONTH)}: ${fmt(prev)}</small></div>`;
      const more = `<a class="more" href="${location.pathname.replace(/\/dashboard\/?$|\/$/, '')}/plugin/visitor-insights">${esc(L.OPEN_PAGE)} →</a>`;

      if (!m.views && !lm.views) {
        this.card().innerHTML = `<div class="head"><h3>${esc(L.TITLE)}</h3><span class="note">${esc(L.NOTE)}</span></div><p class="empty">${esc(L.EMPTY)}</p>`;
        return;
      }

      const series = d.last_30_days || [];
      const max = Math.max(1, ...series.map((s) => s.visitors));
      const day = (iso) => new Date(iso + 'T12:00:00Z').toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
      const bars = series.map((s) => `<i class="${s.visitors ? '' : 'zero'}" style="height:${Math.max(2, Math.round(s.visitors / max * 84))}px" title="${esc(day(s.date))}: ${fmt(s.visitors)}"></i>`).join('');

      const list = (obj, limit) => {
        const rows = Object.entries(obj || {}).slice(0, limit);
        if (!rows.length) return `<p class="empty">${esc(L.WAITING)}</p>`;
        const top = Math.max(1, ...rows.map(([, n]) => n));
        return `<ul>${rows.map(([name, n]) => `<li><span>${esc(name)}</span><b>${fmt(n)}</b><span class="fill" style="width:${Math.round(n / top * 100)}%"></span></li>`).join('')}</ul>`;
      };
      const devices = {};
      for (const [k, v] of Object.entries(m.devices || {})) devices[L[k.toUpperCase()] || k] = v;

      this.card().innerHTML = `
        <div class="head"><h3>${esc(L.TITLE)}</h3><span class="note">${esc(L.NOTE)} · ${esc(monthName)} 1–${d.day_of_month}</span></div>
        <div class="kpis">
          ${kpi(m.visitors, L.VISITORS, lm.visitors)}
          ${kpi(m.views, L.VIEWS, lm.views)}
          ${kpi(ev.call || 0, L.TAPS_CALL, lev.call || 0)}
          ${kpi(msgs, L.TAPS_MESSAGE, lmsgs)}
          ${kpi(ev.directions || 0, L.TAPS_DIRECTIONS, lev.directions || 0)}
        </div>
        <div class="row">
          <div><h4>${esc(L.DAILY)}</h4><div class="bars">${bars}</div><div class="axis"><span>${esc(series[0] ? day(series[0].date) : '')}</span><span>${esc(L.TODAY)}</span></div></div>
          <div><h4>${esc(L.SOURCES)}</h4>${list(m.sources, 6)}</div>
          <div><h4>${esc(L.DEVICES)}</h4>${list(devices, 3)}<div style="margin-top:12px">${more}</div></div>
        </div>`;
    }
  }

  customElements.define(TAG, InsightsPanel);
})();
