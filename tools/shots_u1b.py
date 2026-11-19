# Bildschirmfotos und Layoutprüfung der Seiten aus U1b in 390, 700 und 1280 Pixel Breite.
# Anders als tools/shots_u1a.py läuft diese Prüfung gegen den Wegwerf-Server von tools/test.sh auf dem Pi und
# nicht gegen die Testinstanz: So müssen deren Zugangscodes nicht vorübergehend ersetzt werden. Aufruf:
#
#   SHOTS_DIR=/srv/workspaces/wedo-board/.cyon/shots-u1b flock /srv/workspaces/.build.lock tools/test.sh
#
# tools/test_u1b.sh startet dafür das Docker-Abbild von Playwright mit dem Netz des Hosts und übergibt in der
# Umgebung BASE, SESS (Sitzungs-Cookie eines Testkontos), CID und LCID (zwei registrierte Clients), CH (code_challenge)
# und LIB (Pfad der Bibliothek). Geprüft wird je Seite und Breite: kein waagrechter Überlauf, keine PHP-Meldung,
# kein zu schmales Eingabefeld. Die Bilder liegen danach in SHOTS_DIR; ansehen muss sie ein Mensch.
import os
from urllib.parse import quote
from playwright.sync_api import sync_playwright
B = os.environ['BASE']; CID = os.environ['CID']; LCID = os.environ['LCID']; CH = os.environ['CH']; LIB = os.environ.get('LIB', '/lehrerzimmer/')
WIDTHS = [390, 700, 1280]
report = []
def check(page, name, w):
    m = page.evaluate("""() => { const d = document.scrollingElement; const wide = [...document.querySelectorAll('body *')].filter(e => { const r = e.getBoundingClientRect(); return r.width > 0 && r.right > window.innerWidth + 1 && !e.closest('.nav') && getComputedStyle(e).position !== 'fixed'; }).slice(0, 3).map(e => e.tagName + '.' + e.className + ' right=' + Math.round(e.getBoundingClientRect().right));
      const small = [...document.querySelectorAll('.form input[type=text],.form input[type=email],.form input[type=password],.form select')].filter(e => { const r = e.getBoundingClientRect(); return r.width > 0 && r.width < 140; }).length;
      const errs = document.body.innerText.match(/(Warning|Fatal error|Notice|Deprecated|Uncaught)[^\\n]{0,80}/g) || [];
      const btn = [...document.querySelectorAll('button.btn, a.btn')].filter(e => { const r = e.getBoundingClientRect(); return r.width > 0 && (r.left < 0 || r.right > window.innerWidth); }).length;
      return {small, sw: d.scrollWidth, iw: window.innerWidth, wide, errs, btn, h: d.scrollHeight}; }""")
    ok = m['sw'] <= m['iw'] + 1 and not m['errs'] and not m['small'] and not m['btn']
    report.append(f"{'ok ' if ok else 'BAD'} {w:5d} {name:24s} scrollWidth={m['sw']} höhe={m['h']} {m['wide'] if not ok else ''} schmale-felder={m['small']} knopf-abgeschnitten={m['btn']} {m['errs'] if m['errs'] else ''}")
    page.screenshot(path=f'/w/{name}-{w}.png', full_page=True)
qs = f"response_type=code&client_id={CID}&redirect_uri={quote('https://client.example/cb', safe='')}&state=st-123&code_challenge={CH}&code_challenge_method=S256"
lqs = f"response_type=code&client_id={LCID}&redirect_uri={quote('http://localhost:3118/callback', safe='')}&state=st-9&code_challenge={CH}&code_challenge_method=S256"
pages = [
    ('zustimmung', '/oauth/authorize?' + qs + '&resource=' + quote(B + '/mcp/private', safe='')),
    ('zustimmung-auswahl', '/oauth/authorize?' + qs),
    ('zustimmung-lokal', '/oauth/authorize?' + lqs + '&resource=' + quote(B + '/mcp/private', safe='')),
    ('zustimmung-fehler', '/oauth/authorize?' + qs.replace('client.example', 'evil.example')),
    ('konto', '/account'),
    ('konto-token', '/account/token'),
    ('hilfe-connector', '/help/connector'),
    ('einstellungen', LIB + 'settings.php'),
]
with sync_playwright() as p:
    br = p.chromium.launch()
    for w in WIDTHS:
        ctx = br.new_context(viewport={'width': w, 'height': 800}, device_scale_factor=1)
        ctx.add_cookies([{'name': 'tafula_s', 'value': os.environ['SESS'], 'url': B}])
        page = ctx.new_page()
        for name, path in pages:
            page.goto(B + path); check(page, name, w)
        # Neues Token erzeugen und die einmalige Anzeige prüfen
        page.goto(B + '/account/token')
        if not page.locator('#tl').count():
            report.append(f"BAD {w:5d} konto-token-neu: Formular fehlt, Adresse {page.url}, Text: {page.inner_text('body')[:300]!r}")
        else:
            page.fill('#tl', 'Bildschirmfoto ' + str(w)); 
            with page.expect_navigation(): page.click('form.form button[type=submit]')
            check(page, 'konto-token-neu', w)
            if 'tfp_' not in page.inner_text('body'): report.append(f'BAD {w:5d} konto-token-neu: kein Token angezeigt')
        page.goto(B + '/account'); check(page, 'konto-mit-token', w)
        ctx.close()
    br.close()
print('\n'.join(r for r in report if r.startswith('BAD')) or 'alle Seiten in allen Breiten ohne Befund'); print(len(report), 'Prüfungen')
open('/w/bericht.txt', 'w').write('\n'.join(report) + '\n')
