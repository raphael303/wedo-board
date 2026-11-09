/* Wortwolke (Seite einer Aktivität vom Typ wordcloud) */
(function () {
  'use strict';
  const W = window.WT;
  const S = W.strings;
  const A = W.activity;
  const me = W.me;
  const api = W.api;
  const $ = (s) => document.querySelector(s);
  const t = (k, vars) => { let s = S[k] || k; if (vars) for (const v in vars) s = s.split('{' + v + '}').join(vars[v]); return s; };
  const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /* ---------- Identität: wb_uid in localStorage spiegeln (wie auf der Board-Seite) ---------- */
  let storedUid = null;
  try {
    storedUid = localStorage.getItem('wb_uid');
    if (me.uid && /^[a-f0-9]{32}$/.test(me.uid)) {
      if (me.fresh && storedUid && storedUid !== me.uid && /^[a-f0-9]{32}$/.test(storedUid)) {
        fetch(api + '/identify', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ uid: storedUid }) }).then(() => location.reload());
        return;
      }
      localStorage.setItem('wb_uid', me.uid); storedUid = me.uid;
    }
  } catch (e) { /* localStorage nicht verfügbar */ }

  let state = W.state;
  const configVersion = state.config_version;
  const past = !!state.past;
  let busy = false;
  const canAnswer = () => !me.owner && !past && state.status === 'open';

  /* ---------- Aufrufe ---------- */
  async function call(path, json) {
    const init = { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(json || {}) };
    if (W.csrf) init.headers['X-CSRF'] = W.csrf;
    if (storedUid) init.headers['X-Guest-Key'] = storedUid;
    const res = await fetch(api + path, init);
    let data = null;
    try { data = await res.json(); } catch (e) { /* leer */ }
    if (!res.ok || !data || !data.ok) throw new Error((data && data.error) || 'error_generic');
    return data;
  }

  let noticeTimer = null;
  /** Meldung oben; optional mit einem Knopf (z. B. Rückgängig). */
  function notice(text, warn, ms, action) {
    const n = $('#notice');
    clearTimeout(noticeTimer);
    if (!text) { n.hidden = true; return; }
    n.textContent = '';
    const span = document.createElement('span');
    span.textContent = text;
    n.appendChild(span);
    n.className = 'notice' + (warn ? ' warn' : '') + (action ? ' has-action' : '');
    if (action) {
      const b = document.createElement('button');
      b.type = 'button'; b.className = 'notice-action'; b.textContent = action.label;
      b.addEventListener('click', () => { n.hidden = true; action.run(); });
      n.appendChild(b);
    }
    n.hidden = false;
    if (ms) noticeTimer = setTimeout(() => { n.hidden = true; }, ms);
  }

  /* ---------- Wolke: Anordnung ---------- */
  const cloud = $('#cloud');
  const PALETTE = ['#3b6ef5', '#e8590c', '#0f9d76', '#d6336c', '#7048e8', '#0c8599', '#c92a2a', '#5c940d', '#ae3ec9', '#1864ab', '#2b8a3e', '#b35c00'];
  function hash(s) { let h = 0; for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0; return Math.abs(h); }
  const els = new Map();   // id → {el, x, y, fs}
  const prev = new Map();  // id → {x, y} in Grundeinheiten der letzten Anordnung
  let measureCtx = null, fontFamily = 'system-ui, sans-serif';
  function textWidth(text, size) {
    if (!measureCtx) {
      measureCtx = document.createElement('canvas').getContext('2d');
      fontFamily = getComputedStyle(cloud).fontFamily || fontFamily;
    }
    measureCtx.font = '700 ' + size + 'px ' + fontFamily;
    return measureCtx.measureText(text).width;
  }

  /**
   * Ordnet die Begriffe spiralförmig um die Mitte an (häufigste zuerst, grösste Schrift), ohne Überlappung.
   * Ein Begriff versucht zuerst seine letzte Lage, damit die Wolke beim Wachsen ruhig bleibt. Danach wird das
   * Ganze so skaliert, dass es die Fläche füllt.
   */
  function layout(terms, Wd, Hd) {
    const maxN = terms.reduce((m, x) => Math.max(m, x.n), 1);
    const base = 22, top = maxN > 1 ? 64 : 34;
    const aspect = Math.max(0.6, Math.min(2.6, Wd / Math.max(1, Hd)));
    const placed = [];
    const pad = 4;
    const hit = (r) => placed.some((q) => r.x - r.w / 2 - pad < q.x + q.w / 2 && r.x + r.w / 2 + pad > q.x - q.w / 2 && r.y - r.h / 2 - pad / 2 < q.y + q.h / 2 && r.y + r.h / 2 + pad / 2 > q.y - q.h / 2);
    for (const term of terms) {
      const k = maxN > 1 ? Math.sqrt((term.n - 1) / (maxN - 1)) : 0;
      const fs = base + (top - base) * k;
      const w = textWidth(term.label, fs) + fs * 0.12, h = fs * 1.08;
      const r = { id: term.id, w, h, fs, x: 0, y: 0 };
      const p = prev.get(term.id);
      let ok = false;
      if (p) { r.x = p.x; r.y = p.y; ok = !hit(r); }
      if (!ok) {
        const start = (hash(term.id) % 628) / 100;
        for (let i = 0; i < 6000; i++) {
          const a = start + i * 0.21, rad = 2.2 * Math.sqrt(i) * 3.2;
          r.x = Math.cos(a) * rad * aspect; r.y = Math.sin(a) * rad;
          if (!hit(r)) { ok = true; break; }
        }
      }
      placed.push(r);
    }
    if (!placed.length) return { items: [], scale: 1 };
    let x0 = Infinity, x1 = -Infinity, y0 = Infinity, y1 = -Infinity;
    for (const r of placed) { x0 = Math.min(x0, r.x - r.w / 2); x1 = Math.max(x1, r.x + r.w / 2); y0 = Math.min(y0, r.y - r.h / 2); y1 = Math.max(y1, r.y + r.h / 2); }
    const scale = Math.min((Wd - 24) / (x1 - x0), (Hd - 24) / (y1 - y0), 2.6);
    const cx = (x0 + x1) / 2, cy = (y0 + y1) / 2;
    for (const r of placed) prev.set(r.id, { x: r.x, y: r.y });
    return { items: placed.map((r) => ({ id: r.id, fs: r.fs * scale, x: Wd / 2 + (r.x - cx) * scale, y: Hd / 2 + (r.y - cy) * scale })), scale };
  }

  function renderCloud(opts = {}) {
    const terms = state.visible ? (state.terms || []) : [];
    const empty = $('#cloud-empty');
    if (!state.visible) { empty.textContent = me.owner ? t('wc_hint_owner') : t('wc_wait'); empty.hidden = false; }
    else if (!terms.length) { empty.textContent = t('wc_empty_cloud'); empty.hidden = false; }
    else empty.hidden = true;
    cloud.setAttribute('aria-label', t('wc_cloud_label', { n: terms.length }));
    const Wd = cloud.clientWidth, Hd = cloud.clientHeight;
    if (!Wd || !Hd) return;
    const byId = new Map(terms.map((x) => [x.id, x]));
    const { items } = layout(terms, Wd, Hd);
    const seen = new Set();
    items.forEach((it, i) => {
      const term = byId.get(it.id);
      seen.add(it.id);
      let d = els.get(it.id);
      if (!d) {
        const el = document.createElement('button');
        el.type = 'button';
        el.className = 'wc-word';
        el.setAttribute('role', 'listitem');
        el.addEventListener('click', () => openTerm(el.dataset.id));
        cloud.appendChild(el);
        d = { el };
        els.set(it.id, d);
        el.classList.add('no-anim');
        el.style.transform = 'translate(' + it.x + 'px,' + it.y + 'px) translate(-50%,-50%) scale(' + (opts.instant || reduceMotion ? 1 : 0.2) + ')';
        el.style.opacity = opts.instant || reduceMotion ? '1' : '0';
        requestAnimationFrame(() => requestAnimationFrame(() => {
          el.classList.remove('no-anim');
          el.style.opacity = '1';
          el.style.transform = 'translate(' + it.x + 'px,' + it.y + 'px) translate(-50%,-50%) scale(1)';
        }));
      } else {
        if (opts.instant) d.el.classList.add('no-anim');
        d.el.style.transform = 'translate(' + it.x + 'px,' + it.y + 'px) translate(-50%,-50%) scale(1)';
        if (opts.instant) requestAnimationFrame(() => d.el.classList.remove('no-anim'));
      }
      const el = d.el;
      el.dataset.id = it.id;
      if (el.textContent !== term.label) el.textContent = term.label;
      el.style.fontSize = it.fs.toFixed(1) + 'px';
      el.style.color = i === 0 && items.length > 2 ? '#1b1f24' : PALETTE[hash(term.id) % PALETTE.length];
      el.style.zIndex = String(1000 - i);
      el.classList.toggle('mine', !!term.m);
      el.classList.toggle('grouped', !!term.v);
      el.title = term.label + ' · ' + (term.n === 1 ? t('wc_people_one') : t('wc_people', { n: term.n })) + (term.v ? ' · ' + t('wc_variants') + ': ' + term.v.map((v) => v[0]).join(', ') : '');
    });
    for (const [id, d] of els) {
      if (seen.has(id)) continue;
      els.delete(id);
      prev.delete(id);
      if (reduceMotion || opts.instant) { d.el.remove(); continue; }
      d.el.style.opacity = '0';
      d.el.style.transform += ' scale(0.3)';
      setTimeout(() => d.el.remove(), 600);
    }
  }

  /* ---------- Begriff ansehen und (Besitzer) korrigieren ---------- */
  const dlg = $('#wc-term'), dlgBody = $('#wc-term-body');
  function findTerm(id) {
    return (state.terms || []).find((x) => x.id === id) || ((state.owner && state.owner.hidden) || []).find((x) => x.id === id) || null;
  }
  function people(n) { return n === 1 ? t('wc_people_one') : t('wc_people', { n }); }
  function openTerm(id) {
    const term = findTerm(id);
    if (!term) return;
    if (!me.owner && !term.v) return; // Ohne Zusammenlegung gibt es nichts weiter zu zeigen.
    let h = '<h3>' + esc(term.label) + ' <small>' + esc(people(term.n)) + '</small></h3>';
    if (term.v) {
      h += '<p class="wc-dialog-sub">' + esc(t('wc_variants')) + '</p><ul class="wc-variants">';
      for (const v of term.v) {
        h += '<li><span>' + esc(v[0]) + ' <small>' + v[1] + '</small></span>' + (me.owner && !past ? '<button type="button" class="btn-ghost small" data-release="' + esc(v[2]) + '" title="' + esc(t('wc_release_title')) + '">' + esc(t('wc_release')) + '</button>' : '') + '</li>';
      }
      h += '</ul>';
    }
    if (me.owner && !past) {
      h += '<label class="wc-rename"><span>' + esc(t('wc_rename')) + '</span><span class="wc-rename-row"><input type="text" maxlength="40" value="' + esc(term.label) + '" id="wc-rename-input"><button type="button" class="btn-primary small" id="wc-rename-save">' + esc(t('wc_save')) + '</button></span></label>';
      const hidden = ((state.owner && state.owner.hidden) || []).some((x) => x.id === id);
      h += '<div class="wc-dialog-actions"><button type="button" class="btn-ghost" data-toggle-hide="' + (hidden ? 'show' : 'hide') + '">' + esc(hidden ? t('wc_show') : t('wc_hide')) + '</button><button class="btn-ghost" value="close">' + esc(t('close')) + '</button></div>';
    } else {
      h += '<div class="wc-dialog-actions"><button class="btn-ghost" value="close">' + esc(t('close')) + '</button></div>';
    }
    dlgBody.innerHTML = h;
    dlgBody.querySelectorAll('[data-release]').forEach((b) => b.addEventListener('click', () => groupAction({ action: 'release', variant: b.dataset.release })));
    const save = dlgBody.querySelector('#wc-rename-save');
    if (save) {
      const input = dlgBody.querySelector('#wc-rename-input');
      const go = () => { const v = input.value.trim(); if (v === term.label) { dlg.close(); return; } groupAction({ action: 'rename', term: id, label: v }); };
      save.addEventListener('click', go);
      input.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); go(); } });
    }
    const tog = dlgBody.querySelector('[data-toggle-hide]');
    if (tog) tog.addEventListener('click', () => groupAction({ action: tog.dataset.toggleHide, term: id }));
    if (dlg.showModal) dlg.showModal();
  }
  function openHidden() {
    const list = (state.owner && state.owner.hidden) || [];
    let h = '<h3>' + esc(t('wc_hidden_terms', { n: list.length })) + '</h3><ul class="wc-variants">';
    for (const x of list) h += '<li><span>' + esc(x.label) + ' <small>' + x.n + '</small></span><button type="button" class="btn-ghost small" data-show="' + esc(x.id) + '">' + esc(t('wc_show')) + '</button></li>';
    h += '</ul><div class="wc-dialog-actions"><button class="btn-ghost" value="close">' + esc(t('close')) + '</button></div>';
    dlgBody.innerHTML = h;
    dlgBody.querySelectorAll('[data-show]').forEach((b) => b.addEventListener('click', () => groupAction({ action: 'show', term: b.dataset.show })));
    if (dlg.showModal) dlg.showModal();
  }
  dlg.addEventListener('click', (ev) => { if (ev.target === dlg) dlg.close(); });

  async function groupAction(body) {
    try {
      const r = await call('/group', body);
      if (dlg.open) dlg.close();
      applyState(r.state);
      notice(r.label, false, 10000, { label: t('wc_undo'), run: undo });
    } catch (e) { notice(t(e.message), true, 6000); }
  }
  async function undo() {
    try {
      const r = await call('/undo', {});
      applyState(r.state);
      notice(t('wc_undone', { label: r.undone }), false, 5000);
    } catch (e) { notice(t(e.message), true, 5000); }
  }

  /* ---------- Bedienung ---------- */
  function renderUI() {
    const n = state.count;
    const countEl = $('#count');
    const txt = n === 0 ? t('wc_count_zero') : n === 1 ? t('wc_count_one') : t('wc_count', { n });
    if (countEl.textContent !== txt) {
      const bump = countEl.textContent !== '';
      countEl.textContent = txt;
      if (bump && !reduceMotion) { countEl.classList.remove('bump'); void countEl.offsetWidth; countEl.classList.add('bump'); }
    }
    const rv = $('#reveal'), oh = $('#owner-hint');
    if (oh) oh.hidden = !!state.visible;
    if (rv) { rv.textContent = state.visible ? t('target_hide') : t('target_reveal'); rv.classList.toggle('is-on', !!state.visible); }

    const hint = $('#hint');
    if (past) { hint.textContent = ''; const pl = $('#past-label'); if (pl) pl.textContent = t('target_past', { n: state.round }); }
    else if (canAnswer()) hint.textContent = A.max_terms === 1 ? t('wc_hint_one') : t('wc_hint', { n: A.max_terms });
    else hint.textContent = '';
    hint.hidden = hint.textContent === '';

    const dock = $('#dock');
    dock.hidden = !canAnswer();
    if (canAnswer()) {
      const mine = state.mine || [];
      const ul = $('#mine');
      ul.innerHTML = mine.map((x, i) => '<li><span>' + esc(x) + '</span><button type="button" data-i="' + i + '" aria-label="' + esc(t('wc_remove') + ': ' + x) + '" title="' + esc(t('wc_remove')) + '">×</button></li>').join('');
      ul.hidden = !mine.length;
      ul.querySelectorAll('button').forEach((b) => b.addEventListener('click', () => removeTerm(+b.dataset.i, mine[+b.dataset.i])));
      const full = mine.length >= A.max_terms;
      const input = $('#wc-input');
      input.disabled = full || busy;
      input.placeholder = full ? t('wc_full', { n: A.max_terms }) : t('wc_placeholder');
      $('#wc-send').disabled = full || busy;
      $('#wc-form').classList.toggle('is-full', full);
      $('#progress').textContent = t('wc_progress', { n: mine.length, total: A.max_terms });
    }
    if (me.owner && !past) {
      const o = state.owner || {};
      const hb = $('#wc-hidden');
      if (hb) { hb.hidden = !o.hidden_count; hb.textContent = t('wc_hidden_terms', { n: o.hidden_count || 0 }); }
      const ub = $('#wc-undo');
      if (ub) { ub.hidden = !o.undo; ub.title = o.undo ? t('wc_undo') + ': ' + o.undo.label : ''; }
    }
    document.body.classList.toggle('wc-visible', !!state.visible);
    if (state.status !== 'open' && !past) notice(t('wc_closed'), true);
  }

  function applyState(next) {
    if (!next || !next.changed) return;
    if (next.config_version && next.config_version !== configVersion) { location.reload(); return; }
    if (next.round !== state.round && !past) { location.reload(); return; }
    state = next;
    renderCloud();
    renderUI();
    if (state.status === 'open' && !$('#notice').querySelector('.notice-action') && $('#notice').classList.contains('warn')) notice(null);
  }

  $('#wc-form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const input = $('#wc-input');
    const text = input.value.trim();
    if (!text || busy || !canAnswer()) return;
    busy = true; renderUI();
    try {
      const r = await call('/terms', { text });
      input.value = '';
      busy = false;
      applyState(r.state);
      if ((state.mine || []).length < A.max_terms) input.focus();
    } catch (e) {
      busy = false; renderUI();
      notice(t(e.message), true, 5000);
      input.focus();
    }
  });
  async function removeTerm(i, text) {
    if (busy) return;
    busy = true;
    try { const r = await call('/terms/remove', { index: i, text }); busy = false; applyState(r.state); }
    catch (e) { busy = false; renderUI(); notice(t(e.message), true, 5000); }
  }

  const mergeBtn = $('#wc-merge');
  if (mergeBtn) mergeBtn.addEventListener('click', async () => {
    const ai = W.ai || {};
    if (!ai.available) {
      notice(t('ai_unavailable_' + (ai.reason || 'disabled')), true, 12000, { label: t('wc_settings'), run: () => { location.href = ai.reason === 'activity_off' ? ai.edit_url : ai.settings_url; } });
      return;
    }
    mergeBtn.disabled = true;
    const old = mergeBtn.textContent;
    mergeBtn.textContent = t('wc_merging');
    mergeBtn.classList.add('is-busy');
    try {
      const r = await call('/merge', {});
      applyState(r.state);
      const msg = r.merged > 0 ? t('wc_merged', { from: r.terms_before, to: r.terms_after }) : t('wc_merged_none');
      notice(msg + (r.model ? ' · ' + r.model : ''), false, 12000, r.merged > 0 ? { label: t('wc_undo'), run: undo } : null);
    } catch (e) { notice(t(e.message), true, 8000); }
    mergeBtn.disabled = false;
    mergeBtn.textContent = old;
    mergeBtn.classList.remove('is-busy');
  });
  const undoBtn = $('#wc-undo');
  if (undoBtn) undoBtn.addEventListener('click', undo);
  const hiddenBtn = $('#wc-hidden');
  if (hiddenBtn) hiddenBtn.addEventListener('click', openHidden);
  const revealBtn = $('#reveal');
  if (revealBtn) revealBtn.addEventListener('click', async () => {
    revealBtn.disabled = true;
    try { const r = await call('/reveal', { revealed: !state.visible }); applyState(r.state); }
    catch (e) { notice(t(e.message), true, 5000); }
    revealBtn.disabled = false;
  });
  const roundBtn = $('#new-round');
  if (roundBtn) roundBtn.addEventListener('click', async () => {
    if (!window.confirm(t('wc_new_round_confirm'))) return;
    try { await call('/round', {}); location.reload(); } catch (e) { notice(t(e.message), true, 5000); }
  });

  /* ---------- Präsentationsmodus und Zugangs-Panel (wie auf der Board-Seite) ---------- */
  $('#presenter').addEventListener('click', () => {
    const on = document.body.classList.toggle('presenter');
    $('#presenter').title = on ? t('presenter_exit') : t('presenter');
    if (on && document.documentElement.requestFullscreen) document.documentElement.requestFullscreen().catch(() => {});
    if (!on && document.fullscreenElement) document.exitFullscreen().catch(() => {});
  });
  document.addEventListener('fullscreenchange', () => { if (!document.fullscreenElement) document.body.classList.remove('presenter'); });
  const access = $('#access'), accessBtn = $('#access-btn'), accessBig = $('#access-big');
  const accessStore = 'wb_access:' + A.key;
  function setAccess(on, remember) {
    access.hidden = !on;
    document.body.classList.toggle('access-open', on);
    accessBtn.setAttribute('aria-expanded', on ? 'true' : 'false');
    accessBtn.classList.toggle('is-active', on);
    accessBtn.title = on ? t('access_hide') : t('access');
    if (remember) { try { localStorage.setItem(accessStore, on ? '1' : '0'); } catch (e) { /* ignorieren */ } }
  }
  let accessInitial = false;
  try { accessInitial = localStorage.getItem(accessStore) === '1'; } catch (e) { /* ignorieren */ }
  setAccess(accessInitial, false);
  accessBtn.addEventListener('click', () => setAccess(access.hidden, true));
  $('#access-close').addEventListener('click', () => setAccess(false, true));
  $('#access-enlarge').addEventListener('click', () => { if (accessBig.showModal) accessBig.showModal(); });
  $('#access-big-close').addEventListener('click', () => accessBig.close());
  accessBig.addEventListener('click', () => accessBig.close());
  $('#access-copy').addEventListener('click', async (ev) => {
    const b = ev.currentTarget, url = b.dataset.url;
    try { await navigator.clipboard.writeText(url); } catch (e) { window.prompt(t('access_copy'), url); return; }
    b.textContent = t('access_copied');
    setTimeout(() => { b.textContent = t('access_copy'); }, 1600);
  });

  /* ---------- Grösse ---------- */
  let resizeTimer = null, lastW = 0, lastH = 0;
  function onResize() {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      const w = cloud.clientWidth, h = cloud.clientHeight;
      if ((w === lastW && h === lastH) || !w) return;
      lastW = w; lastH = h;
      prev.clear(); // neue Fläche: frisch anordnen
      renderCloud({ instant: true });
    }, 80);
  }
  if (window.ResizeObserver) new ResizeObserver(onResize).observe(cloud);
  else window.addEventListener('resize', onResize);

  /* ---------- Abfrage ---------- */
  let failures = 0, timer = null;
  async function poll() {
    clearTimeout(timer);
    if (past) return;
    if (document.hidden) { timer = setTimeout(poll, 15000); return; }
    try {
      const init = { credentials: 'same-origin', cache: 'no-store', headers: {} };
      if (storedUid) init.headers['X-Guest-Key'] = storedUid;
      const res = await fetch(api + '/state?since=' + encodeURIComponent(state.version), init);
      if (!res.ok) throw new Error('http ' + res.status);
      const data = await res.json();
      if (failures) { failures = 0; notice(null); }
      if (data.changed && !busy) applyState(data);
    } catch (e) {
      failures++;
      if (failures >= 3) notice(t('offline'), true);
    }
    timer = setTimeout(poll, Math.min(3000 * Math.pow(1.6, Math.max(0, failures - 1)), 30000));
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });

  /* ---------- Start ---------- */
  lastW = cloud.clientWidth; lastH = cloud.clientHeight;
  renderCloud({ instant: true });
  renderUI();
  timer = setTimeout(poll, 3000);
})();
