/* Gemeinschaftsprüfung (Seite einer Aktivität vom Typ exam), Etappe G1: Fragen beisteuern */
(function () {
  'use strict';
  const W = window.WT;
  const S = W.strings;
  const A = W.activity;
  const me = W.me;
  const api = W.api;
  const L = W.limits;
  const $ = (s) => document.querySelector(s);
  const t = (k, vars) => { let s = S[k] || k; if (vars) for (const v in vars) s = s.split('{' + v + '}').join(vars[v]); return s; };
  const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; };

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
  let busy = false;
  const canWrite = () => !me.owner && state.status === 'open' && state.phase === 'collect';

  /* ---------- Aufrufe ---------- */
  async function call(path, json) {
    const init = { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(json || {}) };
    if (storedUid) init.headers['X-Guest-Key'] = storedUid;
    const res = await fetch(api + path, init);
    let data = null;
    try { data = await res.json(); } catch (e) { /* leer */ }
    if (!res.ok || !data || !data.ok) throw new Error((data && data.error) || t('error_generic'));
    return data;
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

  /* ---------- Anzeige ---------- */
  function renderHead() {
    const lead = $('#lead'), sub = $('#sub'), count = $('#count');
    sub.textContent = '';
    if (state.status !== 'open') {
      lead.textContent = t('exam_closed');
    } else if (state.phase === 'running') {
      lead.textContent = t('exam_phase_running');
    } else if (state.phase === 'results') {
      lead.textContent = t('exam_phase_results');
    } else if (me.owner) {
      lead.textContent = t('exam_owner_hint');
    } else {
      lead.textContent = t('exam_intro');
      const parts = [];
      if (state.me && state.me.name) parts.push(t('exam_you_are', { name: state.me.name }));
      if (state.quota_max > 0) parts.push(state.quota_max === 1 ? t('exam_quota_info_one') : t('exam_quota_info', { n: state.quota_max }));
      if (state.quota_min > 0) parts.push(t('exam_quota_min', { n: state.quota_min }));
      sub.textContent = parts.join(' ');
    }
    if (me.owner && state.counts) {
      const c = state.counts;
      count.textContent = t('exam_owner_counts', { total: c.total, people: c.contributors, accepted: c.accepted, submitted: c.submitted });
    } else if (state.phase === 'collect') {
      count.textContent = state.count === 1 ? t('exam_count_one') : t('exam_count', { n: state.count });
    } else count.textContent = '';
    if (!me.owner && state.phase === 'collect' && state.status === 'open' && state.quota_max > 0 && state.quota_used >= state.quota_max) {
      sub.textContent = (sub.textContent ? sub.textContent + ' ' : '') + t('exam_quota_full');
    }
  }

  function questionCard(q) {
    const li = el('li', 'exam-q status-' + q.status);
    const head = el('div', 'exam-q-head');
    head.appendChild(el('span', 'exam-pill type', t('exam_type_' + q.type)));
    head.appendChild(el('span', 'exam-pill st-' + q.status, t('exam_status_' + q.status)));
    li.appendChild(head);
    li.appendChild(el('p', 'exam-q-prompt', q.prompt));
    if (q.type === 'open') {
      const p = el('p', 'exam-q-solution');
      p.appendChild(el('strong', '', t('exam_solution') + ': '));
      p.appendChild(document.createTextNode(q.solution));
      li.appendChild(p);
    } else {
      const ul = el('ul', 'exam-q-opts' + (q.type === 'truefalse' ? ' tf' : ''));
      q.options.forEach((o) => {
        const item = el('li', o.correct ? 'is-correct' : '');
        const mark = el('span', 'exam-mark', q.type === 'truefalse' ? (o.correct ? t('exam_true') : t('exam_false')) : (o.correct ? '✓' : ''));
        item.appendChild(mark);
        item.appendChild(el('span', '', o.text));
        ul.appendChild(item);
      });
      li.appendChild(ul);
    }
    if (q.status === 'rejected') li.appendChild(el('p', 'exam-hint', t('exam_rejected_hint')));
    if (q.status === 'submitted' && canWrite()) {
      const act = el('div', 'exam-q-actions');
      const edit = el('button', 'btn-ghost small', t('exam_edit'));
      edit.type = 'button';
      edit.addEventListener('click', () => openForm(q));
      const wd = el('button', 'btn-ghost small danger', t('exam_withdraw'));
      wd.type = 'button';
      wd.addEventListener('click', async () => {
        if (!window.confirm(t('exam_withdraw_confirm'))) return;
        try {
          const r = await call('/questions/' + q.id + '/delete', {});
          if (editing && editing.id === q.id) closeForm();
          applyState(r.state);
          notice(t('exam_withdrawn'), false, 2500);
        } catch (e) { notice(e.message, true, 5000); }
      });
      act.appendChild(edit); act.appendChild(wd);
      li.appendChild(act);
    }
    return li;
  }

  function renderMine() {
    const box = $('#mine-box'), list = $('#mine');
    const mine = state.mine || [];
    box.hidden = me.owner || !mine.length;
    list.textContent = '';
    mine.forEach((q) => list.appendChild(questionCard(q)));
    $('#progress').textContent = state.quota_max ? t('exam_quota_progress', { used: state.quota_used || 0, max: state.quota_max }) : '';
    const nb = $('#new-q');
    nb.hidden = !canWrite() || (state.quota_used || 0) >= state.quota_max || !$('#qform').hidden;
  }

  function render() { renderHead(); renderMine(); }

  function applyState(s) {
    if (!s) return;
    if (s.config_version && s.config_version !== configVersion) { location.reload(); return; }
    state = Object.assign({}, state, s);
    if (!canWrite() && !$('#qform').hidden) closeForm();
    render();
  }

  /* ---------- Formular ---------- */
  const form = $('#qform');
  let editing = null;   // Frage, die bearbeitet wird, oder null
  let opts = [];        // [{id, text, correct}]
  const curType = () => (form.querySelector('input[name="type"]:checked') || {}).value || 'single';

  function blankOpts(type) {
    const n = type === 'truefalse' ? 3 : 4;
    return Array.from({ length: n }, () => ({ text: '', correct: type === 'truefalse' }));
  }

  function renderOpts() {
    const type = curType();
    const isTF = type === 'truefalse';
    $('#opt-box').hidden = type === 'open';
    $('#sol-box').hidden = type !== 'open';
    $('#type-hint').textContent = t('exam_type_' + type + '_hint');
    if (type === 'open') return;
    $('#opt-label').textContent = t('exam_options_' + type);
    const ul = $('#opts');
    ul.textContent = '';
    opts.forEach((o, i) => {
      const li = el('li', 'exam-opt');
      if (isTF) {
        const sw = el('div', 'exam-tf');
        [true, false].forEach((val) => {
          const b = el('button', 'exam-tf-btn' + (o.correct === val ? ' on' : ''), val ? t('exam_true') : t('exam_false'));
          b.type = 'button';
          b.setAttribute('aria-pressed', o.correct === val ? 'true' : 'false');
          b.addEventListener('click', () => { o.correct = val; renderOpts(); });
          sw.appendChild(b);
        });
        li.appendChild(sw);
      } else {
        const mark = document.createElement('input');
        mark.type = type === 'single' ? 'radio' : 'checkbox';
        mark.name = 'correct';
        mark.className = 'exam-check';
        mark.checked = !!o.correct;
        mark.setAttribute('aria-label', t('exam_correct_mark'));
        mark.addEventListener('change', () => {
          if (type === 'single') opts.forEach((x, j) => { x.correct = j === i; });
          else o.correct = mark.checked;
        });
        li.appendChild(mark);
      }
      const inp = document.createElement('input');
      inp.type = 'text';
      inp.className = 'exam-opt-text';
      inp.maxLength = L.option;
      inp.value = o.text;
      inp.placeholder = t(isTF ? 'exam_statement_ph' : 'exam_option_ph', { n: i + 1 });
      inp.addEventListener('input', () => { o.text = inp.value; });
      li.appendChild(inp);
      const rm = el('button', 'exam-opt-rm', '×');
      rm.type = 'button';
      rm.title = t('exam_remove');
      rm.setAttribute('aria-label', t('exam_remove'));
      rm.disabled = opts.length <= L.min_options;
      rm.addEventListener('click', () => { opts.splice(i, 1); renderOpts(); });
      li.appendChild(rm);
      ul.appendChild(li);
    });
    const add = $('#add-opt');
    add.textContent = t(isTF ? 'exam_add_statement' : 'exam_add_option');
    add.hidden = opts.length >= L.max_options;
  }

  form.querySelectorAll('input[name="type"]').forEach((r) => r.addEventListener('change', () => {
    const type = curType();
    if (type === 'single') { let seen = false; opts.forEach((o) => { if (o.correct && !seen) seen = true; else o.correct = false; }); }
    if (type === 'truefalse' && opts.every((o) => !o.correct)) opts.forEach((o) => { o.correct = true; });
    if (type !== 'open' && !opts.length) opts = blankOpts(type);
    renderOpts();
  }));
  $('#add-opt').addEventListener('click', () => {
    if (opts.length >= L.max_options) return;
    opts.push({ text: '', correct: curType() === 'truefalse' });
    renderOpts();
    const inputs = form.querySelectorAll('.exam-opt-text');
    if (inputs.length) inputs[inputs.length - 1].focus();
  });

  function openForm(q) {
    editing = q || null;
    const type = q ? q.type : 'single';
    form.querySelectorAll('input[name="type"]').forEach((r) => { r.checked = r.value === type; });
    $('#q-prompt').value = q ? q.prompt : '';
    $('#q-solution').value = q && q.solution ? q.solution : '';
    opts = q && q.type !== 'open' ? q.options.map((o) => ({ id: o.id, text: o.text, correct: !!o.correct })) : blankOpts(type);
    $('#q-save').textContent = q ? t('exam_save_changes') : t('exam_save');
    $('#form-error').hidden = true;
    form.hidden = false;
    renderOpts();
    renderMine();
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    $('#q-prompt').focus({ preventScroll: true });
  }
  function closeForm() {
    form.hidden = true;
    editing = null;
    renderMine();
  }
  $('#new-q').addEventListener('click', () => openForm(null));
  $('#q-cancel').addEventListener('click', closeForm);

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (busy) return;
    const type = curType();
    const body = { type, prompt: $('#q-prompt').value };
    if (type === 'open') body.solution = $('#q-solution').value;
    else body.options = opts.filter((o) => o.text.trim() !== '' || o.correct).map((o) => (o.id ? { id: o.id, text: o.text, correct: o.correct } : { text: o.text, correct: o.correct }));
    busy = true;
    $('#q-save').disabled = true;
    try {
      const r = await call(editing ? '/questions/' + editing.id : '/questions', body);
      const wasEdit = !!editing;
      closeForm();
      applyState(r.state);
      notice(wasEdit ? t('exam_updated') : t('exam_saved'), false, 2500);
    } catch (e) {
      const fe = $('#form-error');
      fe.textContent = e.message;
      fe.hidden = false;
    } finally {
      busy = false;
      $('#q-save').disabled = false;
    }
  });

  /* ---------- Zugangs-Panel (wie auf der Board-Seite) ---------- */
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

  /* ---------- Abfrage ---------- */
  let failures = 0, timer = null;
  async function poll() {
    clearTimeout(timer);
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

  render();
  timer = setTimeout(poll, 3000);
})();
