<?php
declare(strict_types=1);
// Öffentliche Startseite: erklärt das Projekt und führt Lernende mit einem Code zu ihrem Board.
// Bewusst ohne Link zur Bibliothek (deren Pfad steht nur in config.php, siehe admin_path).
require __DIR__ . '/lib/bootstrap.php';

if (!db_is_installed()) {
    header('Location: /install.php');
    exit;
}

$codeError = null;
$code = trim((string)($_GET['code'] ?? ''));
if ($code !== '') {
    $c = strtolower(preg_replace('~^.*/~', '', rtrim($code, '/')) ?? '');
    if (preg_match('/^[a-z0-9]{16}$/', $c) && (board_by_key($c) || activity_by_key($c))) {
        header('Location: ' . (board_by_key($c) ? '/b/' : '/a/') . $c);
        exit;
    }
    $slug = wb_valid_slug($c);
    if ($slug !== null && $slug !== wb_admin_path() && (board_by_slug($slug) || activity_by_slug($slug))) {
        header('Location: /' . $slug);
        exit;
    }
    $codeError = 'Zu diesem Code gibt es nichts. Prüfe die Schreibweise oder frage deine Lehrperson.';
}

$app = wb_app_name();
$host = (string)parse_url(wb_base_url(), PHP_URL_HOST);
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($app) ?> · Gemeinsam denken im Unterricht</title>
<meta name="description" content="<?= e($app) ?> ist ein privates Projekt von R. Huber: digitale Pinnwände und Feedback-Werkzeuge für den Unterricht, selbst gehostet und ohne Konto für Lernende.">
<meta name="robots" content="index, nofollow">
<meta name="theme-color" content="#f6f3ec">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%231b1f24'/%3E%3Crect x='7' y='8' width='8' height='8' rx='2' fill='%23ffd666'/%3E%3Crect x='17' y='8' width='8' height='12' rx='2' fill='%2398dbb4'/%3E%3Crect x='7' y='18' width='8' height='6' rx='2' fill='%23a9cdfb'/%3E%3C/svg%3E">
<link rel="stylesheet" href="/assets/landing.css?v=<?= WB_VERSION ?>">
</head>
<body>

<header class="top">
  <div class="wrap top-in">
    <a class="logo" href="/" aria-label="<?= e($app) ?>">
      <svg width="28" height="28" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="8" fill="#1b1f24"/><rect x="7" y="8" width="8" height="8" rx="2" fill="#ffd666"/><rect x="17" y="8" width="8" height="12" rx="2" fill="#98dbb4"/><rect x="7" y="18" width="8" height="6" rx="2" fill="#a9cdfb"/></svg>
      <span><?= e(strtolower($app)) ?></span>
    </a>
    <span class="badge">Privates Projekt · im Aufbau</span>
  </div>
</header>

