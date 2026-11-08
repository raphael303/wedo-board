/* Feedbackzielscheibe (Seite einer Aktivität vom Typ target) */
(function () {
  'use strict';
  const W = window.WT;
  const S = W.strings;
  const A = W.activity;
  const me = W.me;
  const api = W.api;
  const aspects = A.aspects;
  const N = aspects.length;
  const STEP = (Math.PI * 2) / N;
  const NS = 'http://www.w3.org/2000/svg';
  const $ = (s) => document.querySelector(s);
  const t = (k, vars) => { let s = S[k] || k; if (vars) for (const v in vars) s = s.split('{' + v + '}').join(vars[v]); return s; };
  const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

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

  /* ---------- Zustand ---------- */
  let state = W.state;
  const configVersion = state.config_version;
  const past = !!state.past;
  const draftKey = 'wt_draft:' + A.key + ':' + A.round;
  let draft = null;      // {aspectId: {r, t}} während der Eingabe
  let editing = false;
  let busy = false;

  const canAnswer = () => !me.owner && !past && state.status === 'open';
  function loadDraft() {
    try { const d = JSON.parse(localStorage.getItem(draftKey) || 'null'); if (d && typeof d === 'object') return d; } catch (e) { /* ignorieren */ }
    return null;
  }
  function saveDraft() { try { localStorage.setItem(draftKey, JSON.stringify(draft || {})); } catch (e) { /* ignorieren */ } }
  function clearDraft() { try { localStorage.removeItem(draftKey); } catch (e) { /* ignorieren */ } }
  function cleanDraft(d) {
    const out = {};
    if (!d) return out;
    for (const a of aspects) {
      const p = d[a.id];
      if (p && typeof p.r === 'number' && typeof p.t === 'number') out[a.id] = { r: Math.max(0, Math.min(1, p.r)), t: Math.max(0, Math.min(1, p.t)) };
    }
    return out;
  }
  function startEditing() {
    editing = true;
    draft = cleanDraft(loadDraft() || state.mine || {});
  }
  if (canAnswer() && !state.mine) startEditing();

  /* ---------- Farben ---------- */
  // Reihenfolge so gewählt, dass benachbarte Sektoren sich deutlich unterscheiden.
  const PALETTE = ['#3b6ef5', '#f08c00', '#10a37f', '#e64980', '#7950f2', '#0c9fb8', '#e03131', '#74b816', '#be4bdb', '#fd7e14', '#1c7ed6', '#2f9e44'];
  function sectorColor(i) {
    // Bei ungerader Anzahl über 7 nicht mit der Farbe des ersten Sektors enden.
    return PALETTE[i % PALETTE.length];
  }
  function scaleColor(r) {
    const h = 130 * Math.pow(1 - Math.max(0, Math.min(1, r)), 1.1); // 130 grün … 0 rot
    return 'hsl(' + h.toFixed(0) + ' 72% ' + (h > 60 && h < 100 ? 40 : 46) + '%)';
  }
  const aspectIndex = new Map(aspects.map((a, i) => [a.id, i]));
  function dotColor(aid, r) { return A.color_mode === 'scale' ? scaleColor(r) : sectorColor(aspectIndex.get(aid) || 0); }

  /* ---------- Geometrie ---------- */
  let G = null; // {size, c, R, band, font, dot}
  const disc = $('#disc');
  let svg = null, layerDots = null, layerOwn = null, labelEls = [];

  function measure() {
    const size = Math.max(240, Math.floor(disc.clientWidth));
    const font = Math.round(Math.max(12, Math.min(26, size * 0.033)));
    const band = Math.round(font * 2.1);
    const R = size / 2 - band - 4;
    const dot = Math.max(6, Math.min(13, size * 0.0135));
    return { size, c: size / 2, R, band, font, dot };
  }
  function polar(angle, radius) {
    // Winkel im Uhrzeigersinn ab zwölf Uhr.
    return [G.c + Math.sin(angle) * radius, G.c - Math.cos(angle) * radius];
  }
  function arcPath(a0, a1, radius, reverse) {
    const [x0, y0] = polar(reverse ? a1 : a0, radius), [x1, y1] = polar(reverse ? a0 : a1, radius);
    const large = Math.abs(a1 - a0) > Math.PI ? 1 : 0;
    return 'M' + x0.toFixed(2) + ' ' + y0.toFixed(2) + ' A' + radius + ' ' + radius + ' 0 ' + large + ' ' + (reverse ? 0 : 1) + ' ' + x1.toFixed(2) + ' ' + y1.toFixed(2);
  }
  function wedgePath(a0, a1, radius) {
    if (N === 1) return '';
    const [x0, y0] = polar(a0, radius), [x1, y1] = polar(a1, radius);
    return 'M' + G.c + ' ' + G.c + ' L' + x0.toFixed(2) + ' ' + y0.toFixed(2) + ' A' + radius + ' ' + radius + ' 0 ' + (a1 - a0 > Math.PI ? 1 : 0) + ' 1 ' + x1.toFixed(2) + ' ' + y1.toFixed(2) + ' Z';
  }
  /** Bildschirmlage eines Punktes {r, t} in Sektor i, so begrenzt, dass der Punkt ganz in Scheibe und Sektor bleibt. */
  function place(i, r, tt) {
    const rad = Math.max(0, Math.min(r * G.R, G.R - G.dot * 0.55));
    const margin = Math.min(STEP * 0.45, (G.dot * 1.05) / Math.max(rad, G.dot * 2));
    const ang = i * STEP + margin + (STEP - 2 * margin) * tt;
    const [x, y] = polar(ang, rad);
    return { x, y };
  }
  function svgEl(name, attrs, parent) {
    const n = document.createElementNS(NS, name);
    for (const k in attrs) n.setAttribute(k, attrs[k]);
    if (parent) parent.appendChild(n);
    return n;
  }

  /* ---------- Scheibe zeichnen ---------- */
  function buildDisc() {
    G = measure();
    const old = svg;
    svg = svgEl('svg', { viewBox: '0 0 ' + G.size + ' ' + G.size, width: G.size, height: G.size, class: 'target-svg' + (A.color_mode === 'scale' ? ' mode-scale' : ' mode-sector') });
    const defs = svgEl('defs', {}, svg);
    const grad = svgEl('radialGradient', { id: 'wt-scale', cx: '50%', cy: '50%', r: '50%' }, defs);
    [['0%', '#2fbf71', 0.30], ['45%', '#c9d93b', 0.20], ['75%', '#f5a524', 0.18], ['100%', '#e5484d', 0.22]].forEach(([o, c, a]) => svgEl('stop', { offset: o, 'stop-color': c, 'stop-opacity': a }, grad));

    // Teller mit Beschriftungsrand und innerer Scheibe
    svgEl('circle', { cx: G.c, cy: G.c, r: G.R + G.band, class: 'plate' }, svg);
    svgEl('circle', { cx: G.c, cy: G.c, r: G.R, class: 'face' }, svg);
    if (A.color_mode === 'scale') svgEl('circle', { cx: G.c, cy: G.c, r: G.R, fill: 'url(#wt-scale)' }, svg);
    const wedges = svgEl('g', { class: 'wedges' }, svg);
    labelEls = [];
    for (let i = 0; i < N; i++) {
      const a0 = i * STEP, a1 = (i + 1) * STEP;
      const w = svgEl('path', { d: wedgePath(a0, a1, G.R), class: 'wedge', 'data-i': i }, wedges);
      if (A.color_mode !== 'scale') w.style.fill = sectorColor(i);
    }
    // Ringe
    for (let k = 1; k <= 5; k++) svgEl('circle', { cx: G.c, cy: G.c, r: (G.R * k) / 5, class: 'ring' + (k === 5 ? ' ring-outer' : '') }, svg);
    // Trennlinien
    for (let i = 0; i < N; i++) {
      const [x, y] = polar(i * STEP, G.R + G.band);
      svgEl('line', { x1: G.c, y1: G.c, x2: x.toFixed(2), y2: y.toFixed(2), class: 'spoke' }, svg);
    }
    svgEl('circle', { cx: G.c, cy: G.c, r: Math.max(3, G.R * 0.03), class: 'bull' }, svg);

    // Beschriftungen auf Bögen; in der unteren Hälfte rückwärts, damit sie lesbar bleiben.
    const labels = svgEl('g', { class: 'labels' }, svg);
    for (let i = 0; i < N; i++) {
      const a0 = i * STEP, a1 = (i + 1) * STEP, mid = (a0 + a1) / 2;
      const bottom = N > 1 && mid > Math.PI / 2 && mid < Math.PI * 1.5;
      const radius = bottom ? G.R + G.band * 0.5 + G.font * 0.36 : G.R + G.band * 0.5 - G.font * 0.36;
      const pad = Math.min(STEP * 0.06, 0.08);
      const id = 'wt-arc-' + i;
      svgEl('path', { id, d: N === 1 ? arcPath(-Math.PI * 0.45, Math.PI * 0.45, radius, false) : arcPath(a0 + pad, a1 - pad, radius, bottom), fill: 'none' }, defs);
      const text = svgEl('text', { class: 'label', 'font-size': G.font }, labels);
      const tp = svgEl('textPath', { href: '#' + id, startOffset: '50%', 'text-anchor': 'middle' }, text);
      tp.setAttributeNS('http://www.w3.org/1999/xlink', 'xlink:href', '#' + id);
      const mark = svgEl('tspan', { class: 'label-mark' }, tp);
      mark.textContent = '● ';
      mark.style.fill = A.color_mode === 'scale' ? '#1b1f24' : sectorColor(i);
      const body = svgEl('tspan', {}, tp);
      body.textContent = aspects[i].label;
      const title = svgEl('title', {}, text);
      title.textContent = aspects[i].label;
      labelEls.push({ text, body, len: (a1 - a0 - 2 * pad) * radius });
    }
    layerDots = svgEl('g', { class: 'dots' }, svg);
    layerOwn = svgEl('g', { class: 'dots-own' }, svg);
    if (old) old.replaceWith(svg); else disc.appendChild(svg);
    fitLabels();
    dotEls.clear();
    bindPointer();
  }
  function fitLabels() {
    for (const L of labelEls) {
      let size = G.font;
      L.text.setAttribute('font-size', size);
      let w = 0;
      try { w = L.text.getComputedTextLength(); } catch (e) { w = 0; }
      if (!w || w <= L.len * 0.94) continue;
      size = Math.max(9, Math.floor(size * (L.len * 0.94) / w));
      L.text.setAttribute('font-size', size);
      try { w = L.text.getComputedTextLength(); } catch (e) { w = 0; }
      let label = L.body.textContent;
      while (w > L.len * 0.96 && label.length > 3) {
        label = label.slice(0, -2);
        L.body.textContent = label.replace(/\s+$/, '') + '…';
        try { w = L.text.getComputedTextLength(); } catch (e) { break; }
      }
    }
  }

  /* ---------- Punkte ---------- */
  const dotEls = new Map(); // Schlüssel → {g, x, y}
  let flyFrom = null;       // Startpunkt beim Anzeigen (Zähler unten rechts)

  function badgePoint() {
    const b = $('#count-box').getBoundingClientRect(), s = svg.getBoundingClientRect();
    return { x: b.left + b.width / 2 - s.left, y: b.top + b.height / 2 - s.top };
  }
  function hash(s) { let h = 0; for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0; return Math.abs(h); }

  /** Rückt zu nahe Punkte leicht auseinander, höchstens etwa um einen Punktdurchmesser, und hält sie in ihrem Sektor. */
  function relax(items) {
    const minD = G.dot * 2.1, maxShift = G.dot * 1.7;
    for (let iter = 0; iter < 36; iter++) {
      let moved = false;
      for (let i = 0; i < items.length; i++) {
        const p = items[i];
        for (let j = i + 1; j < items.length; j++) {
          const q = items[j];
          let dx = q.x - p.x, dy = q.y - p.y;
          let d = Math.hypot(dx, dy);
          if (d >= minD) continue;
          if (d < 0.01) { const a = (hash(p.key + q.key) % 628) / 100; dx = Math.cos(a); dy = Math.sin(a); d = 1; }
          const push = (minD - d) / 2;
          const ux = dx / d, uy = dy / d;
          const wp = p.fixed ? 0 : (q.fixed ? 2 : 1), wq = q.fixed ? 0 : (p.fixed ? 2 : 1);
          p.x -= ux * push * wp; p.y -= uy * push * wp;
          q.x += ux * push * wq; q.y += uy * push * wq;
          moved = true;
        }
      }
      for (const p of items) {
        if (p.fixed) continue;
        // Nicht weiter als maxShift von der echten Lage, innerhalb der Scheibe und des Sektors.
        let sx = p.x - p.ox, sy = p.y - p.oy, s = Math.hypot(sx, sy);
        if (s > maxShift) { p.x = p.ox + (sx / s) * maxShift; p.y = p.oy + (sy / s) * maxShift; }
        let dx = p.x - G.c, dy = p.y - G.c, rad = Math.hypot(dx, dy);
        const lim = G.R - G.dot * 0.55;
        if (rad > lim) { p.x = G.c + (dx / rad) * lim; p.y = G.c + (dy / rad) * lim; rad = lim; }
        if (N > 1 && rad > G.dot) {
          let ang = Math.atan2(p.x - G.c, -(p.y - G.c)); if (ang < 0) ang += Math.PI * 2;
          const m = Math.min(STEP * 0.45, (G.dot * 1.05) / rad);
          const lo = p.i * STEP + m, hi = (p.i + 1) * STEP - m;
          if (ang < lo || ang > hi) {
            const c = Math.abs(angDiff(ang, lo)) < Math.abs(angDiff(ang, hi)) ? lo : hi;
            const [x, y] = polar(c, rad); p.x = x; p.y = y;
          }
        }
      }
      if (!moved) break;
    }
  }
  function angDiff(a, b) { let d = a - b; while (d > Math.PI) d -= Math.PI * 2; while (d < -Math.PI) d += Math.PI * 2; return d; }

  function collect() {
    const items = [];
    const showAll = state.revealed;
    if (showAll) {
      for (const p of state.points || []) {
        if (!aspectIndex.has(p.a)) continue;
        if (p.m && editing) continue; // eigene gespeicherte Punkte durch den Entwurf ersetzen
        const i = aspectIndex.get(p.a), pos = place(i, p.r, p.t);
        // Eigene gespeicherte Punkte behalten ihren Schlüssel, damit sie beim Anzeigen nicht neu einfliegen.
        items.push({ key: p.m ? 'own:' + p.a : 'g' + p.g + ':' + p.a, i, a: p.a, r: p.r, x: pos.x, y: pos.y, ox: pos.x, oy: pos.y, mine: !!p.m, own: !!p.m, fixed: false });
      }
    }
    const own = editing ? draft : (!showAll ? state.mine : null);
    if (own) {
      for (const a of aspects) {
        const p = own[a.id]; if (!p) continue;
        const i = aspectIndex.get(a.id), pos = place(i, p.r, p.t);
        items.push({ key: 'own:' + (editing ? 'd:' : '') + a.id, i, a: a.id, r: p.r, x: pos.x, y: pos.y, ox: pos.x, oy: pos.y, mine: true, own: true, draft: editing, fixed: editing });
      }
    }
    return items;
  }

  function renderDots(opts = {}) {
    if (!svg) return;
    const items = collect();
    relax(items);
    const seen = new Set();
    let order = 0;
    for (const it of items) {
      seen.add(it.key);
      let d = dotEls.get(it.key);
      const layer = it.own ? layerOwn : layerDots;
      if (!d) {
        const g = svgEl('g', { class: 'dot' + (it.mine ? ' mine' : '') + (it.draft ? ' draft' : '') }, layer);
        if (it.draft) svgEl('circle', { r: G.dot * 2.1, class: 'halo' }, g);
        svgEl('circle', { r: it.own ? G.dot * (it.draft ? 1.25 : 1.15) : G.dot, class: 'core' }, g);
        d = { g, x: it.x, y: it.y };
        dotEls.set(it.key, d);
        const fly = opts.fly && flyFrom && !it.own && !reduceMotion;
        if (fly) {
          g.classList.add('no-anim');
          g.style.transform = 'translate(' + flyFrom.x + 'px,' + flyFrom.y + 'px) scale(0.35)';
          const delay = 40 + (hash(it.key) % 700) + order * 4;
          order++;
          requestAnimationFrame(() => requestAnimationFrame(() => {
            g.classList.remove('no-anim');
            g.style.transitionDelay = delay + 'ms';
            g.style.transform = 'translate(' + it.x + 'px,' + it.y + 'px)';
            setTimeout(() => { g.style.transitionDelay = ''; }, delay + 900);
          }));
        } else {
          g.classList.add('no-anim');
          g.style.transform = 'translate(' + it.x + 'px,' + it.y + 'px)';
          if (!opts.instant && !it.draft && !reduceMotion) g.classList.add('pop');
          requestAnimationFrame(() => g.classList.remove('no-anim'));
        }
      } else {
        if (opts.instant || it.draft) d.g.classList.add('no-anim');
        d.g.style.transform = 'translate(' + it.x + 'px,' + it.y + 'px)';
        if (opts.instant || it.draft) requestAnimationFrame(() => d.g.classList.remove('no-anim'));
      }
      d.x = it.x; d.y = it.y;
      d.g.querySelector('.core').style.fill = dotColor(it.a, it.r);
    }
    for (const [key, d] of dotEls) {
      if (seen.has(key)) continue;
      dotEls.delete(key);
      if (opts.flyBack && flyFrom && !reduceMotion && !key.startsWith('own:')) {
        d.g.style.transitionDelay = (hash(key) % 300) + 'ms';
        d.g.style.transform = 'translate(' + flyFrom.x + 'px,' + flyFrom.y + 'px) scale(0.2)';
        d.g.classList.add('leaving');
        setTimeout(() => d.g.remove(), 1100);
      } else d.g.remove();
    }
    // Sektoren ohne Punkt im Entwurf dezent markieren.
    svg.querySelectorAll('.wedge').forEach((w) => w.classList.toggle('missing', editing && !(draft && draft[aspects[+w.dataset.i].id])));
    labelEls.forEach((L, i) => L.text.classList.toggle('done', editing && !!(draft && draft[aspects[i].id])));
  }

  /* ---------- Eingabe: tippen und ziehen ---------- */
  let drag = null;
  function pointFromEvent(ev) {
    const rect = svg.getBoundingClientRect();
    const x = (ev.clientX - rect.left) * (G.size / rect.width), y = (ev.clientY - rect.top) * (G.size / rect.height);
    const dx = x - G.c, dy = y - G.c;
    let ang = Math.atan2(dx, -dy); if (ang < 0) ang += Math.PI * 2;
    return { x, y, d: Math.hypot(dx, dy), ang };
  }
  function toValue(i, p) {
    let ang = p.ang;
    const lo = i * STEP, hi = (i + 1) * STEP;
    if (N > 1 && (ang < lo || ang > hi)) ang = Math.abs(angDiff(ang, lo)) < Math.abs(angDiff(ang, hi)) ? lo : hi;
    const rad = Math.min(p.d, G.R);
    const margin = Math.min(STEP * 0.45, (G.dot * 1.05) / Math.max(Math.min(rad, G.R - G.dot * 0.55), G.dot * 2));
    const tt = N > 1 ? (ang - lo - margin) / (STEP - 2 * margin) : 0.5;
    return { r: +(rad / G.R).toFixed(4), t: +Math.max(0, Math.min(1, tt)).toFixed(4) };
  }
  function bindPointer() {
    svg.addEventListener('pointerdown', (ev) => {
      if (!editing || busy) return;
      const p = pointFromEvent(ev);
      if (p.d > G.R + G.band * 0.6) return;
      // Liegt ein eigener Punkt nahe, wird dieser gezogen, auch wenn der Finger knapp im Nachbarsektor ist.
      let i = Math.min(N - 1, Math.floor(p.ang / STEP));
      let best = null;
      for (const a of aspects) {
        const dp = draft[a.id]; if (!dp) continue;
        const j = aspectIndex.get(a.id), pos = place(j, dp.r, dp.t);
        const dist = Math.hypot(pos.x - p.x, pos.y - p.y);
        if (dist < G.dot * 2.6 && (!best || dist < best.dist)) best = { j, dist };
      }
      if (best) i = best.j;
      ev.preventDefault();
      try { svg.setPointerCapture(ev.pointerId); } catch (e) { /* ignorieren */ }
      drag = { i, id: ev.pointerId, moved: false };
      draft[aspects[i].id] = toValue(i, p);
      renderDots();
      renderUI();
    });
    svg.addEventListener('pointermove', (ev) => {
      if (!drag || ev.pointerId !== drag.id) return;
      ev.preventDefault();
      draft[aspects[drag.i].id] = toValue(drag.i, pointFromEvent(ev));
      drag.moved = true;
      renderDots();
    });
    const end = (ev) => {
      if (!drag || ev.pointerId !== drag.id) return;
      drag = null;
      saveDraft();
      renderDots();
      renderUI();
    };
    svg.addEventListener('pointerup', end);
    svg.addEventListener('pointercancel', end);
  }

  /* ---------- Bedienleiste, Zähler, Hinweise ---------- */
  function placedCount() { return draft ? Object.keys(draft).filter((k) => aspectIndex.has(k)).length : 0; }
  function renderUI() {
    const dock = $('#dock'), confirmBtn = $('#confirm'), discard = $('#discard'), progress = $('#progress');
    const n = state.count;
    const countEl = $('#count');
    const txt = n === 0 ? t('target_count_zero') : n === 1 ? t('target_count_one') : t('target_count', { n });
    if (countEl.textContent !== txt) {
      const bump = countEl.textContent !== '';
      countEl.textContent = txt;
      if (bump && !reduceMotion) { countEl.classList.remove('bump'); void countEl.offsetWidth; countEl.classList.add('bump'); }
    }
    const rv = $('#reveal');
    const oh = $('#owner-hint');
    if (oh) oh.hidden = !!state.revealed;
    if (rv) { rv.textContent = state.revealed ? t('target_hide') : t('target_reveal'); rv.classList.toggle('is-on', state.revealed); }

    const hint = $('#hint');
    if (past) { hint.textContent = ''; const pl = $('#past-label'); if (pl) pl.textContent = t('target_past', { n: state.round }); }
    else if (me.owner) hint.textContent = ''; // Hinweis steht beim Knopf unten rechts, damit die Scheibe beim Anzeigen nicht springt
    else if (editing) hint.textContent = A.require_all ? t('target_hint') : t('target_hint_optional');
    else if (state.revealed) hint.textContent = state.mine ? t('target_hint_revealed') : '';
    else hint.textContent = state.mine ? t('target_hint_saved') : '';
    hint.hidden = hint.textContent === '';

    if (!canAnswer()) { dock.hidden = true; }
    else if (editing) {
      dock.hidden = false;
      const k = placedCount();
      progress.textContent = t('target_progress', { n: k, total: N });
      progress.classList.toggle('complete', k === N);
      confirmBtn.textContent = state.mine ? t('target_save_change') : t('target_confirm');
      confirmBtn.disabled = busy || (A.require_all ? k < N : k < 1);
      discard.hidden = !state.mine;
      confirmBtn.classList.remove('is-saved');
    } else {
      dock.hidden = false;
      progress.textContent = '✓ ' + t('target_saved');
      progress.classList.add('complete');
      discard.hidden = true;
      confirmBtn.textContent = t('target_change');
      confirmBtn.disabled = busy;
      confirmBtn.classList.add('is-saved');
    }
    document.body.classList.toggle('target-editing', editing);
    document.body.classList.toggle('target-revealed', !!state.revealed);
    if (state.status !== 'open' && !past) notice(t('target_closed'), true);
  }

  let noticeTimer = null;
  function notice(text, warn, ms) {
    const n = $('#notice');
    clearTimeout(noticeTimer);
    if (!text) { n.hidden = true; return; }
    n.textContent = text;
    n.className = 'notice' + (warn ? ' warn' : '');
    n.hidden = false;
    if (ms) noticeTimer = setTimeout(() => { n.hidden = true; }, ms);
  }

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

  /** Übernimmt einen neuen Zustand und animiert Anzeigen und Verbergen. */
  function applyState(next) {
    if (!next || !next.changed) return;
    if (next.config_version && next.config_version !== configVersion) { location.reload(); return; }
    if (next.round !== state.round && !past) { clearDraft(); location.reload(); return; }
    const wasRevealed = state.revealed;
    state = next;
    if (state.status !== 'open' && editing && !me.owner) { editing = false; }
    if (!wasRevealed && state.revealed) { flyFrom = badgePoint(); renderDots({ fly: true }); }
    else if (wasRevealed && !state.revealed) { flyFrom = badgePoint(); renderDots({ flyBack: true }); }
    else renderDots();
    if (state.status === 'open') notice(null);
    renderUI();
  }

  $('#confirm').addEventListener('click', async () => {
    if (!canAnswer() || busy) return;
    if (!editing) { startEditing(); renderDots({ instant: true }); renderUI(); return; }
    busy = true; renderUI();
    try {
      const r = await call('/response', { points: draft });
      editing = false;
      clearDraft();
      draft = null;
      busy = false;
      applyState(r.state);
      renderDots({ instant: true });
      renderUI();
    } catch (e) {
      busy = false;
      renderUI();
      notice(t(e.message), true, 5000);
    }
  });
  $('#discard').addEventListener('click', () => {
    editing = false; draft = null; clearDraft();
    renderDots({ instant: true }); renderUI();
  });
  const revealBtn = $('#reveal');
  if (revealBtn) revealBtn.addEventListener('click', async () => {
    revealBtn.disabled = true;
    try { const r = await call('/reveal', { revealed: !state.revealed }); applyState(r.state); }
    catch (e) { notice(t(e.message), true, 5000); }
    revealBtn.disabled = false;
  });
  const roundBtn = $('#new-round');
  if (roundBtn) roundBtn.addEventListener('click', async () => {
    if (!window.confirm(t('target_new_round_confirm'))) return;
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
    scheduleResize();
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
  var resizeTimer = null, lastSize = 0; // var: setAccess ruft scheduleResize schon vorher auf
  function scheduleResize() {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      const s = Math.floor(disc.clientWidth);
      if (s === lastSize || !s) return;
      lastSize = s;
      buildDisc();
      renderDots({ instant: true });
    }, 60);
  }
  if (window.ResizeObserver) new ResizeObserver(scheduleResize).observe(disc);
  else window.addEventListener('resize', scheduleResize);

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
      if (data.changed && !drag) applyState(data);
    } catch (e) {
      failures++;
      if (failures >= 3) notice(t('offline'), true);
    }
    timer = setTimeout(poll, Math.min(3000 * Math.pow(1.6, Math.max(0, failures - 1)), 30000));
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });

  /* ---------- Start ---------- */
  lastSize = Math.floor(disc.clientWidth);
  buildDisc();
  renderDots({ instant: true });
  renderUI();
  timer = setTimeout(poll, 3000);
})();
