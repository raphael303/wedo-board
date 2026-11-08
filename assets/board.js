/* Gäste-Ansicht (Board-Seite) */
(function () {
  'use strict';
  const W = window.WB;
  const S = W.strings;
  const board = W.board;
  const api = W.api;
  const me = W.me;

  /* ---------- Identität: wb_uid in localStorage spiegeln ---------- */
  let storedUid = null;
  try {
    storedUid = localStorage.getItem('wb_uid');
    if (me.uid && /^[a-f0-9]{32}$/.test(me.uid)) {
      if (me.fresh && storedUid && storedUid !== me.uid) {
        // Cookie fehlte, aber der Browser kennt seinen alten Schlüssel: Cookie neu setzen und mit dem alten Namen laden.
        fetch(api + '/identify', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ uid: storedUid }) }).then(() => location.reload());
      } else {
        localStorage.setItem('wb_uid', me.uid); storedUid = me.uid;
      }
    }
  } catch (e) { /* localStorage nicht verfügbar */ }

  const state = {
    version: W.state.version,
    status: W.state.status,
    posts: new Map(),
    bundles: new Map(),
    openBundles: new Set(),
    comments: new Map(),
    reactions: new Map(), // "post:12" -> Map(emoji -> {n, mine})
    collapsedComments: new Set(),
    openForms: new Set(),
    expanded: new Set(),
    drafts: new Map(),
    activeColumn: board.columns[0] ? board.columns[0].id : null,
    pickerOpen: null,
  };

  const $ = (sel, root) => (root || document).querySelector(sel);
  const el = (tag, attrs, ...children) => {
    const n = document.createElement(tag);
    if (attrs) for (const [k, v] of Object.entries(attrs)) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') n.className = v;
      else if (k === 'style') n.style.cssText = v;
      else if (k.startsWith('on')) n.addEventListener(k.slice(2), v);
      else if (k === 'text') n.textContent = v;
      else n.setAttribute(k, v === true ? '' : v);
    }
    for (const c of children) if (c !== null && c !== undefined && c !== false) n.append(c);
    return n;
  };
  const t = (key, vars) => {
    let s = S[key] || key;
    if (vars) for (const [k, v] of Object.entries(vars)) s = s.replace('{' + k + '}', v);
    return s;
  };
  const ICONS = {
    edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>',
    trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>',
    comment: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-8 8H7l-4 3V12a8 8 0 0 1 8-8h2a8 8 0 0 1 8 8z"/></svg>',
    bundle: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16"/><path d="M6 12h12"/><path d="M8 17h8"/></svg>',
    chevron: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>',
    react: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="12" r="8"/><path d="M7 14.5s1 1.5 3 1.5 3-1.5 3-1.5"/><path d="M7.5 10h.01M12.5 10h.01"/><path d="M19 3v6M16 6h6"/></svg>',
    link: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/></svg>',
  };
  const svg = (name) => { const s = el('span'); s.innerHTML = ICONS[name]; return s.firstChild; };

  /* ---------- Zustand einpflegen ---------- */
  function applyState(st) {
    if (st.full) { state.posts.clear(); state.comments.clear(); state.bundles.clear(); }
    for (const g of st.bundles || []) {
      if (g.deleted) state.bundles.delete(g.id); else state.bundles.set(g.id, g);
    }
    for (const p of st.posts || []) {
      if (p.deleted) state.posts.delete(p.id); else state.posts.set(p.id, p);
    }
    for (const c of st.comments || []) {
      if (c.deleted) state.comments.delete(c.id); else state.comments.set(c.id, c);
    }
    if (st.reactions) {
      state.reactions.clear();
      for (const r of st.reactions) {
        const k = r.t + ':' + r.id;
        if (!state.reactions.has(k)) state.reactions.set(k, new Map());
        state.reactions.get(k).set(r.e, { n: r.n, mine: r.mine });
      }
    }
    if (st.status) state.status = st.status;
    if (st.version) state.version = st.version;
  }

  /* ---------- Hilfen ---------- */
  function parseUtc(s) { return new Date(s.replace(' ', 'T').replace(/(\.\d{3})\d*$/, '$1') + 'Z'); }
  function timeLabel(s) {
    const d = parseUtc(s), diff = (Date.now() - d.getTime()) / 1000;
    if (diff < 60) return t('just_now');
    if (diff < 3600) return t('minutes_ago', { n: Math.floor(diff / 60) });
    if (diff < 86400) return t('hours_ago', { n: Math.floor(diff / 3600) });
    return d.toLocaleDateString(board.lang === 'de' ? 'de-CH' : 'en-GB', { day: 'numeric', month: 'short' }) + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }
  function hostOf(url) { try { return new URL(url).host.replace(/^www\./, ''); } catch (e) { return url; } }
  const canWrite = () => me.owner || (state.status === 'open' && !me.blocked);
  const commentsOn = () => board.settings.comments || me.owner;
  const reactionsOn = () => board.settings.reactions || me.owner;

  async function call(path, opts = {}) {
    const init = { method: opts.method || 'POST', credentials: 'same-origin', headers: {} };
    if (W.csrf) init.headers['X-CSRF'] = W.csrf;
    if (storedUid) init.headers['X-Guest-Key'] = storedUid;
    if (opts.form) init.body = opts.form;
    else if (opts.json) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.json); }
    const res = await fetch(api + path, init);
    let data = null;
    try { data = await res.json(); } catch (e) { /* leer */ }
    if (!res.ok || !data || !data.ok) {
      const err = new Error((data && data.error) || 'error_generic');
      err.status = res.status;
      err.code = data && data.code;
      throw err;
    }
    return data;
  }
  function showError(e) {
    if (e.code === 'blocked' && !me.owner) { me.blocked = true; render(); return; }
    const msg = S[e.message] ? t(e.message) : (e.message && e.message.length < 160 ? e.message : t('error_generic'));
    notice(msg, true, 6000);
  }
  let noticeTimer = null;
  function notice(text, warn, ms, action) {
    const n = $('#notice');
    clearTimeout(noticeTimer);
    if (!text) { n.hidden = true; return; }
    n.replaceChildren(el('span', { text }));
    if (action) n.append(el('button', { class: 'notice-action', type: 'button', text: action.label, onclick: () => { n.hidden = true; action.run(); } }));
    n.className = 'notice' + (warn ? ' warn' : '') + (action ? ' has-action' : ''); n.hidden = false;
    if (ms) noticeTimer = setTimeout(() => { n.hidden = true; }, ms);
  }

  /* ---------- Rückgängig und Verlauf (Besitzer) ---------- */
  function offerUndo(r, text) {
    if (!r || !r.undo_id) return;
    notice(text || t('undo'), false, 10000, { label: t('undo'), run: () => undo(r.undo_id) });
  }
  async function undo(id) {
    try {
      const r = await call('/undo/' + id, { method: 'POST' });
      notice(t('undo_done'), false, 3000);
      if (r.version) { await poll(); }
      if (hdlg && hdlg.open) loadHistory();
    } catch (e) { showError(e); }
  }
  const hdlg = $('#history-dialog');
  function actorLabel(a) {
    if (a === 'teacher') return t('actor_teacher');
    if (a === 'claude') return t('actor_claude');
    if (a && a.startsWith('ai:')) return t('actor_ai', { model: a.slice(3) });
    return a || '';
  }
  async function loadHistory() {
    const list = $('#history-list');
    list.replaceChildren();
    try {
      const r = await call('/history', { method: 'GET' });
      if (!r.entries.length) { list.append(el('li', { class: 'history-empty', text: t('history_empty') })); return; }
      for (const e of r.entries) {
        const li = el('li', { class: 'history-item' + (e.undone ? ' undone' : '') },
          el('div', { class: 'history-main' },
            el('span', { class: 'history-label', text: e.label }),
            el('span', { class: 'history-meta', text: timeLabel(e.created_at) + ' · ' + actorLabel(e.actor) + (e.undone ? ' · ' + t('undone') : '') })));
        if (e.can_undo) li.append(el('button', { class: 'btn-ghost small', type: 'button', text: t('undo'), onclick: () => undo(e.id) }));
        list.append(li);
      }
    } catch (e) { showError(e); }
  }
  if (hdlg) {
    $('#history-btn').addEventListener('click', () => { hdlg.showModal(); loadHistory(); });
    $('#history-close').addEventListener('click', () => hdlg.close());
    hdlg.addEventListener('close', () => render());
  }

  /* ---------- Rendern ---------- */
  const sortedPosts = () => [...state.posts.values()].sort((a, b) => a.position - b.position || a.id - b.id);
  const sortedBundles = () => [...state.bundles.values()].sort((a, b) => a.position - b.position || a.id - b.id);
  const postsInBundle = (gid) => sortedPosts().filter(p => p.bundle === gid);
  // Lose Beiträge und Bündel als gemischte Liste; lose Beiträge je nach Einstellung oben oder unten.
  function items(posts, bundles) {
    const loose = posts.filter(p => !p.bundle || !state.bundles.has(p.bundle));
    const looseItems = loose.map(p => ({ kind: 'post', post: p }));
    const bundleItems = bundles.map(g => ({ kind: 'bundle', bundle: g }));
    return board.settings.new_on_top ? looseItems.concat(bundleItems) : bundleItems.concat(looseItems);
  }
  function renderItem(it) { return it.kind === 'bundle' ? renderBundle(it.bundle) : renderPost(it.post); }

  function render() {
    const root = $('#board');
    root.replaceChildren();
    const posts = sortedPosts();
    if (board.type === 'columns') renderColumns(root, posts); else renderWall(root, posts);
    updateStatusNotice();
  }

  function renderWall(root, posts) {
    if (!posts.length && !state.bundles.size) { root.append(el('p', { class: 'board-empty', text: canWrite() ? t('board_empty') : '' })); return; }
    // Geschlossene Karten fliessen im Raster; ein offenes Bündel nimmt die ganze Breite und zeigt seine Beiträge im eigenen Raster.
    const container = el('div', { class: 'wall-flow' });
    let wall = null;
    for (const it of items(posts, sortedBundles())) {
      if (it.kind === 'bundle' && state.openBundles.has(it.bundle.id)) {
        wall = null;
        container.append(renderBundle(it.bundle, true));
      } else {
        if (!wall) { wall = el('div', { class: 'wall' }); container.append(wall); }
        wall.append(renderItem(it));
      }
    }
    if (me.owner) enableDropZone(container, null);
    root.append(container);
  }

  function renderColumns(root, posts) {
    const tabs = el('div', { class: 'column-tabs', role: 'tablist' });
    const wrap = el('div', { class: 'columns' });
    const byCol = new Map();
    for (const c of board.columns) byCol.set(c.id, []);
    for (const p of posts) {
      const key = byCol.has(p.column) ? p.column : (board.columns[0] && board.columns[0].id);
      if (key) byCol.get(key).push(p);
    }
    if (!board.columns.some(c => c.id === state.activeColumn)) state.activeColumn = board.columns[0] ? board.columns[0].id : null;
    for (const c of board.columns) {
      const list = byCol.get(c.id);
      const gList = sortedBundles().filter(g => (board.columns.some(x => x.id === g.column) ? g.column : board.columns[0].id) === c.id);
      tabs.append(el('button', {
        class: 'column-tab', role: 'tab', 'aria-selected': String(c.id === state.activeColumn),
        text: c.title + (list.length ? ' · ' + list.length : ''),
        onclick: () => { state.activeColumn = c.id; render(); },
      }));
      const body = el('div', { class: 'column-body' });
      if (!list.length && !gList.length) body.append(el('p', { class: 'column-empty', text: t('column_empty') }));
      for (const it of items(list, gList)) body.append(renderItem(it));
      const section = el('section', { class: 'column' + (c.id === state.activeColumn ? ' active' : ''), 'data-column': c.id },
        el('header', { class: 'column-head' },
          el('h2', { class: 'column-title', text: c.title }),
          el('span', { class: 'column-count', text: list.length ? String(list.length) : '' }),
          canWrite() ? el('button', { class: 'column-add', type: 'button', title: t('new_post'), 'aria-label': t('new_post'), text: '+', onclick: () => openComposer(null, c.id) }) : null),
        body);
      if (me.owner) enableDropZone(section, c.id);
      wrap.append(section);
    }
    root.append(tabs, wrap);
  }

  function renderPost(p) {
    const art = el('article', { class: 'post', 'data-id': p.id, style: '--hue:' + p.hue });
    if (me.owner) {
      art.draggable = true;
      art.title = t('drag_hint');
      art.addEventListener('dragstart', (ev) => { ev.stopPropagation(); ev.dataTransfer.setData('text/plain', 'post:' + p.id); ev.dataTransfer.effectAllowed = 'move'; art.classList.add('dragging'); });
      art.addEventListener('dragend', () => art.classList.remove('dragging'));
    }
    const head = el('header', { class: 'post-head' },
      authorEl(p),
      el('time', { text: timeLabel(p.created_at) + (p.edited ? ' · ' + t('edited') : ''), title: p.created_at }));
    if (p.editable && canWrite()) {
      head.append(el('div', { class: 'post-menu' },
        el('button', { class: 'icon-btn', type: 'button', title: t('edit'), 'aria-label': t('edit'), onclick: () => openComposer(p) }, svg('edit')),
        el('button', { class: 'icon-btn', type: 'button', title: t('delete'), 'aria-label': t('delete'), onclick: () => deletePost(p) }, svg('trash'))));
    }
    art.append(head);
    if (p.title) art.append(el('h3', { class: 'post-title', text: p.title }));
    if (p.body) {
      const long = p.body.length > 600 || (p.body.match(/\n/g) || []).length > 11;
      const expanded = state.expanded.has(p.id);
      const body = el('p', { class: 'post-body' + (long && !expanded ? ' clamp' : ''), text: p.body });
      art.append(body);
      if (long) art.append(el('button', { class: 'more', type: 'button', text: expanded ? t('show_less') : t('show_more'),
        onclick: () => { expanded ? state.expanded.delete(p.id) : state.expanded.add(p.id); render(); } }));
    }
    if (p.image) {
      art.append(el('a', { class: 'post-image', href: p.image, onclick: (ev) => { ev.preventDefault(); openLightbox(p.image); } },
        el('img', { src: p.thumb || p.image, alt: p.title || '', loading: 'lazy' })));
    }
    if (p.link) {
      art.append(el('a', { class: 'post-link', href: p.link, target: '_blank', rel: 'noopener noreferrer' }, svg('link'), el('span', { text: hostOf(p.link) })));
    }
    const foot = el('footer', { class: 'post-foot' });
    foot.append(renderReactions('post', p.id));
    const count = [...state.comments.values()].filter(c => c.post === p.id).length;
    const open = count ? !state.collapsedComments.has(p.id) : state.openForms.has(p.id);
    if (commentsOn() || count) {
      foot.append(el('button', { class: 'comments-toggle', type: 'button', 'aria-expanded': String(open),
        onclick: () => {
          if (count) { open ? state.collapsedComments.add(p.id) : state.collapsedComments.delete(p.id); }
          else { open ? state.openForms.delete(p.id) : state.openForms.add(p.id); }
          render();
        } },
        svg('comment'), el('span', { text: count ? String(count) : t('comment') })));
    }
    art.append(foot);
    if (open) art.append(renderComments(p));
    return art;
  }

  /* ---------- Gäste sperren (Besitzer) ---------- */
  function authorEl(item) {
    const inner = [el('i', { class: 'dot' }), el('span', { class: 'name', text: item.author })];
    if (me.owner && item.akey) {
      return el('button', { class: 'author author-btn', type: 'button', title: t('block_board'), onclick: (ev) => { ev.stopPropagation(); openBlockDialog(item); } }, ...inner);
    }
    return el('span', { class: 'author' }, ...inner);
  }
  const kdlg = $('#block-dialog');
  function openBlockDialog(item) {
    if (!kdlg) return;
    const f = $('#block-form');
    f.reset();
    f.guest_key.value = item.akey; f.display_name.value = item.author;
    $('#block-title').textContent = t('block_title', { name: item.author });
    $('#block-submit').textContent = t('block_board');
    kdlg.showModal();
  }
  if (kdlg) {
    const f = $('#block-form');
    f.addEventListener('change', () => { $('#block-submit').textContent = t(f.scope.value === 'all' ? 'block_all' : 'block_board'); });
    $('#block-cancel').addEventListener('click', () => kdlg.close());
    f.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const btn = $('#block-submit'); btn.disabled = true;
      try {
        const r = await call('/block', { json: { guest_key: f.guest_key.value, display_name: f.display_name.value, scope: f.scope.value, hide: f.hide.checked } });
        kdlg.close();
        await poll();
        offerUndo(r, t('block_done', { name: f.display_name.value }));
      } catch (e) { showError(e); } finally { btn.disabled = false; }
    });
    kdlg.addEventListener('close', () => render());
  }

  /* ---------- KI-Bündelung (Besitzer) ---------- */
  const adlg = $('#ai-dialog');
  let aiAbort = null;
  function aiShow(part) {
    for (const id of ['ai-unavailable', 'ai-body', 'ai-running']) $('#' + id).hidden = id !== part;
  }
  function aiUpdateButton() {
    const f = $('#ai-form');
    $('#ai-submit').textContent = t(f.instruction.value.trim() ? 'ai_run_prompt' : 'ai_run_auto');
  }
  async function openAiDialog() {
    if (!adlg) return;
    const f = $('#ai-form');
    f.reset();
    $('#ai-error').hidden = true;
    adlg.showModal();
    aiShow('ai-running');
    try {
      const st = await call('/ai/status', { method: 'GET' });
      if (!st.available) {
        $('#ai-unavailable-text').textContent = t('ai_unavailable_' + st.reason);
        const link = $('#ai-settings-link');
        link.href = st.reason === 'board_off' ? '/admin/board.php?key=' + board.key : '/admin/settings.php#ki';
        link.textContent = t(st.reason === 'board_off' ? 'ai_to_board_settings' : 'ai_to_settings');
        link.hidden = st.reason === 'forbidden';
        aiShow('ai-unavailable'); return;
      }
      if (st.post_count < st.min_posts) {
        $('#ai-unavailable-text').textContent = t('ai_too_few', { n: st.min_posts });
        $('#ai-settings-link').hidden = true;
        aiShow('ai-unavailable'); return;
      }
      if (st.post_count > st.max_posts) {
        $('#ai-unavailable-text').textContent = t('ai_too_many', { n: st.max_posts });
        $('#ai-settings-link').hidden = true;
        aiShow('ai-unavailable'); return;
      }
      const warn = $('#ai-warn');
      warn.hidden = st.post_count < st.warn_posts;
      if (!warn.hidden) warn.textContent = t('ai_warn_many', { n: st.post_count });
      $('#ai-model-hint').textContent = t('ai_model_hint', { model: st.model || '' });
      f.collect_rest.checked = !!st.collect_rest;
      f.querySelector('input[name=scope][value=loose]').disabled = st.bundle_count === 0 && st.loose_count === st.post_count;
      const rec = $('#ai-recent');
      rec.replaceChildren();
      if (st.recent_instructions && st.recent_instructions.length) {
        rec.append(el('span', { class: 'chips-label', text: t('ai_recent') }));
        for (const ins of st.recent_instructions) rec.append(el('button', { type: 'button', class: 'chip', text: ins.length > 60 ? ins.slice(0, 57) + '…' : ins, title: ins, onclick: () => { f.instruction.value = ins; aiUpdateButton(); f.instruction.focus(); } }));
      }
      rec.hidden = !rec.childElementCount;
      aiUpdateButton();
      aiShow('ai-body');
      setTimeout(() => f.instruction.focus(), 30);
    } catch (e) { adlg.close(); showError(e); }
  }
  if (adlg) {
    const f = $('#ai-form');
    $('#ai-bundle').addEventListener('click', openAiDialog);
    adlg.querySelectorAll('.ai-close').forEach(b => b.addEventListener('click', () => adlg.close()));
    f.instruction.addEventListener('input', aiUpdateButton);
    f.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' && (ev.metaKey || ev.ctrlKey) && !$('#ai-body').hidden) { ev.preventDefault(); f.requestSubmit(); } });
    $('#ai-abort').addEventListener('click', () => { if (aiAbort) aiAbort.abort(); adlg.close(); });
    f.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if ($('#ai-body').hidden) return;
      $('#ai-error').hidden = true;
      aiShow('ai-running');
      aiAbort = new AbortController();
      const body = { instruction: f.instruction.value.trim(), scope: f.scope.value, collect_rest: f.collect_rest.checked };
      try {
        const res = await fetch(api + '/ai/organize', { method: 'POST', credentials: 'same-origin', signal: aiAbort.signal,
          headers: { 'Content-Type': 'application/json', 'X-CSRF': W.csrf || '' }, body: JSON.stringify(body) });
        let data = null; try { data = await res.json(); } catch (e) { /* leer */ }
        if (!res.ok || !data || !data.ok) {
          const msg = (data && data.error) || t('error_generic');
          $('#ai-error').textContent = msg; $('#ai-error').hidden = false;
          aiShow('ai-body'); return;
        }
        adlg.close();
        await poll();
        const text = (data.scope === 'loose' ? t('ai_result_assigned', { a: data.assigned, n: data.bundles.length, m: data.loose_posts }) : t('ai_result', { n: data.bundles.length, m: data.loose_posts })) + ' · ' + data.model;
        offerUndo(data, text);
      } catch (e) {
        if (e.name === 'AbortError') return;
        $('#ai-error').textContent = t('error_generic'); $('#ai-error').hidden = false; aiShow('ai-body');
      } finally { aiAbort = null; }
    });
    adlg.addEventListener('close', () => render());
  }

  /* ---------- Bündel ---------- */
  function renderBundle(g, wide) {
    const posts = postsInBundle(g.id);
    const open = state.openBundles.has(g.id);
    const art = el('article', { class: 'bundle' + (open ? ' open' : '') + (wide ? ' wide' : ''), 'data-bundle': g.id });
    if (me.owner) {
      art.draggable = true;
      art.addEventListener('dragstart', (ev) => { ev.stopPropagation(); ev.dataTransfer.setData('text/plain', 'bundle:' + g.id); ev.dataTransfer.effectAllowed = 'move'; art.classList.add('dragging'); });
      art.addEventListener('dragend', () => art.classList.remove('dragging'));
      enableDropZone(art, null, g.id);
    }
    const head = el('header', { class: 'bundle-head' },
      el('span', { class: 'bundle-icon' }, svg('bundle')),
      el('h3', { class: 'bundle-title', text: g.title }),
      el('span', { class: 'bundle-count', text: String(posts.length) }));
    if (me.owner && canWrite()) {
      head.append(el('div', { class: 'post-menu' },
        el('button', { class: 'icon-btn', type: 'button', title: t('edit'), 'aria-label': t('edit'), onclick: () => openBundleDialog(g) }, svg('edit')),
        el('button', { class: 'icon-btn', type: 'button', title: t('bundle_dissolve'), 'aria-label': t('bundle_dissolve'), onclick: () => deleteBundle(g) }, svg('trash'))));
    }
    art.append(head);
    if (g.summary) art.append(el('p', { class: 'bundle-summary', text: g.summary }));
    if (g.author) art.append(el('p', { class: 'bundle-author', text: g.author }));
    art.append(el('button', { class: 'bundle-toggle', type: 'button', 'aria-expanded': String(open),
      onclick: () => { open ? state.openBundles.delete(g.id) : state.openBundles.add(g.id); render(); } },
      svg('chevron'), el('span', { text: open ? t('bundle_hide') : t('bundle_show', { n: posts.length }) })));
    if (open) {
      const list = el('div', { class: 'bundle-posts' + (wide ? ' wall' : '') });
      if (!posts.length) list.append(el('p', { class: 'column-empty', text: t('bundle_empty') }));
      for (const p of posts) list.append(renderPost(p));
      art.append(list);
    }
    return art;
  }

  // Ablagefläche: Spalte (columnId), Bündel (bundleId) oder freie Fläche (beides null = aus Bündel lösen).
  function enableDropZone(node, columnId, bundleId) {
    node.addEventListener('dragover', (ev) => { ev.preventDefault(); ev.stopPropagation(); ev.dataTransfer.dropEffect = 'move'; node.classList.add('drop-target'); });
    node.addEventListener('dragleave', (ev) => { if (!node.contains(ev.relatedTarget)) node.classList.remove('drop-target'); });
    node.addEventListener('drop', async (ev) => {
      ev.preventDefault(); ev.stopPropagation();
      node.classList.remove('drop-target');
      const data = ev.dataTransfer.getData('text/plain') || '';
      const [kind, idStr] = data.split(':');
      const id = parseInt(idStr, 10);
      if (kind === 'post') {
        const post = state.posts.get(id);
        if (!post) return;
        const target = bundleId !== undefined && bundleId !== null ? state.bundles.get(bundleId) : null;
        const newBundle = target ? target.id : null;
        const newColumn = target ? (target.column || post.column) : (columnId || post.column);
        if (post.bundle === newBundle && post.column === newColumn) return;
        const prev = { bundle: post.bundle, column: post.column };
        post.bundle = newBundle; post.column = newColumn; render();
        try { const r = await call('/posts/' + id, { json: { bundle_id: newBundle || 0, column: newColumn } }); state.posts.set(id, r.post); state.version = r.version; render(); offerUndo(r, t(newBundle ? 'log_assign' : 'log_release', { n: 1 })); }
        catch (e) { Object.assign(post, prev); render(); showError(e); }
      } else if (kind === 'bundle' && columnId) {
        const g = state.bundles.get(id);
        if (!g || g.column === columnId) return;
        const prev = g.column;
        g.column = columnId; postsInBundle(g.id).forEach(p => { p.column = columnId; }); render();
        try { const r = await call('/bundles/' + id, { json: { column: columnId } }); state.bundles.set(id, r.bundle); state.version = r.version; poll(); offerUndo(r, t('log_bundle_update', { title: g.title })); }
        catch (e) { g.column = prev; render(); showError(e); }
      }
    });
  }

  async function deleteBundle(g) {
    if (!confirm(t('bundle_dissolve_confirm'))) return;
    try {
      const r = await call('/bundles/' + g.id, { method: 'DELETE' });
      state.bundles.delete(g.id); postsInBundle(g.id).forEach(p => { p.bundle = null; });
      state.version = r.version; render(); poll();
      offerUndo(r, t('log_bundle_delete', { title: g.title }));
    } catch (e) { showError(e); }
  }

  const bdlg = $('#bundle-dialog');
  let editingBundle = null;
  function openBundleDialog(g, columnId) {
    if (!bdlg) return;
    editingBundle = g || null;
    const f = $('#bundle-form');
    f.reset();
    f.title.value = g ? g.title : '';
    f.summary.value = g ? (g.summary || '') : '';
    const radios = f.querySelectorAll('input[name="column"]');
    const col = g ? g.column : (columnId || state.activeColumn || '');
    let hit = false; radios.forEach(r => { r.checked = r.value === col; if (r.checked) hit = true; });
    if (!hit && radios[0]) radios[0].checked = true;
    $('#bundle-submit').textContent = g ? t('save') : t('bundle_create');
    bdlg.showModal();
    setTimeout(() => f.title.focus(), 30);
  }
  if (bdlg) {
    $('#bundle-cancel').addEventListener('click', () => bdlg.close());
    $('#bundle-form').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const f = ev.target;
      const r0 = f.querySelector('input[name="column"]:checked');
      const payload = { title: f.title.value.trim(), summary: f.summary.value.trim() };
      if (r0) payload.column = r0.value;
      if (!payload.title) return;
      try {
        const r = editingBundle ? await call('/bundles/' + editingBundle.id, { json: payload }) : await call('/bundles', { json: payload });
        state.bundles.set(r.bundle.id, r.bundle);
        state.version = r.version;
        bdlg.close(); render(); poll();
        if (editingBundle) offerUndo(r, t('log_bundle_update', { title: r.bundle.title }));
      } catch (e) { showError(e); }
    });
    const nb = $('#new-bundle');
    if (nb) nb.addEventListener('click', () => openBundleDialog(null));
    bdlg.addEventListener('close', () => render());
  }

  function renderReactions(type, id) {
    const box = el('div', { class: 'reactions' });
    const key = type + ':' + id;
    const rx = state.reactions.get(key) || new Map();
    for (const emoji of board.settings.emojis) {
      const r = rx.get(emoji);
      if (!r || !r.n) continue;
      box.append(el('button', { class: 'chip' + (r.mine ? ' mine' : ''), type: 'button', onclick: () => react(type, id, emoji) },
        el('span', { text: emoji }), el('span', { text: String(r.n) })));
    }
    if (reactionsOn() && canWrite()) {
      const open = state.pickerOpen === key;
      box.append(el('button', { class: 'chip add', type: 'button', title: t('react'), 'aria-label': t('react'),
        onclick: () => { state.pickerOpen = open ? null : key; render(); } }, open ? el('span', { text: '×' }) : svg('react')));
      if (open) {
        const picker = el('div', { class: 'picker' });
        for (const emoji of board.settings.emojis) picker.append(el('button', { type: 'button', text: emoji, onclick: () => { state.pickerOpen = null; react(type, id, emoji); } }));
        box.append(picker);
      }
    }
    return box;
  }

  function renderComments(p) {
    const sec = el('section', { class: 'comments' });
    const list = [...state.comments.values()].filter(c => c.post === p.id).sort((a, b) => a.id - b.id);
    for (const c of list) {
      const row = el('div', { class: 'comment', 'data-id': c.id, style: '--hue:' + c.hue });
      row.append(authorEl(c));
      row.append(el('p', { class: 'comment-body', text: c.body }));
      if (c.editable && canWrite()) {
        row.append(el('div', { class: 'post-menu' },
          el('button', { class: 'icon-btn', type: 'button', title: t('edit'), 'aria-label': t('edit'), onclick: () => editComment(row, c) }, svg('edit')),
          el('button', { class: 'icon-btn', type: 'button', title: t('delete'), 'aria-label': t('delete'), onclick: () => deleteComment(c) }, svg('trash'))));
      }
      const meta = el('div', { class: 'comment-meta' }, el('span', { text: timeLabel(c.created_at) }));
      meta.append(renderReactions('comment', c.id));
      row.append(meta);
      sec.append(row);
    }
    if (commentsOn() && canWrite()) {
      const input = el('input', { type: 'text', maxlength: '2000', placeholder: t('comment_placeholder'), value: state.drafts.get(p.id) || '',
        oninput: (ev) => state.drafts.set(p.id, ev.target.value) });
      const form = el('form', { class: 'comment-form', onsubmit: async (ev) => {
        ev.preventDefault();
        const body = input.value.trim();
        if (!body) return;
        try {
          const r = await call('/posts/' + p.id + '/comments', { json: { body } });
          state.comments.set(r.comment.id, r.comment);
          state.drafts.delete(p.id);
          state.version = r.version;
          render();
        } catch (e) { showError(e); }
      } }, input, el('button', { type: 'submit', text: t('send') }));
      sec.append(form);
    }
    return sec;
  }

  function editComment(row, c) {
    row.classList.add('editing');
    const input = el('textarea', { maxlength: '2000', rows: '3' });
    input.value = c.body;
    input.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' && (ev.metaKey || ev.ctrlKey)) form.requestSubmit(); if (ev.key === 'Escape') render(); });
    const form = el('form', { class: 'comment-edit', onsubmit: async (ev) => {
      ev.preventDefault();
      try {
        const r = await call('/comments/' + c.id, { json: { body: input.value.trim() } });
        state.comments.set(r.comment.id, r.comment); state.version = r.version; render();
      } catch (e) { showError(e); }
    } }, input, el('div', { class: 'comment-edit-actions' },
      el('button', { type: 'submit', class: 'btn-primary', text: t('save') }),
      el('button', { type: 'button', class: 'btn-ghost', text: t('cancel'), onclick: render })));
    row.querySelector('.comment-body').after(form);
    input.focus();
    input.setSelectionRange(input.value.length, input.value.length);
  }

  async function deleteComment(c) {
    if (!confirm(t('delete_comment_confirm'))) return;
    try { const r = await call('/comments/' + c.id, { method: 'DELETE' }); state.comments.delete(c.id); state.version = r.version; render(); offerUndo(r, t('log_comment_delete')); }
    catch (e) { showError(e); }
  }

  async function deletePost(p) {
    if (!confirm(t('delete_confirm'))) return;
    try { const r = await call('/posts/' + p.id, { method: 'DELETE' }); state.posts.delete(p.id); state.version = r.version; render(); offerUndo(r, t('log_post_delete')); }
    catch (e) { showError(e); }
  }

  async function react(type, id, emoji) {
    const key = type + ':' + id;
    if (!state.reactions.has(key)) state.reactions.set(key, new Map());
    const rx = state.reactions.get(key);
    const cur = rx.get(emoji) || { n: 0, mine: false };
    rx.set(emoji, cur.mine ? { n: cur.n - 1, mine: false } : { n: cur.n + 1, mine: true });
    render();
    try { const r = await call('/react', { json: { type, id, emoji } }); state.version = r.version; }
    catch (e) { rx.set(emoji, cur); render(); showError(e); }
  }

  function updateStatusNotice() {
    if (!me.owner && me.blocked) notice(t('blocked'), true);
    else if (!canWrite()) notice(t('board_closed'), true);
    else if ($('#notice').classList.contains('warn') && $('#notice').textContent === t('board_closed')) notice(null);
    const hb = $('#history-btn'); if (hb) hb.hidden = false;
    $('#new-post').hidden = !canWrite();
    $('#fab').hidden = !canWrite();
  }

  /* ---------- Eingabe ---------- */
  const dlg = $('#composer'), form = $('#composer-form');
  let pendingFile = null, removeImage = false, editing = null;

  function openComposer(post, columnId) {
    editing = post || null;
    pendingFile = null; removeImage = false;
    form.reset();
    form.post_id.value = post ? post.id : '';
    setColumnChoice(post ? (post.column || '') : (columnId || state.activeColumn || ''));
    form.title.value = post ? (post.title || '') : '';
    form.body.value = post ? post.body : '';
    form.link.value = post ? (post.link || '') : '';
    form.link.parentElement.hidden = !board.settings.links && !me.owner;
    $('.image-pick').hidden = !board.settings.images && !me.owner;
    setPreview(post && post.image ? post.thumb || post.image : null);
    $('#composer-error').hidden = true;
    $('#composer-submit').textContent = post ? t('save') : t('publish');
    dlg.showModal();
    setTimeout(() => (post && post.title ? form.title : form.body).focus(), 30);
  }
  function setColumnChoice(id) {
    const radios = form.querySelectorAll('input[name="column"][type="radio"]');
    if (!radios.length) { if (form.column) form.column.value = id; return; }
    let hit = false;
    radios.forEach(r => { r.checked = r.value === id; if (r.checked) hit = true; });
    if (!hit) radios[0].checked = true;
  }
  function columnChoice() {
    const r = form.querySelector('input[name="column"]:checked');
    return r ? r.value : (form.column && form.column.value ? form.column.value : '');
  }
  function setPreview(src) {
    const img = $('#image-preview');
    img.hidden = !src; if (src) img.src = src;
    $('#image-remove').hidden = !src;
  }
  form.image.addEventListener('change', () => {
    const f = form.image.files[0];
    if (!f) return;
    if (f.size > 10 * 1024 * 1024) { form.image.value = ''; showComposerError(t('image_too_large')); return; }
    pendingFile = f; removeImage = false;
    setPreview(URL.createObjectURL(f));
  });
  $('#image-remove').addEventListener('click', () => { pendingFile = null; removeImage = true; form.image.value = ''; setPreview(null); });
  $('#composer-cancel').addEventListener('click', () => dlg.close());
  function showComposerError(msg) { const e = $('#composer-error'); e.textContent = msg; e.hidden = false; }

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const fd = new FormData();
    fd.append('title', form.title.value);
    fd.append('body', form.body.value);
    fd.append('link', form.link.value);
    const col = columnChoice();
    if (col) fd.append('column', col);
    if (pendingFile) fd.append('image', pendingFile);
    if (removeImage) fd.append('remove_image', '1');
    const hasImage = pendingFile || (editing && editing.image && !removeImage);
    if (!form.body.value.trim() && !hasImage && !form.link.value.trim()) { showComposerError(t('body_required')); return; }
    const btn = $('#composer-submit');
    btn.disabled = true;
    try {
      const r = await call(editing ? '/posts/' + editing.id : '/posts', { form: fd });
      state.posts.set(r.post.id, r.post);
      state.version = r.version;
      dlg.close();
      render();
      const node = document.querySelector('.post[data-id="' + r.post.id + '"]');
      if (node) { node.classList.add('is-new'); if (!editing) node.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
    } catch (e) {
      showComposerError(S[e.message] ? t(e.message) : t('error_generic'));
    } finally { btn.disabled = false; }
  });
  $('#new-post').addEventListener('click', () => openComposer(null));
  $('#fab').addEventListener('click', () => openComposer(null));

  /* ---------- Bild gross ---------- */
  const lb = $('#lightbox');
  function openLightbox(src) { lb.querySelector('img').src = src; lb.showModal(); }
  lb.addEventListener('click', () => lb.close());

  /* ---------- Präsentationsmodus ---------- */
  $('#presenter').addEventListener('click', () => {
    const on = document.body.classList.toggle('presenter');
    $('#presenter').title = on ? t('presenter_exit') : t('presenter');
    if (on && document.documentElement.requestFullscreen) document.documentElement.requestFullscreen().catch(() => {});
    if (!on && document.fullscreenElement) document.exitFullscreen().catch(() => {});
  });
  document.addEventListener('fullscreenchange', () => { if (!document.fullscreenElement) document.body.classList.remove('presenter'); });
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape' && document.body.classList.contains('presenter')) document.body.classList.remove('presenter'); });

  /* ---------- Zugangs-Panel (Kurzlink und QR-Code) ---------- */
  const access = $('#access'), accessBtn = $('#access-btn'), accessBig = $('#access-big');
  const accessStore = 'wb_access:' + board.key;
  function setAccess(on, remember) {
    access.hidden = !on;
    document.body.classList.toggle('access-open', on);
    accessBtn.setAttribute('aria-expanded', on ? 'true' : 'false');
    accessBtn.classList.toggle('is-active', on);
    accessBtn.title = on ? t('access_hide') : t('access');
    accessBtn.setAttribute('aria-label', accessBtn.title);
    if (remember) { try { localStorage.setItem(accessStore, on ? '1' : '0'); } catch (e) { /* ohne Speicher nur für diese Sitzung */ } }
  }
  let accessInitial = false;
  try { accessInitial = localStorage.getItem(accessStore) === '1'; } catch (e) { /* ignorieren */ }
  setAccess(accessInitial, false);
  accessBtn.addEventListener('click', () => setAccess(access.hidden, true));
  $('#access-close').addEventListener('click', () => setAccess(false, true));
  $('#access-enlarge').addEventListener('click', () => { if (accessBig.showModal) accessBig.showModal(); });
  $('#access-big-close').addEventListener('click', () => accessBig.close());
  accessBig.addEventListener('click', (ev) => { if (ev.target === accessBig || ev.target.closest('.access-big-inner')) accessBig.close(); });
  $('#access-copy').addEventListener('click', async (ev) => {
    const b = ev.currentTarget, url = b.dataset.url;
    try { await navigator.clipboard.writeText(url); } catch (e) { window.prompt(t('access_copy'), url); return; }
    b.textContent = t('access_copied');
    setTimeout(() => { b.textContent = t('access_copy'); }, 1600);
  });

  /* ---------- Abfrage ---------- */
  let failures = 0, timer = null;
  async function poll() {
    clearTimeout(timer);
    if (document.hidden) { timer = setTimeout(poll, 15000); return; }
    try {
      const res = await fetch(api + '/state?since=' + encodeURIComponent(state.version), { credentials: 'same-origin', cache: 'no-store' });
      if (!res.ok) throw new Error('http ' + res.status);
      const data = await res.json();
      if (failures) { failures = 0; notice(null); }
      if (data.changed) {
        const prevStatus = state.status;
        applyState(data);
        if (dlg.open || (bdlg && bdlg.open)) {
          // Nicht neu zeichnen, während jemand schreibt; nur Zustand übernehmen.
        } else render();
        if (prevStatus !== state.status) updateStatusNotice();
      }
    } catch (e) {
      failures++;
      if (failures >= 3) notice(t('offline'), true);
    }
    timer = setTimeout(poll, Math.min(3000 * Math.pow(1.6, Math.max(0, failures - 1)), 30000));
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
  dlg.addEventListener('close', () => render());

  /* ---------- Start ---------- */
  applyState(Object.assign({ full: true }, W.state));
  render();
  timer = setTimeout(poll, 3000);
  setInterval(() => { if (!dlg.open && !(bdlg && bdlg.open) && !(hdlg && hdlg.open) && !(kdlg && kdlg.open) && !(adlg && adlg.open)) render(); }, 60000); // Zeitangaben auffrischen
})();