<main>
  <section class="hero">
    <div class="wrap hero-grid">
      <div class="hero-text">
        <p class="eyebrow">Werkzeuge für den Unterricht</p>
        <h1>Gedanken einer ganzen Klasse auf einer Tafel.</h1>
        <p class="lead"><?= e($app) ?> ist eine digitale Pinnwand, auf der Lernende über einen QR-Code Ideen, Fragen und Bilder sammeln. Die Lehrperson ordnet, bündelt und wertet aus, auf Wunsch mit Unterstützung durch KI.</p>

        <form class="join" method="get" action="/" autocomplete="off">
          <label for="code">Hast du einen Code von deiner Lehrperson?</label>
          <div class="join-row">
            <span class="join-prefix" aria-hidden="true"><?= e($host) ?>/</span>
            <input id="code" name="code" type="text" inputmode="url" autocapitalize="none" spellcheck="false" placeholder="kurzwort" value="<?= e($code) ?>" required>
            <button type="submit">Öffnen</button>
          </div>
          <?php if ($codeError): ?><p class="join-error" role="alert"><?= e($codeError) ?></p><?php else: ?><p class="join-hint">Ein Konto brauchst du nicht. Du schreibst unter einem zufälligen Fantasienamen.</p><?php endif; ?>
        </form>
      </div>

      <div class="hero-visual" aria-hidden="true">
        <div class="mock">
          <div class="mock-bar"><i></i><i></i><i></i><span>Was wissen wir schon über Energie?</span></div>
          <div class="mock-cols">
            <div class="mock-col">
              <b>Formen</b>
              <div class="note y">Wärme ist Bewegung der Teilchen</div>
              <div class="note y s">Licht, Schall, Strom …</div>
              <div class="note y">Lageenergie beim Velo den Berg hinauf</div>
            </div>
            <div class="mock-col">
              <b>Fragen</b>
              <div class="note b">Geht Energie wirklich nie verloren?</div>
              <div class="note b">Warum wird das Handy warm?<span class="react">👍 4</span></div>
            </div>
            <div class="mock-col hide-s">
              <b>Alltag</b>
              <div class="note g">Wasserkocher: 2000 W</div>
              <div class="note g">Solarpanel auf dem Schuldach<span class="react">💡 3</span></div>
              <div class="note g s">Velo-Dynamo</div>
            </div>
          </div>
        </div>
        <div class="mock-target">
          <svg viewBox="0 0 120 120">
            <circle cx="60" cy="60" r="56" fill="#fff" stroke="#e4ded2"/>
            <circle cx="60" cy="60" r="40" fill="none" stroke="#e4ded2"/>
            <circle cx="60" cy="60" r="22" fill="none" stroke="#e4ded2"/>
            <line x1="60" y1="4" x2="60" y2="116" stroke="#e4ded2"/><line x1="4" y1="60" x2="116" y2="60" stroke="#e4ded2"/>
            <circle cx="50" cy="48" r="4.5" fill="#e8743b"/><circle cx="72" cy="42" r="4.5" fill="#3b82c4"/><circle cx="40" cy="72" r="4.5" fill="#2f9e6a"/><circle cx="76" cy="76" r="4.5" fill="#9b5de5"/><circle cx="66" cy="54" r="4.5" fill="#3b82c4"/><circle cx="55" cy="68" r="4.5" fill="#2f9e6a"/><circle cx="30" cy="38" r="4.5" fill="#e8743b"/>
          </svg>
          <span>Feedbackzielscheibe</span>
        </div>
      </div>
    </div>
  </section>

  <section class="section">
    <div class="wrap">
      <p class="eyebrow">Was es heute kann</p>
      <h2>Vier Werkzeuge, ein Ziel: alle Stimmen im Raum hören.</h2>
      <div class="cards">
        <article class="card">
          <div class="icon y"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="7" height="7" rx="1.5"/><rect x="14" y="4" width="7" height="11" rx="1.5"/><rect x="3" y="14" width="7" height="6" rx="1.5"/></svg></div>
          <h3>Pinnwände</h3>
          <p>Lernende schreiben Beiträge mit Text, Link oder Foto, kommentieren und reagieren. Boards gibt es frei angeordnet oder in Spalten, mit Kurzlink und QR-Code für den Beamer.</p>
        </article>
        <article class="card">
          <div class="icon r"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.5"/></svg></div>
          <h3>Feedbackzielscheibe</h3>
          <p>Alle setzen pro Aspekt einen Punkt, je näher an der Mitte, desto besser. Die Punkte bleiben verborgen, bis die Lehrperson sie gemeinsam aufdeckt; spätere Runden zeigen die Entwicklung.</p>
        </article>
        <article class="card">
          <div class="icon b"><svg viewBox="0 0 24 24"><path d="M4 10h7M6 14h12M9 18h6M13 10h7M8 6h8"/></svg></div>
          <h3>Wortwolke</h3>
          <p>Alle geben ein paar Begriffe ein, häufige erscheinen grösser. Auf Knopfdruck legt die KI Synonyme, Mehrzahl und Tippfehler zusammen, sodass «Auto», «Autos» und «Fahrzeuge» als ein Begriff zählen.</p>
        </article>
        <article class="card">
          <div class="icon b"><svg viewBox="0 0 24 24"><path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8z"/><path d="M18 15l.8 2.2L21 18l-2.2.8L18 21l-.8-2.2L15 18l2.2-.8z"/></svg></div>
          <h3>Bündeln mit KI</h3>
          <p>Aus vierzig losen Beiträgen werden auf Knopfdruck thematische Gruppen. Jeder Schritt lässt sich rückgängig machen, und die Lehrperson entscheidet, was bleibt.</p>
        </article>
        <article class="card">
          <div class="icon g"><svg viewBox="0 0 24 24"><path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/><path d="M10 13h6M10 17h6"/></svg></div>
          <h3>Ergebnisse sichern</h3>
          <p>Ein Board wird zum PDF, wahlweise mit Zusammenfassung. Namen, Zeitstempel und Gerätekennungen bleiben dabei immer aussen vor.</p>
        </article>
      </div>
    </div>
  </section>

  <section class="section alt">
    <div class="wrap split">
      <div>
        <p class="eyebrow">Was es vielleicht einmal wird</p>
        <h2>Ein kleiner Werkzeugkasten für lebendigen Unterricht.</h2>
        <p>Zielscheibe und Wortwolke sind die ersten «Aktivitäten», die neben den Pinnwänden entstehen. Denkbar sind weitere wie Live-Umfragen und kurze Abstimmungen, wie man sie von Präsentationswerkzeugen kennt, aber auf demselben schlanken Fundament und mit derselben Bibliothek, in der alle Materialien nach Klasse, Fach und Thema auffindbar bleiben.</p>
        <p>Ob <?= e($app) ?> später auch anderen Lehrpersonen offensteht, ist offen. Das Fundament dafür, mit eigenen Benutzerkonten und getrennten Bibliotheken, ist bereits angelegt.</p>
      </div>
      <ol class="road">
        <li class="done"><b>Pinnwände</b><span>Beiträge, Kommentare, Reaktionen, Spalten, Bilder</span></li>
        <li class="done"><b>Bündeln, Rückgängig, PDF-Export</b><span>mit optionaler KI-Unterstützung</span></li>
        <li class="done"><b>Feedbackzielscheibe</b><span>die erste Aktivität</span></li>
        <li class="done"><b>Wortwolke</b><span>mit KI-Zusammenlegen</span></li>
        <li><b>Umfragen und Abstimmungen</b><span>angedacht</span></li>
        <li><b>Weitere Lehrpersonen</b><span>vielleicht</span></li>
      </ol>
    </div>
  </section>

  <section class="section">
    <div class="wrap">
      <p class="eyebrow">Grundsätze</p>
      <h2>Für Schulzimmer gebaut, nicht für Datensammlungen.</h2>
      <div class="principles">
        <div><h3>Ohne Konto für Lernende</h3><p>Wer mitmacht, erhält einen zufälligen Fantasienamen. Es braucht weder E-Mail-Adresse noch App.</p></div>
        <div><h3>Selbst gehostet in der Schweiz</h3><p><?= e($app) ?> läuft auf einem Schweizer Webhosting, ohne Werbung, ohne Tracking und ohne eingebundene Fremddienste.</p></div>
        <div><h3>KI nur auf Wunsch</h3><p>KI-Funktionen sind pro Board abschaltbar. Werden sie genutzt, gehen nur Beitragstexte ohne Namen an den KI-Anbieter.</p></div>
        <div><h3>Schlank und offen</h3><p>Reines PHP und MySQL ohne Framework, ohne Build-Schritt und ohne externe Bibliotheken im Browser. Das hält die Seite schnell, auch im schwachen Schul-WLAN.</p></div>
      </div>
    </div>
  </section>

  <section class="note-private">
    <div class="wrap">
      <div class="private">
        <h2>Ein privates Projekt</h2>
        <p><?= e($app) ?> ist ein persönliches Projekt von R. Huber und wird zurzeit ausschliesslich im eigenen Unterricht eingesetzt. Es gibt keine öffentliche Registrierung und keinen Support. Wer einen Code erhalten hat, kann oben direkt einsteigen.</p>
      </div>
    </div>
  </section>
</main>

<footer class="foot">
  <div class="wrap foot-in">
    <span>© <?= date('Y') ?> R. Huber · <?= e($app) ?> ist ein privates, nicht kommerzielles Projekt.</span>
    <span class="muted">Version <?= e(WB_VERSION) ?></span>
  </div>
</footer>

</body>
</html>
