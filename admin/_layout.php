<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';

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
        header('Location: /admin/login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? '/admin/'));
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
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%231b1f24'/><rect x='7' y='7' width='8' height='11' rx='2' fill='%23fff'/><rect x='17' y='7' width='8' height='6' rx='2' fill='%23fff'/><rect x='17' y='15' width='8' height='10' rx='2' fill='%23fff'/><rect x='7' y='20' width='8' height='5' rx='2' fill='%23fff'/></svg>">
</head>
<body>
<nav class="nav">
  <a class="brand" href="/admin/"><?= e(wb_app_name()) ?></a>
  <?php if (wb_is_admin_session()): ?>
  <a href="/admin/" class="<?= $active === 'library' ? 'active' : '' ?>">Bibliothek</a>
  <a href="/admin/board.php?new=1" class="<?= $active === 'new' ? 'active' : '' ?>">Neues Board</a>
  <a href="/admin/settings.php" class="<?= $active === 'settings' ? 'active' : '' ?>">Einstellungen</a>
  <a href="/admin/logout.php" class="nav-right">Abmelden</a>
  <?php endif; ?>
</nav>
<main class="wrap">
<?php if ($flash): ?><div class="flash"><?= e($flash) ?><?php if ($flashUndo): ?> <form method="post" action="/admin/board.php?key=<?= e($flashUndo['key']) ?>" class="inline"><input type="hidden" name="csrf" value="<?= e(wb_csrf_token()) ?>"><input type="hidden" name="action" value="undo"><input type="hidden" name="id" value="<?= (int)$flashUndo['id'] ?>"><button class="link-undo" type="submit">Rückgängig</button></form><?php endif; ?></div><?php endif; ?>
<?php
}

function admin_footer(): void {
    ?></main>
<script src="/assets/admin.js?v=<?= WB_VERSION ?>"></script>
</body>
</html><?php
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
