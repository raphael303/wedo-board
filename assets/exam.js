/* Gemeinschaftsprüfung (Seite einer Aktivität vom Typ exam): Fragen beisteuern (G1), Prüfung lösen, Ergebnis, Lösungen und Rangliste (G2) */
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
    if (!res.ok || !data || !data.ok) {
      const err = new Error((data && data.error) || t('error_generic'));
      err.code = data && data.code; err.status = res.status;
      throw err;
    }
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
      const parts = [];
      if (state.me && state.me.name) parts.push(t('exam_you_solve', { name: state.me.name }));
      if (state.ends_at) parts.push(t('exam_ends_at', { t: clock(state.ends_at) }));
      sub.textContent = parts.join(' ');
    } else if (state.phase === 'results') {
      lead.textContent = t('exam_phase_results');
      if (state.me && state.me.name) sub.textContent = t('exam_you_are_name', { name: state.me.name });
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
    if (state.phase === 'collect' && !me.owner && state.status === 'open' && state.quota_max > 0 && state.quota_used >= state.quota_max) {
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
    box.hidden = me.owner || !mine.length || state.phase !== 'collect';
    list.textContent = '';
    mine.forEach((q) => list.appendChild(questionCard(q)));
    $('#progress').textContent = state.quota_max ? t('exam_quota_progress', { used: state.quota_used || 0, max: state.quota_max }) : '';
    const nb = $('#new-q');
    nb.hidden = !canWrite() || (state.quota_used || 0) >= state.quota_max || !$('#qform').hidden;
  }


  /* ---------- Prüfung lösen (Phasen running und results, Etappe G2) ---------- */
  const run = $('#run');
  let runKey = '';            // bestimmt, ob die Fragen neu gezeichnet werden (nie während des Tippens)
  let local = {};             // lokale Antworten des laufenden Versuchs
  let queue = {};             // noch nicht gespeicherte Antworten
  let saveTimer = null, saving = false, saveState = '';
  let deadlineAt = null, tick = null, autoSubmitted = false;
  const fmtNum = (n) => (n === null || n === undefined) ? '–' : String(Math.round(n * 100) / 100).replace('.', S.decimal_sep || '.');
  const pad = (n) => (n < 10 ? '0' : '') + n;
  const clock = (iso) => { if (!iso) return ''; const d = new Date(iso); return pad(d.getHours()) + ':' + pad(d.getMinutes()); };

  async function ownerCall(path, json) {
    const res = await fetch(api + path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': W.csrf || '' }, body: JSON.stringify(json || {}) });
    let data = null;
    try { data = await res.json(); } catch (e) { /* leer */ }
    if (!res.ok || !data || !data.ok) throw new Error((data && data.error) || t('error_generic'));
    return data;
  }

  function setSaveState(s) {
    saveState = s;
    const n = run.querySelector('.exam-save-state');
    if (n) { n.textContent = s ? t('exam_' + s) : ''; n.className = 'exam-save-state ' + s; }
  }

  async function flush() {
    clearTimeout(saveTimer);
    if (saving || !Object.keys(queue).length) return;
    const batch = queue; queue = {};
    saving = true; setSaveState('ans_saving');
    try {
      const r = await call('/attempt/answer', { answers: batch });
      if (typeof r.seconds_left === 'number') setDeadline(r.seconds_left);
      setSaveState(Object.keys(queue).length ? 'ans_saving' : 'ans_saved');
    } catch (e) {
      // Nicht gespeichert: zurück in die Warteschlange (neuere Eingaben gehen vor) und später erneut versuchen.
      queue = Object.assign({}, batch, queue);
      setSaveState('ans_save_failed');
      notice(e.message, true, 5000);
      if (e.status === 409 || e.status === 403) { queue = {}; refresh(); }
      else saveTimer = setTimeout(flush, 4000);
    } finally {
      saving = false;
      if (Object.keys(queue).length && saveState !== 'ans_save_failed') saveTimer = setTimeout(flush, 400);
    }
  }
  function queueAnswer(qid, value) {
    local[qid] = value; queue[qid] = value;
    setSaveState('ans_saving');
    clearTimeout(saveTimer);
    saveTimer = setTimeout(flush, 700);
  }
  window.addEventListener('pagehide', () => {
    if (!Object.keys(queue).length) return;
    const h = { 'Content-Type': 'application/json' };
    if (storedUid) h['X-Guest-Key'] = storedUid;
    try { fetch(api + '/attempt/answer', { method: 'POST', credentials: 'same-origin', keepalive: true, headers: h, body: JSON.stringify({ answers: queue }) }); } catch (e) { /* ignorieren */ }
  });

  async function refresh() {
    try {
      const init = { credentials: 'same-origin', cache: 'no-store', headers: {} };
      if (storedUid) init.headers['X-Guest-Key'] = storedUid;
      const res = await fetch(api + '/state', init);
      const data = await res.json();
      if (data && data.changed) applyState(data);
    } catch (e) { /* nächster Abgleich */ }
  }

  function setDeadline(secondsLeft) {
    deadlineAt = typeof secondsLeft === 'number' ? Date.now() + secondsLeft * 1000 : null;
    clearInterval(tick);
    if (deadlineAt) { tick = setInterval(updateTimer, 1000); updateTimer(); }
  }
  function updateTimer() {
    const el2 = run.querySelector('.exam-timer');
    if (!deadlineAt) { if (el2) el2.hidden = true; return; }
    const left = Math.max(0, Math.round((deadlineAt - Date.now()) / 1000));
    if (el2) {
      el2.hidden = false;
      el2.textContent = t('exam_time_left', { t: Math.floor(left / 60) + ':' + pad(left % 60) });
      el2.classList.toggle('low', left <= 60);
    }
    if (left <= 0 && !autoSubmitted) {
      autoSubmitted = true;
      clearInterval(tick);
      submitAttempt(true);
    }
  }

  async function submitAttempt(auto) {
    if (busy) return;
    busy = true;
    try {
      await flush();
      const r = await call('/attempt/submit', {});
      runKey = '';
      applyState(r.state);
      notice(auto ? t('exam_auto_submitted') : t('exam_submitted_ok'), false, 4000);
    } catch (e) {
      notice(e.message, true, 5000);
      refresh();
    } finally { busy = false; }
  }

  async function startAttempt(kind) {
    if (busy) return;
    busy = true;
    try {
      const r = await call('/attempt/' + kind, {});
      runKey = '';
      applyState(r.state);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (e) { notice(e.message, true, 5000); }
    finally { busy = false; }
  }

  function scoreText(r) {
    if (!r) return '';
    const pts = t('exam_score_points', { s: fmtNum(r.score), m: fmtNum(r.max_score) });
    const pct = t('exam_score_percent', { p: fmtNum(r.percent) });
    let s = r.display === 'percent' ? pct + ' (' + pts + ')' : pts + ' (' + pct + ')';
    if (r.grade !== null && r.grade !== undefined) s += ' · ' + t('exam_grade', { g: fmtNum(r.grade) });
    return s;
  }

  /** Eingabe für eine Frage im laufenden Versuch. */
  function questionInput(q) {
    const box = el('div', 'exam-answer');
    const name = 'q' + q.id;
    if (q.type === 'open') {
      const ta = document.createElement('textarea');
      ta.rows = 3; ta.maxLength = W.answer_max || 1000; ta.className = 'exam-open';
      ta.value = typeof local[q.id] === 'string' ? local[q.id] : '';
      ta.placeholder = t('exam_open_ph');
      ta.setAttribute('aria-label', t('exam_your_answer'));
      ta.addEventListener('input', () => queueAnswer(q.id, ta.value));
      box.appendChild(ta);
      return box;
    }
    if (q.type === 'truefalse') {
      const cur = (local[q.id] && typeof local[q.id] === 'object' && !Array.isArray(local[q.id])) ? local[q.id] : {};
      q.options.forEach((o) => {
        const row = el('div', 'exam-tf-row');
        row.appendChild(el('span', 'exam-tf-text', o.text));
        const sw = el('div', 'exam-tf');
        [true, false].forEach((val) => {
          const b = el('button', 'exam-tf-btn' + (cur[o.id] === val ? ' on' : ''), val ? t('exam_true') : t('exam_false'));
          b.type = 'button';
          b.setAttribute('aria-pressed', cur[o.id] === val ? 'true' : 'false');
          b.addEventListener('click', () => {
            const next = Object.assign({}, cur);
            if (next[o.id] === val) delete next[o.id]; else next[o.id] = val;
            Object.assign(cur, next); Object.keys(cur).forEach((k) => { if (!(k in next)) delete cur[k]; });
            queueAnswer(q.id, next);
            sw.querySelectorAll('button').forEach((x, i) => { const on = next[o.id] === (i === 0); x.classList.toggle('on', on); x.setAttribute('aria-pressed', on ? 'true' : 'false'); });
          });
          sw.appendChild(b);
        });
        row.appendChild(sw);
        box.appendChild(row);
      });
      return box;
    }
    const multi = q.type === 'multi';
    if (multi) box.appendChild(el('p', 'exam-hint', t('exam_multi_hint')));
    q.options.forEach((o) => {
      const lab = el('label', 'exam-choice');
      const inp = document.createElement('input');
      inp.type = multi ? 'checkbox' : 'radio';
      inp.name = name; inp.value = o.id;
      inp.checked = multi ? Array.isArray(local[q.id]) && local[q.id].includes(o.id) : local[q.id] === o.id;
      inp.addEventListener('change', () => {
        if (multi) queueAnswer(q.id, Array.from(box.querySelectorAll('input:checked')).map((x) => x.value));
        else queueAnswer(q.id, o.id);
      });
      lab.appendChild(inp);
      lab.appendChild(el('span', '', o.text));
      box.appendChild(lab);
    });
    return box;
  }

  /** Abgegebene Antwort, mit Lösung und Punkten, wenn sie gezeigt werden dürfen. */
  function questionReview(q, att) {
    const sol = att.solutions ? att.solutions[q.id] : null;
    const pts = att.result ? att.result.per_question[q.id] : undefined;
    const box = el('div', 'exam-answer review');
    const ans = q.answer;
    if (q.type === 'open') {
      box.appendChild(el('p', 'exam-given', (typeof ans === 'string' && ans.trim()) ? ans : t('exam_no_answer')));
      if (sol) { const p = el('p', 'exam-q-solution'); p.appendChild(el('strong', '', t('exam_solution') + ': ')); p.appendChild(document.createTextNode(sol.solution || '')); box.appendChild(p); }
    } else {
      const ul = el('ul', 'exam-review-opts');
      q.options.forEach((o) => {
        let mine;
        if (q.type === 'single') mine = ans === o.id;
        else if (q.type === 'multi') mine = Array.isArray(ans) && ans.includes(o.id);
        else mine = ans && typeof ans === 'object' && o.id in ans ? ans[o.id] : null;
        const correct = sol ? sol.correct.includes(o.id) : null;
        const li = el('li', '');
        let markText;
        if (q.type === 'truefalse') markText = mine === null ? '–' : (mine ? t('exam_true') : t('exam_false'));
        else markText = mine ? (q.type === 'single' ? '●' : '☑') : (q.type === 'single' ? '○' : '☐');
        li.appendChild(el('span', 'exam-mine-mark', markText));
        li.appendChild(el('span', 'exam-opt-label', o.text));
        if (sol) {
          const ok = q.type === 'truefalse' ? mine === correct : (q.type === 'single' ? (!mine || correct) : mine === correct);
          if (q.type === 'truefalse') li.appendChild(el('span', 'exam-sol-mark ' + (ok ? 'ok' : 'no'), correct ? t('exam_true') : t('exam_false')));
          else if (correct) li.appendChild(el('span', 'exam-sol-mark ok', '✓ ' + t('exam_correct_mark')));
          li.classList.add(ok ? 'is-ok' : 'is-wrong');
        }
        ul.appendChild(li);
      });
      box.appendChild(ul);
    }
    if (pts !== undefined) box.appendChild(el('p', 'exam-q-points', pts === null ? t('exam_points_pending') : t('exam_points_got', { s: fmtNum(pts), m: fmtNum(q.points) })));
    return box;
  }

  function attemptCard(att) {
    const frag = document.createDocumentFragment();
    const running = att.status === 'in_progress';
    const head = el('section', 'exam-card exam-att-head');
    if (att.no > 1) head.appendChild(el('p', 'exam-pill practice', t('exam_practice', { n: att.no })));
    if (running) {
      head.appendChild(el('p', 'exam-lead', att.questions.length === 1 ? t('exam_running_lead_one') : t('exam_running_lead', { n: att.questions.length })));
      const meta = el('div', 'exam-att-meta');
      const tm = el('span', 'exam-timer'); tm.hidden = true; meta.appendChild(tm);
      meta.appendChild(el('span', 'exam-save-state ' + saveState, saveState ? t('exam_' + saveState) : ''));
      head.appendChild(meta);
    } else {
      head.appendChild(el('p', 'exam-lead', t('exam_done_lead')));
      if (att.result) head.appendChild(el('p', 'exam-score', scoreText(att.result)));
      else head.appendChild(el('p', 'exam-sub', t('exam_result_later')));
      if (!att.solutions) head.appendChild(el('p', 'exam-hint', t('exam_solutions_later')));
      if (state.can_retry) {
        const b = el('button', 'btn-primary', t('exam_retry'));
        b.type = 'button';
        b.addEventListener('click', () => { if (window.confirm(t('exam_retry_confirm'))) startAttempt('retry'); });
        const p = el('p', 'exam-actions'); p.appendChild(b); head.appendChild(p);
      }
    }
    frag.appendChild(head);
    const list = el('ol', 'exam-list exam-run-list');
    att.questions.forEach((q) => {
      const li = el('li', 'exam-q exam-run-q');
      li.id = 'rq' + q.id;
      const h = el('div', 'exam-q-head');
      h.appendChild(el('span', 'exam-pill', t('exam_q_of', { n: q.n, total: att.questions.length })));
      h.appendChild(el('span', 'exam-pill type', t('exam_type_' + q.type)));
      h.appendChild(el('span', 'exam-pill', t('exam_points', { n: fmtNum(q.points) })));
      if (q.own) h.appendChild(el('span', 'exam-pill own', q.excluded ? t('exam_own_excluded') : t('exam_own')));
      li.appendChild(h);
      li.appendChild(el('p', 'exam-q-prompt', q.prompt));
      li.appendChild(running ? questionInput(q) : questionReview(q, att));
      list.appendChild(li);
    });
    frag.appendChild(list);
    if (running) {
      const foot = el('section', 'exam-card exam-submit');
      const b = el('button', 'btn-primary', t('exam_submit'));
      b.type = 'button';
      b.addEventListener('click', () => {
        const open = att.questions.filter((q) => {
          const v = local[q.id];
          if (q.type === 'open') return !(typeof v === 'string' && v.trim());
          if (q.type === 'multi') return !(Array.isArray(v) && v.length);
          if (q.type === 'truefalse') return !(v && typeof v === 'object' && Object.keys(v).length === q.options.length);
          return !v;
        }).length;
        if (!window.confirm(open === 1 ? t('exam_submit_confirm_open_one') : open ? t('exam_submit_confirm_open', { n: open }) : t('exam_submit_confirm'))) return;
        submitAttempt(false);
      });
      foot.appendChild(el('p', 'exam-hint', t('exam_submit_hint')));
      foot.appendChild(b);
      frag.appendChild(foot);
    }
    return frag;
  }

  function rankingCard(list, ownerView) {
    const sec = el('section', 'exam-card exam-ranking');
    sec.appendChild(el('h2', 'exam-h2', t('exam_ranking')));
    if (!list || !list.length) { sec.appendChild(el('p', 'exam-sub', t('exam_ranking_empty'))); return sec; }
    const tbl = el('table', 'exam-rank-table');
    const percent = state.display === 'percent';
    list.forEach((r) => {
      const tr = el('tr', r.m ? 'me' : '');
      tr.appendChild(el('td', 'rank', r.rank + '.'));
      tr.appendChild(el('td', 'name', r.name + (r.m ? ' · ' + t('exam_you') : '')));
      tr.appendChild(el('td', 'val', percent ? fmtNum(r.percent) + ' %' : fmtNum(r.score) + ' / ' + fmtNum(r.max_score)));
      if (r.grade !== null && r.grade !== undefined) tr.appendChild(el('td', 'grade', fmtNum(r.grade)));
      tbl.appendChild(tr);
    });
    sec.appendChild(tbl);
    if (ownerView) sec.appendChild(el('p', 'exam-hint', state.ranking_shown ? t('exam_ranking_shown') : t('exam_ranking_hidden')));
    return sec;
  }

  function renderOwnerRun() {
    run.textContent = '';
    const c = state.attempts || { started: 0, submitted: 0, in_progress: 0 };
    const card = el('section', 'exam-card');
    card.appendChild(el('p', 'exam-lead', t('exam_owner_progress', { started: c.started, submitted: c.submitted })));
    if (state.ends_at) card.appendChild(el('p', 'exam-sub', t('exam_ends_at', { t: clock(state.ends_at) })));
    if (state.ranking_enabled && state.phase === 'results') {
      const b = el('button', 'btn-primary', state.ranking_shown ? t('exam_ranking_hide') : t('exam_ranking_show'));
      b.type = 'button';
      b.addEventListener('click', async () => {
        try { const r = await ownerCall('/reveal', { revealed: !state.ranking_shown }); applyState(r.state); } catch (e) { notice(e.message, true, 5000); }
      });
      const p = el('p', 'exam-actions'); p.appendChild(b); card.appendChild(p);
    }
    run.appendChild(card);
    if (state.ranking_enabled) run.appendChild(rankingCard(state.ranking, true));
  }

  function renderRun() {
    if (me.owner) { runKey = ''; renderOwnerRun(); return; }
    const att = state.attempt;
    const key = att ? [att.no, att.status, !!att.result, !!att.solutions, state.can_retry, state.phase, JSON.stringify(state.ranking || null)].join('|') : 'none|' + state.may_start + '|' + state.phase;
    if (att && att.status === 'in_progress') {
      setDeadline(att.seconds_left);
      if (key === runKey) return; // nichts neu zeichnen, solange jemand schreibt
      local = {};
      att.questions.forEach((q) => { if (q.answer !== null && q.answer !== undefined) local[q.id] = q.answer; });
      Object.assign(local, queue);
      autoSubmitted = false;
    } else {
      setDeadline(null);
    }
    if (key === runKey) return;
    runKey = key;
    run.textContent = '';
    if (state.ranking) run.appendChild(rankingCard(state.ranking, false));
    if (att) { run.appendChild(attemptCard(att)); updateTimer(); return; }
    const card = el('section', 'exam-card');
    if (state.phase === 'results') {
      card.appendChild(el('p', 'exam-sub', t('exam_not_taken')));
    } else if (state.may_start === 'exam_need_contribution') {
      card.appendChild(el('p', 'exam-lead', t('exam_need_contribution')));
    } else if (state.may_start === true) {
      const tl = state.time_limit || { minutes: 0 };
      if (tl.minutes > 0 && tl.per_person) {
        card.appendChild(el('p', 'exam-lead', t('exam_start_timed', { n: tl.minutes })));
        const b = el('button', 'btn-primary', t('exam_start'));
        b.type = 'button';
        b.addEventListener('click', () => startAttempt('start'));
        const p = el('p', 'exam-actions'); p.appendChild(b); card.appendChild(p);
      } else {
        card.appendChild(el('p', 'exam-sub', t('exam_loading')));
        setTimeout(() => startAttempt('start'), 0);
      }
    }
    run.appendChild(card);
  }

  function render() {
    const collecting = state.phase === 'collect' || state.status !== 'open';
    renderHead();
    if (collecting) {
      run.hidden = true; runKey = ''; setDeadline(null);
      renderMine();
    } else {
      $('#mine-box').hidden = true; $('#new-q').hidden = true;
      if (!$('#qform').hidden) closeForm();
      run.hidden = false;
      renderRun();
    }
  }

  function applyState(s) {
    if (!s) return;
    if (s.config_version && s.config_version !== configVersion) {
      if (Object.keys(queue).length) { flush().finally(() => location.reload()); return; }
      location.reload(); return;
    }
    if (state.phase === 'collect' && s.phase && s.phase !== 'collect') { delete state.mine; delete state.quota_used; }
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
