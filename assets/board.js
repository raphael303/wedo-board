/* wedo board – Gäste-Ansicht */
(function () {
  'use strict';
  const W = window.WB;
  const S = W.strings;
  const board = W.board;
  const api = W.api;
  const me = W.me;

  const state = {
    version: W.state.version,
    status: W.state.status,
    posts: new Map(),
    comments: new Map(),
    reactions: new Map(), // "post:12" -> Map(emoji -> {n, mine})
    openComments: new Set(),
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
    react: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="12" r="8"/><path d="M7 14.5s1 1.5 3 1.5 3-1.5 3-1.5"/><path d="M7.5 10h.01M12.5 10h.01"/><path d="M19 3v6M16 6h6"/></svg>',
    link: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/></svg>',
  };
  const svg = (name) => { const s = el('span'); s.innerHTML = ICONS[name]; return s.firstChild; };

  /* ---------- Zustand einpflegen ---------- */
  function applyState(st) {
    if (st.full) { state.posts.clear(); state.comments.clear(); }
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
  const canWrite = () => me.owner || state.status === 'open';
  const commentsOn = () => board.settings.comments || me.owner;
  const reactionsOn = () => board.settings.reactions || me.owner;

  async function call(path, opts = {}) {
    const init = { method: opts.method || 'POST', credentials: 'same-origin', headers: {} };
    if (opts.form) init.body = opts.form;
    else if (opts.json) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.json); }
    const res = await fetch(api + path, init);
    let data = null;
    try { data = await res.json(); } catch (e) { /* leer */ }
    if (!res.ok || !data || !data.ok) {
      const err = new Error((data && data.error) || 'error_generic');
      err.status = res.status;
      throw err;
    }
    return data;
  }
  function showError(e) {
    const msg = S[e.message] ? t(e.message) : (e.message && e.message.length < 80 ? e.message : t('error_generic'));
    notice(msg, true, 4000);
  }
  let noticeTimer = null;
  function notice(text, warn, ms) {
    const n = $('#notice');
    clearTimeout(noticeTimer);
    if (!text) { n.hidden = true; return; }
    n.textContent = text; n.className = 'notice' + (warn ? ' warn' : ''); n.hidden = false;
    if (ms) noticeTimer = setTimeout(() => { n.hidden = true; }, ms);
  }

  /* ---------- Rendern ---------- */
  const sortedPosts = () => [...state.posts.values()].sort((a, b) => a.position - b.position || a.id - b.id);

  function render() {
    const root = $('#board');
    root.replaceChildren();
    const posts = sortedPosts();
    if (board.type === 'columns') renderColumns(root, posts); else renderWall(root, posts);
    updateStatusNotice();
  }

  function renderWall(root, posts) {
    if (!posts.length) { root.append(el('p', { class: 'board-empty', text: canWrite() ? t('board_empty') : '' })); return; }
    const wall = el('div', { class: 'wall' });
    for (const p of posts) wall.append(renderPost(p));
    root.append(wall);
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
      tabs.append(el('button', {
        class: 'column-tab', role: 'tab', 'aria-selected': String(c.id === state.activeColumn),
        text: c.title + (list.length ? ' · ' + list.length : ''),
        onclick: () => { state.activeColumn = c.id; render(); },
      }));
      const body = el('div', { class: 'column-body' });
      if (!list.length) body.append(el('p', { class: 'column-empty', text: t('column_empty') }));
      for (const p of list) body.append(renderPost(p));
      wrap.append(el('section', { class: 'column' + (c.id === state.activeColumn ? ' active' : ''), 'data-column': c.id },
        el('header', { class: 'column-head' },
          el('h2', { class: 'column-title', text: c.title }),
          el('span', { class: 'column-count', text: list.length ? String(list.length) : '' }),
          canWrite() ? el('button', { class: 'column-add', type: 'button', title: t('new_post'), 'aria-label': t('new_post'), text: '+', onclick: () => openComposer(null, c.id) }) : null),
        body));
    }
    root.append(tabs, wrap);
  }

  function renderPost(p) {
    const art = el('article', { class: 'post', 'data-id': p.id, style: '--hue:' + p.hue });
    const head = el('header', { class: 'post-head' },
      el('span', { class: 'author' }, el('i', { class: 'dot' }), el('span', { class: 'name', text: p.author })),
      el('time', { text: timeLabel(p.created_at) + (p.updated_at > p.created_at ? ' · ' + t('edited') : ''), title: p.created_at }));
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
    if (commentsOn() || count) {
      foot.append(el('button', { class: 'comments-toggle', type: 'button', 'aria-expanded': String(state.openComments.has(p.id)),
        onclick: () => { state.openComments.has(p.id) ? state.openComments.delete(p.id) : state.openComments.add(p.id); render(); } },
        svg('comment'), el('span', { text: count ? String(count) : t('comment') })));
    }
    art.append(foot);
    if (state.openComments.has(p.id)) art.append(renderComments(p));
    return art;
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
      row.append(el('span', { class: 'author' }, el('i', { class: 'dot' }), el('span', { class: 'name', text: c.author })));
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
    const input = el('input', { type: 'text', maxlength: '2000', value: c.body });
    const form = el('form', { class: 'comment-edit', onsubmit: async (ev) => {
      ev.preventDefault();
      try {
        const r = await call('/comments/' + c.id, { json: { body: input.value.trim() } });
        state.comments.set(r.comment.id, r.comment); state.version = r.version; render();
      } catch (e) { showError(e); }
    } }, input, el('button', { type: 'submit', class: 'btn-primary', text: t('save') }), el('button', { type: 'button', class: 'btn-ghost', text: t('cancel'), onclick: render }));
    row.querySelector('.comment-body').after(form);
    input.focus();
  }

  async function deleteComment(c) {
    if (!confirm(t('delete_comment_confirm'))) return;
    try { const r = await call('/comments/' + c.id, { method: 'DELETE' }); state.comments.delete(c.id); state.version = r.version; render(); }
    catch (e) { showError(e); }
  }

  async function deletePost(p) {
    if (!confirm(t('delete_confirm'))) return;
    try { const r = await call('/posts/' + p.id, { method: 'DELETE' }); state.posts.delete(p.id); state.version = r.version; render(); }
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
    if (!canWrite()) notice(t('board_closed'), true);
    else if ($('#notice').classList.contains('warn') && $('#notice').textContent === t('board_closed')) notice(null);
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
    form.column.value = post ? (post.column || '') : (columnId || state.activeColumn || '');
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
    if (form.column.value) fd.append('column', form.column.value);
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
        if (dlg.open) {
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
  setInterval(() => { if (!dlg.open) render(); }, 60000); // Zeitangaben auffrischen
})();
