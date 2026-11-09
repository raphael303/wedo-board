<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

// Die Bibliothek ist nur unter <base_url>/<admin_path>/ erreichbar (config.php, Standard «admin»).
// Aufrufe über den echten Ordnernamen /admin/ ergeben 404, wenn admin_path anders lautet.
if (!str_starts_with((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), wb_admin_url())) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Nicht gefunden.');
}

if (!db_is_installed()) {
    header('Location: /install.php');
    exit;
}
wb_session_start();
if (wb_is_admin_session()) {
    db_migrate();
}

$adminLang = 'de';

function admin_require_login(): void {
    if (!wb_is_admin_session()) {
        header('Location: ' . wb_admin_url() . 'login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? wb_admin_url()));
        exit;
    }
}

function admin_check_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(wb_csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Ungültige Anfrage (CSRF).');
    }
}

function admin_flash(?string $msg = null, int $undoId = 0, string $boardKey = ''): ?string {
    if ($msg !== null) {
        $_SESSION['flash'] = $msg;
        $_SESSION['flash_undo'] = $undoId > 0 ? ['id' => $undoId, 'key' => $boardKey] : null;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

function admin_flash_undo(): ?array {
    $u = $_SESSION['flash_undo'] ?? null;
    unset($_SESSION['flash_undo']);
    return is_array($u) ? $u : null;
}

function admin_header(string $title, string $active = ''): void {
    $flash = admin_flash();
    $flashUndo = $flash ? admin_flash_undo() : null;
    ?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?> – <?= e(wb_app_name()) ?></title>
<link rel="stylesheet" href="/assets/admin.css?v=<?= WB_VERSION ?>">
<?= feedback_head('de', ['page' => 'library', 'owner' => wb_is_admin_session(), 'mask_path' => wb_admin_url()]) ?>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%231b1f24'/><rect x='7' y='7' width='8' height='11' rx='2' fill='%23fff'/><rect x='17' y='7' width='8' height='6' rx='2' fill='%23fff'/><rect x='17' y='15' width='8' height='10' rx='2' fill='%23fff'/><rect x='7' y='20' width='8' height='5' rx='2' fill='%23fff'/></svg>">
</head>
<body>
<nav class="nav">
  <a class="brand" href="<?= e(wb_admin_url()) ?>"><?= e(wb_app_name()) ?></a>
  <?php if (wb_is_admin_session()): ?>
  <a href="<?= e(wb_admin_url()) ?>" class="<?= $active === 'library' ? 'active' : '' ?>">Bibliothek</a>
  <a href="<?= e(wb_admin_url()) ?>board.php?new=1" class="<?= $active === 'new' ? 'active' : '' ?>">Neues Board</a>
  <a href="<?= e(wb_admin_url()) ?>targets.php" class="<?= $active === 'targets' ? 'active' : '' ?>">Zielscheiben</a>
  <a href="<?= e(wb_admin_url()) ?>wordclouds.php" class="<?= $active === 'wordclouds' ? 'active' : '' ?>">Wortwolken</a>
  <a href="<?= e(wb_admin_url()) ?>blocks.php" class="<?= $active === 'blocks' ? 'active' : '' ?>">Sperren</a>
  <a href="<?= e(wb_admin_url()) ?>feedback.php" class="<?= $active === 'feedback' ? 'active' : '' ?>">Rückmeldungen<?php $fbOpen = admin_feedback_new_count(); if ($fbOpen > 0): ?> <span class="badge"><?= $fbOpen ?></span><?php endif; ?></a>
  <a href="<?= e(wb_admin_url()) ?>settings.php" class="<?= $active === 'settings' ? 'active' : '' ?>">Einstellungen</a>
  <a href="<?= e(wb_admin_url()) ?>logout.php" class="nav-right">Abmelden</a>
  <?php endif; ?>
</nav>
<main class="wrap">
<?php if ($flash): ?><div class="flash"><?= e($flash) ?><?php if ($flashUndo): ?> <form method="post" action="<?= e(wb_admin_url()) ?>board.php?key=<?= e($flashUndo['key']) ?>" class="inline"><input type="hidden" name="csrf" value="<?= e(wb_csrf_token()) ?>"><input type="hidden" name="action" value="undo"><input type="hidden" name="id" value="<?= (int)$flashUndo['id'] ?>"><button class="link-undo" type="submit">Rückgängig</button></form><?php endif; ?></div><?php endif; ?>
<?php
}

function admin_footer(): void {
    ?></main>
<footer class="admin-foot"><a class="tfb-link" href="#" data-feedback>Rückmeldung</a> · Version <?= e(WB_VERSION) ?></footer>
<script src="/assets/admin.js?v=<?= WB_VERSION ?>"></script>
</body>
</html><?php
}

/** Urheber einer protokollierten Änderung lesbar machen. */
function admin_actor_label(?string $actor): string {
    if ($actor === 'teacher') return 'Lehrperson';
    if ($actor === 'claude') return 'Claude';
    if ($actor !== null && str_starts_with($actor, 'ai:')) return 'KI (' . substr($actor, 3) . ')';
    return (string)$actor;
}

/** Anzahl neuer Rückmeldungen für das Menü (0, solange die Tabelle fehlt). */
function admin_feedback_new_count(): int {
    try {
        return (int)(db_row("SELECT COUNT(*) AS n FROM feedback WHERE status = 'new' AND deleted_at IS NULL")['n'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function admin_status_label(string $s): string {
    return ['open' => 'offen', 'closed' => 'geschlossen', 'archived' => 'archiviert'][$s] ?? $s;
}

function admin_bg_label(string $s): string {
    return ['morgen' => 'Morgen', 'meer' => 'Meer', 'wald' => 'Wald', 'daemmerung' => 'Dämmerung', 'graphit' => 'Graphit', 'papier' => 'Papier'][$s] ?? $s;
}

function admin_time(string $utc): string {
    return (string)wb_local_time($utc);
}
