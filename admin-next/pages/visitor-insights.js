// Visitor Insights: admin page with scroll depth and a tap heatmap for each page.
(() => {
  const TAG = window.__GRAV_PAGE_TAG || 'grav-visitor-insights--page';
  if (customElements.get(TAG)) return;

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const server = () => window.__GRAV_API_SERVER_URL || '';
  const api = (path) => fetch(server() + (window.__GRAV_API_PREFIX || '/api/v1') + path, {
    headers: { Accept: 'application/json', 'X-API-Token': window.__GRAV_API_TOKEN || '' },
  }).then((r) => { if (!r.ok) throw new Error(String(r.status)); return r.json(); }).then((j) => j.data ?? j);

  const WIDTHS = { phone: 390, tablet: 820, computer: 1280 };
  const ENOUGH_TAPS = 150;

  const css = `
    :host{display:block;color:inherit;font:inherit;padding:4px 0 32px}
    .bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:18px}
    select,button{font:inherit;font-size:13px;color:inherit;background:transparent;border:1px solid color-mix(in srgb,currentColor 18%,transparent);border-radius:8px;padding:6px 10px}
    .seg{display:inline-flex;border:1px solid color-mix(in srgb,currentColor 18%,transparent);border-radius:8px;overflow:hidden}
    .seg button{border:0;border-radius:0}
    .seg button[aria-pressed=true]{background:color-mix(in srgb,currentColor 12%,transparent);font-weight:600}
    .grid{display:grid;gap:20px;grid-template-columns:minmax(260px,340px) 1fr;align-items:start}
    @media (max-width:900px){.grid{grid-template-columns:1fr}}
    .card{border:1px solid color-mix(in srgb,currentColor 14%,transparent);border-radius:12px;padding:16px}
    h3{margin:0 0 4px;font-size:15px;font-weight:600}
    .note{font-size:12.5px;opacity:.65;margin:0 0 12px;line-height:1.45}
    .sec{margin:10px 0}
    .sec .top{display:flex;justify-content:space-between;font-size:13px;gap:8px}
    .sec .track{height:8px;border-radius:4px;background:color-mix(in srgb,currentColor 10%,transparent);margin-top:5px;overflow:hidden}
    .sec .track i{display:block;height:100%;background:#4f6b8a;border-radius:4px}
    .frame{position:relative;overflow:hidden;border-radius:10px;border:1px solid color-mix(in srgb,currentColor 14%,transparent);background:#fff;margin:0 auto}
    .frame iframe{position:absolute;top:0;left:0;border:0;transform-origin:0 0;pointer-events:none}
    .legend{display:flex;align-items:center;gap:8px;font-size:12px;opacity:.75;margin:10px 0 0}
    .legend i{display:inline-block;width:120px;height:8px;border-radius:4px;background:linear-gradient(90deg,#3b82f6,#22c55e,#eab308,#ef4444)}
    .empty{font-size:13.5px;opacity:.7;line-height:1.5}
    .warn{font-size:12.5px;background:color-mix(in srgb,#eab308 14%,transparent);border-radius:8px;padding:8px 10px;margin:0 0 12px}
  `;

  class InsightsPage extends HTMLElement {
    connectedCallback() {
      this.state = { month: '', path: '/', device: 'phone', months: [], pages: [] };
      this.attachShadow({ mode: 'open' });
      this.shadowRoot.innerHTML = `<style>${css}</style><div class="bar"></div><div class="grid"><div class="card side"></div><div class="card map"></div></div>`;
      this.loadPages();
    }

    $(sel) { return this.shadowRoot.querySelector(sel); }

    async loadPages(month) {
      try {
        const d = await api('/insights/pages' + (month ? '?month=' + encodeURIComponent(month) : ''));
        this.state.months = d.months.length ? d.months : [d.month];
        this.state.month = d.month;
        this.state.pages = d.pages;
        if (!d.pages.find((p) => p.path === this.state.path)) this.state.path = d.pages[0]?.path || '/';
        this.renderBar();
        this.loadDetail();
      } catch (e) {
        this.$('.side').innerHTML = '<p class="empty">Couldn\'t load visitor insights just now.</p>';
      }
    }

    renderBar() {
      const s = this.state;
      const monthLabel = (m) => new Date(m + '-01T12:00:00Z').toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
      this.$('.bar').innerHTML = `
        <select data-k="month" aria-label="Month">${s.months.map((m) => `<option value="${esc(m)}" ${m === s.month ? 'selected' : ''}>${esc(monthLabel(m))}</option>`).join('')}</select>
        <select data-k="path" aria-label="Page">${(s.pages.length ? s.pages : [{ path: '/', sessions: 0 }]).map((p) => `<option value="${esc(p.path)}" ${p.path === s.path ? 'selected' : ''}>${esc(p.path)} (${p.sessions})</option>`).join('')}</select>
        <span class="seg" role="group" aria-label="Device">${['phone', 'tablet', 'computer'].map((d) => `<button type="button" data-device="${d}" aria-pressed="${d === s.device}">${d[0].toUpperCase() + d.slice(1)}</button>`).join('')}</span>`;
      this.$('.bar').querySelectorAll('select').forEach((el) => el.addEventListener('change', () => {
        if (el.dataset.k === 'month') this.loadPages(el.value);
        else { this.state.path = el.value; this.loadDetail(); }
      }));
      this.$('.bar').querySelectorAll('[data-device]').forEach((b) => b.addEventListener('click', () => {
        this.state.device = b.dataset.device;
        this.renderBar();
        this.loadDetail();
      }));
    }

    async loadDetail() {
      const s = this.state;
      const d = await api(`/insights/page?month=${encodeURIComponent(s.month)}&path=${encodeURIComponent(s.path)}&device=${s.device}`);
      this.detail = d;
      this.renderSide(d);
      this.renderMap(d);
    }

    renderSide(d) {
      if (!d.sessions) {
        this.$('.side').innerHTML = `<h3>How far people scroll</h3><p class="empty">No visits from a ${esc(d.device)} on this page yet this month.</p>`;
        return;
      }
      const secs = d.sections.map((x) => `
        <div class="sec"><div class="top"><span>${esc(x.label)}</span><b>${x.percent}%</b></div><div class="track"><i style="width:${x.percent}%"></i></div></div>`).join('');
      this.$('.side').innerHTML = `
        <h3>How far people scroll</h3>
        <p class="note">${d.sessions} visit${d.sessions === 1 ? '' : 's'} on ${esc(d.device)}. ${d.depth[100] ?? 0}% reached the very bottom of the page.</p>
        ${secs || '<p class="empty">No sections found on this page.</p>'}`;
    }

    renderMap(d) {
      const box = this.$('.map');
      const thin = d.taps_total < ENOUGH_TAPS;
      box.innerHTML = `
        <h3>Where people tap</h3>
        ${thin ? `<p class="warn">Still collecting: ${d.taps_total} tap${d.taps_total === 1 ? '' : 's'} so far. The map gets reliable after about ${ENOUGH_TAPS}.</p>` : `<p class="note">${d.taps_total} taps this month.</p>`}
        <div class="frame"><iframe title="Page preview" sandbox="allow-same-origin allow-scripts"></iframe></div>
        <div class="legend"><span>Fewer</span><i></i><span>More</span></div>`;
      const frame = box.querySelector('.frame');
      const iframe = box.querySelector('iframe');
      const width = WIDTHS[d.device] || 1280;
      const fit = () => {
        const avail = box.clientWidth - 34;
        const scale = Math.min(1, avail / width);
        frame.style.width = Math.round(width * scale) + 'px';
        iframe.style.width = width + 'px';
        iframe.style.transform = `scale(${scale})`;
        return scale;
      };
      iframe.style.height = '800px';
      fit();
      iframe.addEventListener('load', () => {
        const draw = () => {
          const doc = iframe.contentDocument;
          if (!doc) return;
          const h = Math.max(doc.documentElement.scrollHeight, doc.body?.scrollHeight || 0);
          iframe.style.height = h + 'px';
          frame.style.height = Math.ceil(h * fit()) + 'px';
          this.paint(iframe, d);
        };
        draw();
        setTimeout(draw, 800);
        setTimeout(draw, 2500);
      });
      const sep = d.path.includes('?') ? '&' : '?';
      iframe.src = server() + d.path + sep + 'insights-preview=1';
    }

    // Same rules as the site script, so sections line up with what visitors saw.
    sections(win) {
      const doc = win.document;
      const selector = (win.VisitorInsights && win.VisitorInsights.sections) || 'header, section, footer, [data-insights-section]';
      let found = [];
      try { found = Array.from(doc.querySelectorAll(selector)); } catch (e) {}
      const list = [];
      for (const el of found) {
        if (list.some((x) => x.el.contains(el)) || el.offsetHeight < 40) continue;
        list.push({ el });
      }
      list.sort((a, b) => a.el.getBoundingClientRect().top - b.el.getBoundingClientRect().top);
      return list.slice(0, 30).map((x, i) => ({
        el: x.el,
        key: (x.el.getAttribute('data-insights-section') || x.el.id || x.el.tagName.toLowerCase() + '-' + i).replace(/[^a-z0-9_.-]/gi, '').slice(0, 40),
      }));
    }

    paint(iframe, d) {
      const win = iframe.contentWindow, doc = iframe.contentDocument;
      if (!win || !doc) return;
      doc.getElementById('vi-heat')?.remove();
      const W = doc.documentElement.scrollWidth, H = Math.max(doc.documentElement.scrollHeight, doc.body?.scrollHeight || 0);
      const canvas = doc.createElement('canvas');
      canvas.id = 'vi-heat';
      canvas.width = W; canvas.height = H;
      Object.assign(canvas.style, { position: 'absolute', left: '0', top: '0', width: W + 'px', height: H + 'px', pointerEvents: 'none', zIndex: '2147483647' });
      doc.body.appendChild(canvas);
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = 'rgba(15,23,42,.18)';
      ctx.fillRect(0, 0, W, H);

      const grid = d.grid || 20;
      let max = 0;
      for (const cells of Object.values(d.taps || {})) for (const n of Object.values(cells)) max = Math.max(max, n);
      if (!max) return;

      // 1. intensity layer
      const layer = doc.createElement('canvas');
      layer.width = W; layer.height = H;
      const lx = layer.getContext('2d');
      for (const s of this.sections(win)) {
        const cells = d.taps?.[s.key];
        if (!cells) continue;
        const r = s.el.getBoundingClientRect();
        const left = r.left + win.scrollX, top = r.top + win.scrollY;
        const radius = Math.max(22, Math.min(70, Math.min(r.width, r.height) / grid * 2.2));
        for (const [cell, n] of Object.entries(cells)) {
          const [gx, gy] = cell.split(',').map(Number);
          const x = left + (gx + 0.5) / grid * r.width, y = top + (gy + 0.5) / grid * r.height;
          const g = lx.createRadialGradient(x, y, 0, x, y, radius);
          g.addColorStop(0, `rgba(0,0,0,${Math.min(1, 0.25 + 0.75 * n / max)})`);
          g.addColorStop(1, 'rgba(0,0,0,0)');
          lx.fillStyle = g;
          lx.fillRect(x - radius, y - radius, radius * 2, radius * 2);
        }
      }
      // 2. colour it: blue (few) → green → yellow → red (many)
      const img = lx.getImageData(0, 0, W, H), px = img.data;
      const ramp = [[59, 130, 246], [34, 197, 94], [234, 179, 8], [239, 68, 68]];
      for (let i = 3; i < px.length; i += 4) {
        const a = px[i] / 255;
        if (a < 0.02) continue;
        const t = Math.min(0.999, a) * (ramp.length - 1), k = Math.floor(t), f = t - k;
        const c0 = ramp[k], c1 = ramp[k + 1];
        px[i - 3] = c0[0] + (c1[0] - c0[0]) * f;
        px[i - 2] = c0[1] + (c1[1] - c0[1]) * f;
        px[i - 1] = c0[2] + (c1[2] - c0[2]) * f;
        px[i] = Math.min(220, 70 + a * 200);
      }
      lx.putImageData(img, 0, 0);
      ctx.drawImage(layer, 0, 0);
    }
  }

  customElements.define(TAG, InsightsPage);
})();
