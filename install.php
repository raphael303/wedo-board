<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$errors = [];
$done = false;
$token = null;

try {
    db();
} catch (Throwable $e) {
    $errors[] = 'Datenbankverbindung fehlgeschlagen: ' . $e->getMessage();
}

if (!$errors && db_is_installed()) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>' . e(wb_app_name()) . '</title><body style="font-family:system-ui;padding:2rem;max-width:40rem"><h1>Bereits installiert</h1><p>Die Installation wurde abgeschlossen. Aus Sicherheitsgründen kannst du diese Seite löschen.</p></body>';
    exit;
}

if (!$errors && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['password2'] ?? '');
    if (strlen($pw) < 8) $errors[] = 'Das Passwort braucht mindestens 8 Zeichen.';
    elseif ($pw !== $pw2) $errors[] = 'Die Passwörter stimmen nicht überein.';
    if (!$errors) {
        try {
            foreach (db_schema() as $sql) db()->exec($sql);
            $token = bin2hex(random_bytes(24));
            setting_set('api_token', $token);
            setting_set('admin_password_hash', password_hash($pw, PASSWORD_DEFAULT));
            setting_set('installed_at', date('c'));
            setting_set('schema_version', (string)WB_SCHEMA_VERSION);
            db_ensure_superadmin();
            if (!is_dir(__DIR__ . '/uploads')) @mkdir(__DIR__ . '/uploads', 0755);
            $done = true;
        } catch (Throwable $e) {
            $errors[] = 'Installation fehlgeschlagen: ' . $e->getMessage();
        }
    }
}

$checks = [
    'PHP ' . PHP_VERSION . ' (mindestens 8.1)' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO MySQL' => extension_loaded('pdo_mysql'),
    'GD (Bilder)' => extension_loaded('gd'),
    'WebP-Unterstützung' => function_exists('imagewebp'),
    'mbstring' => extension_loaded('mbstring'),
    'uploads/ beschreibbar' => is_writable(__DIR__ . '/uploads') || is_writable(__DIR__),
];
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(wb_app_name()) ?> – Installation</title>
<style>
body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f3f4f6;color:#1b1f24;margin:0;padding:2rem 1rem}
main{max-width:34rem;margin:0 auto;background:#fff;border-radius:14px;padding:2rem;box-shadow:0 1px 2px rgba(0,0,0,.06)}
h1{margin:0 0 1rem;font-size:1.5rem}
ul{padding-left:1.2rem}
label{display:block;margin:1rem 0 .3rem;font-weight:600}
input{width:100%;box-sizing:border-box;padding:.6rem .7rem;border:1px solid #cfd4db;border-radius:8px;font-size:1rem}
button{margin-top:1.2rem;background:#1b1f24;color:#fff;border:0;border-radius:999px;padding:.7rem 1.3rem;font-size:1rem;cursor:pointer}
.err{background:#fde8e8;color:#9b1c1c;padding:.7rem 1rem;border-radius:8px}
.ok{color:#15803d}.bad{color:#b91c1c}
code{background:#f3f4f6;padding:.15rem .4rem;border-radius:4px;word-break:break-all}
</style>
</head>
<body>
<main>
<?php if ($done): ?>
  <h1>Installation abgeschlossen</h1>
  <p>Die Datenbank ist eingerichtet. Dein Schlüssel für die Schnittstelle (API‑Token) lautet:</p>
  <p><code><?= e($token) ?></code></p>
  <p>Du findest ihn jederzeit wieder in der Bibliothek unter «Einstellungen». Lösche jetzt die Datei <code>install.php</code> vom Server oder lasse sie liegen; sie ist ab sofort gesperrt.</p>
  <p><a href="<?= e(wb_admin_url()) ?>">Zur Bibliothek</a> (<code><?= e(wb_base_url() . wb_admin_url()) ?></code>, Pfad über <code>admin_path</code> in config.php)</p>
<?php else: ?>
  <h1><?= e(wb_app_name()) ?> einrichten</h1>
  <?php foreach ($errors as $err): ?><p class="err"><?= e($err) ?></p><?php endforeach; ?>
  <ul>
    <?php foreach ($checks as $label => $ok): ?>
      <li class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✓' : '✗' ?> <?= e($label) ?></li>
    <?php endforeach; ?>
  </ul>
  <form method="post">
    <label for="password">Admin‑Passwort</label>
    <input id="password" name="password" type="password" minlength="8" required autocomplete="new-password">
    <label for="password2">Passwort wiederholen</label>
    <input id="password2" name="password2" type="password" minlength="8" required autocomplete="new-password">
    <button type="submit">Installieren</button>
  </form>
<?php endif; ?>
</main>
</body>
</html>
