# Bildschirmfotos und Layoutprüfung der Testinstanz in 390, 700 und 1280 Pixel Breite (Nutzerverwaltung U1a).
# Läuft auf dem Pi im Docker-Abbild von Playwright, weil die Cloud-Umgebung von Claude die Testinstanz nicht erreicht:
#
#   docker run --rm --ipc=host -v <ordner>:/w -w /w mcr.microsoft.com/playwright/python:v1.65.2-noble \
#     sh -c "pip install -q --break-system-packages playwright==1.65.2; python3 shots/shots.py"
#
# <ordner>/shots/shots.py ist diese Datei, <ordner>/gate.txt enthält den Zugangscode und den zweiten Code der
# Testinstanz (zwei Zeilen, Rechte 600; vorher mit gate_set_code() vorübergehend setzen, danach wieder zufällig).
# Geprüft wird je Seite und Breite: kein waagrechter Überlauf, keine PHP-Meldung, das Band «Testumgebung»
# überdeckt kein Bedienelement, kein zu schmales Eingabefeld, Höhe der Navigation. Die Bilder liegen danach in
# <ordner>/shots/; ansehen muss sie ein Mensch (Raphael prüft zusätzlich in Safari).
import json, os
from playwright.sync_api import sync_playwright
B = 'https://test.tafula.top'
g1, g2 = open('/w/gate.txt').read().split()
WIDTHS = [390, 700, 1280]
report = []
TEXT = ''
def check(page, name, w):
    m = page.evaluate("""() => { const d = document.scrollingElement; const wide = [...document.querySelectorAll('body *')].filter(e => { const r = e.getBoundingClientRect(); return r.width > 0 && r.right > window.innerWidth + 1 && !e.closest('.nav') && !e.closest('.wb-instance-band') && getComputedStyle(e).position !== 'fixed'; }).slice(0, 3).map(e => e.tagName + '.' + e.className + ' right=' + Math.round(e.getBoundingClientRect().right));
      const band = document.querySelector('.wb-instance-band'); const br = band ? band.getBoundingClientRect() : null;
      const hit = br ? [...document.querySelectorAll('input:not([type=hidden]):not([hidden]),button,select,textarea,a.btn,a.btn-ghost')].filter(e => { const r = e.getBoundingClientRect(); return r.width > 0 && r.left < br.right && r.right > br.left && r.top < br.bottom && r.bottom > br.top && !e.closest('.hp'); }).slice(0, 3).map(e => e.tagName + '#' + e.id + ' ' + (e.textContent || '').trim().slice(0, 20)) : [];
      const small = [...document.querySelectorAll('.form input[type=text],.form input[type=email],.form input[type=password]')].filter(e => { const r = e.getBoundingClientRect(); return r.width > 0 && r.width < 140 && !e.closest('.hp'); }).length;
      const nav = document.querySelector('.nav'); const navh = nav ? Math.round(nav.getBoundingClientRect().height) : 0;
      const errs = document.body.innerText.match(/(Warning|Fatal error|Notice|Deprecated|Uncaught)[^\\n]{0,80}/g) || [];
      return {hit, small, navh, sw: d.scrollWidth, iw: window.innerWidth, wide, errs, title: document.title, h: d.scrollHeight}; }""")
    ok = m['sw'] <= m['iw'] + 1 and not m['errs'] and not m['hit'] and not m['small'] and m['navh'] in (0, 52)
    report.append(f"{'ok ' if ok else 'BAD'} {w:5d} {name:22s} scrollWidth={m['sw']} höhe={m['h']} {m['wide'] if not ok else ''} band-überdeckt={m['hit']} schmale-felder={m['small']} nav={m['navh']} {m['errs'] if m['errs'] else ''}")
    page.screenshot(path=f'/w/shots/{name}-{w}.png', full_page=True)
def gate(page, level=1):
    page.goto(B + '/test')
    if level == 1 and page.locator('input[name=code]').count():
        page.fill('input[name=code]', g1); page.click('button[type=submit]')
    if level == 2 and page.locator('#c2').count():
        page.fill('#c2', g2); page.click('form:has(#c2) button[type=submit]')
def login_role(page, local):
    page.goto(B + '/test')
    row = page.locator('tr', has_text=local + '@tafula.test')
    row.locator('button').click()
    page.wait_for_load_state('networkidle')
with sync_playwright() as p:
    br = p.chromium.launch()
    for w in WIDTHS:
        ctx = br.new_context(viewport={'width': w, 'height': 800}, device_scale_factor=1)
        page = ctx.new_page()
        page.goto(B + '/test'); check(page, 'gate', w)
        gate(page, 1)
        for name, path in [('login', '/login'), ('signup', '/signup'), ('reset', '/login/reset'), ('help', '/help'), ('help-anmelden', '/help/anmelden'), ('testrollen', '/test'), ('postfach', '/test/mail'), ('postfach-mail', '/test/mail?id=1')]:
            page.goto(B + path); check(page, name, w)
        login_role(page, 'lea.privat')
        for name, path in [('lea-bibliothek', '/library/'), ('lea-konto', '/account'), ('lea-einstellungen', '/library/settings.php'), ('lea-totp', '/account/totp'), ('lea-board', '/test-lea'), ('fremdes-board', '/test-spalten')]:
            page.goto(B + path); check(page, name, w)
        login_role(page, 'nora.zwei')
        page.goto(B + '/library/'); check(page, 'nora-bibliothek', w)
        gate(page, 2)
        login_role(page, 'sara.super')
        page.goto(B + '/manage/system'); check(page, 'verwaltung', w)
        if w == 1280:
            globals()['TEXT'] = page.inner_text('body')[:6000]
        page.goto(B + '/library/feedback.php'); check(page, 'sa-rueckmeldungen', w)
        ctx.close()
    br.close()
print('\n'.join(r for r in report if r.startswith('BAD')) or 'alle Seiten in allen Breiten ohne Befund'); print(len(report), 'Prüfungen')
open('/w/shots/text.txt', 'w').write(TEXT)
