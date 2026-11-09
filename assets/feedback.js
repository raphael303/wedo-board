/* Rückmelde-Formular für alle Seiten. Ohne Abhängigkeiten.
 * Wird früh im <head> geladen: Der Fehlerpuffer sammelt die letzten JavaScript-Fehler der Seite,
 * damit eine Fehlermeldung den technischen Zusammenhang mitbringt. Jedes Element mit data-feedback öffnet den Dialog. */
(function () {
  'use strict';
  var cfg = window.TFB || {};
  var S = cfg.strings || {};
  var errors = [];
  var MAX = 5;

  function remember(e) {
    try {
      errors.push(e);
      if (errors.length > MAX) errors.shift();
    } catch (_) { /* nie selbst Fehler werfen */ }
  }
  window.addEventListener('error', function (ev) {
    if (!ev) return;
    var target = ev.target;
    if (target && target !== window && (target.src || target.href)) {
      remember({ message: 'Laden fehlgeschlagen: ' + (target.tagName || '') + ' ' + (target.src || target.href), at: new Date().toISOString() });
      return;
    }
    remember({
      message: String(ev.message || ''),
      source: String(ev.filename || ''),
      line: ev.lineno || 0,
      col: ev.colno || 0,
      stack: ev.error && ev.error.stack ? String(ev.error.stack).slice(0, 1500) : '',
      at: new Date().toISOString()
    });
  }, true);
  window.addEventListener('unhandledrejection', function (ev) {
    var r = ev && ev.reason;
    remember({
      message: 'Unhandled rejection: ' + (r && r.message ? r.message : String(r)),
      stack: r && r.stack ? String(r.stack).slice(0, 1500) : '',
      at: new Date().toISOString()
    });
  });

  function el(tag, attrs, children) {
    var n = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'text') n.textContent = attrs[k];
      else if (k === 'class') n.className = attrs[k];
      else n.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) { if (c) n.appendChild(c); });
    return n;
  }

  function context() {
    var c = cfg.context || {};
    var out = {
      url: location.href.split('#')[0],
      page: c.page || '',
      board_key: c.board_key || '',
      activity_key: c.activity_key || '',
      owner: !!c.owner,
      version: cfg.version || '',
      user_agent: navigator.userAgent,
      viewport: window.innerWidth + 'x' + window.innerHeight,
      screen: (screen.width || 0) + 'x' + (screen.height || 0) + '@' + (window.devicePixelRatio || 1),
      language: (navigator.languages || [navigator.language]).slice(0, 3).join(','),
      page_lang: cfg.lang || document.documentElement.lang || '',
      errors: errors.slice()
    };
    // Adresse der Bibliothek nie mitschicken: Pfad durch Platzhalter ersetzen.
    if (c.mask_path) out.url = out.url.split(c.mask_path).join('/<bibliothek>/');
    return out;
  }

  var overlay = null;
  var lastFocus = null;

  function close() {
    if (!overlay) return;
    overlay.remove();
    overlay = null;
    document.removeEventListener('keydown', onKey, true);
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  function onKey(ev) {
    if (ev.key === 'Escape') { ev.preventDefault(); close(); }
  }

  function open() {
    if (overlay) return;
    lastFocus = document.activeElement;
    var kinds = [['bug', S.fb_kind_bug], ['wish', S.fb_kind_wish], ['other', S.fb_kind_other]];
    var chips = el('div', { class: 'tfb-chips', role: 'radiogroup', 'aria-label': S.fb_kind || '' });
    kinds.forEach(function (k, i) {
      var id = 'tfb-kind-' + k[0];
      var input = el('input', { type: 'radio', name: 'tfb-kind', id: id, value: k[0] });
      if (i === 0) input.checked = true;
      chips.appendChild(el('label', { class: 'tfb-chip', for: id }, [input, el('span', { text: k[1] || k[0] })]));
    });
    var text = el('textarea', { id: 'tfb-text', rows: '5', maxlength: '4000', placeholder: S.fb_text_ph || '', required: 'required' });
    var email = el('input', { id: 'tfb-email', type: 'email', maxlength: '200', placeholder: S.fb_email_ph || '', autocomplete: 'email' });
    // Honigtopf: für Menschen unsichtbar, Bots füllen es aus.
    var trap = el('input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', class: 'tfb-trap', 'aria-hidden': 'true' });
    var msg = el('p', { class: 'tfb-msg', role: 'status', 'aria-live': 'polite' });
    var send = el('button', { type: 'submit', class: 'tfb-primary', text: S.fb_send || 'Senden' });
    var cancel = el('button', { type: 'button', class: 'tfb-ghost', text: S.fb_cancel || 'Abbrechen' });
    var form = el('form', { class: 'tfb-form', novalidate: 'novalidate' }, [
      el('p', { class: 'tfb-label', text: S.fb_kind || '' }), chips,
      el('label', { class: 'tfb-label', for: 'tfb-text', text: S.fb_text || '' }), text,
      el('label', { class: 'tfb-label', for: 'tfb-email', text: S.fb_email || '' }), email,
      trap,
      el('p', { class: 'tfb-hint', text: S.fb_hint || '' }),
      msg,
      el('div', { class: 'tfb-actions' }, [cancel, send])
    ]);
    var box = el('div', { class: 'tfb-box', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'tfb-title' }, [
      el('h2', { id: 'tfb-title', class: 'tfb-title', text: S.fb_title || 'Rückmeldung' }),
      form
    ]);
    overlay = el('div', { class: 'tfb-overlay' }, [box]);
    overlay.addEventListener('mousedown', function (ev) { if (ev.target === overlay) close(); });
    cancel.addEventListener('click', close);
    document.addEventListener('keydown', onKey, true);
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (text.value.trim().length < 3) {
        msg.textContent = S.fb_text_short || '';
        msg.className = 'tfb-msg tfb-bad';
        text.focus();
        return;
      }
      var kind = (form.querySelector('input[name="tfb-kind"]:checked') || {}).value || 'bug';
      send.disabled = true;
      msg.textContent = '';
      var headers = { 'Content-Type': 'application/json' };
      try {
        var gk = localStorage.getItem('wb_uid');
        if (gk) headers['X-Guest-Key'] = gk;
      } catch (_) { /* privates Fenster */ }
      fetch('/api/feedback', {
        method: 'POST',
        credentials: 'same-origin',
        headers: headers,
        body: JSON.stringify({ kind: kind, text: text.value, contact_email: email.value.trim(), website: trap.value, lang: cfg.lang || 'de', context: context() })
      }).then(function (r) {
        return r.json().catch(function () { return { ok: false }; }).then(function (j) { return { status: r.status, j: j }; });
      }).then(function (res) {
        if (res.j && res.j.ok) {
          box.replaceChildren(
            el('h2', { id: 'tfb-title', class: 'tfb-title', text: S.fb_title || '' }),
            el('p', { class: 'tfb-done', text: S.fb_thanks || 'Danke!' }),
            el('div', { class: 'tfb-actions' }, [el('button', { type: 'button', class: 'tfb-primary', text: S.fb_close || 'OK' })])
          );
          var b = box.querySelector('button');
          b.addEventListener('click', close);
          b.focus();
        } else {
          msg.textContent = (res.j && res.j.error) || S.fb_error || 'Fehler';
          msg.className = 'tfb-msg tfb-bad';
          send.disabled = false;
        }
      }).catch(function () {
        msg.textContent = S.fb_error || 'Fehler';
        msg.className = 'tfb-msg tfb-bad';
        send.disabled = false;
      });
    });
    document.body.appendChild(overlay);
    text.focus();
  }

  document.addEventListener('click', function (ev) {
    var t = ev.target && ev.target.closest ? ev.target.closest('[data-feedback]') : null;
    if (!t) return;
    ev.preventDefault();
    open();
  });

  window.TafulaFeedback = { open: open, errors: function () { return errors.slice(); } };
})();
