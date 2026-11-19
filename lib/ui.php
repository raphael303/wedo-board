<?php
declare(strict_types=1);

/*
 * Seitenrahmen des Verwaltungsteils: Bibliothek (admin/), Anmeldung und Konto (pages/), Verwaltung.
 * Die Gästeseiten (board.php, activity.php) haben ihren eigenen Rahmen.
 */

/** Leitet auf /login um, wenn niemand angemeldet ist, und gibt sonst das Konto zurück. */
function ui_require_login(): array {
    $u = wb_auth_kind() === 'session' ? wb_current_user() : null;
    if ($u === null) {
        header('Location: /login?next=' . urlencode((string)($_SERVER['REQUEST_URI'] ?? '/account')));
        exit;
    }
    $GLOBALS['wb_ui_lang'] = $u['locale'] ?? 'de';
    return $u;
}

/** Formulare des Verwaltungsteils schicken den CSRF-Wert im Feld csrf mit. */
function ui_check_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !wb_csrf_ok((string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Ungültige Anfrage (CSRF).');
    }
}

function ui_csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(wb_csrf_token()) . '">';
}

/** Nur lokale Pfade als Rücksprungziel («/…», nie «//…» oder eine fremde Adresse). */
function ui_safe_next(?string $next, string $default): string {
    $next = (string)$next;
    return preg_match('~^/(?![/\\\\])[^\r\n]*$~', $next) ? $next : $default;
}

function ui_redirect(string $to, ?string $flash = null): never {
    if ($flash !== null) wb_flash($flash);
    header('Location: ' . $to);
    exit;
}

/** Vor heiklen Handlungen: Passwort erneut abfragen (gilt einige Minuten). */
function ui_require_reauth(): void {
    if (wb_reauth_fresh()) return;
    header('Location: /login/confirm?next=' . urlencode((string)($_SERVER['REQUEST_URI'] ?? '/account')));
    exit;
}

function ui_header(string $title, string $active = ''): void {
    $flash = wb_flash();
    $user = wb_auth_kind() === 'session' ? wb_current_user() : null;
    $lib = $user ? wb_admin_url() : null;
    $staff = $user ? user_system_roles((int)$user['id']) : [];
    $spaces = $user ? wb_workspaces($user) : [];
    $nav = function (string $href, string $key, string $label) use ($active): string {
        return '<a href="' . e($href) . '" class="' . ($active === $key ? 'active' : '') . '">' . $label . '</a>';
    };
    ?><!doctype html>
<html lang="<?= e($GLOBALS['wb_ui_lang'] ?? 'de') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="referrer" content="same-origin">
<title><?= e($title) ?> – <?= e(wb_app_name()) ?></title>
<link rel="stylesheet" href="/assets/admin.css?v=<?= WB_VERSION ?>">
<?= feedback_head('de', ['page' => $lib !== null && $active !== '' && !str_starts_with($active, 'acc') ? 'library' : 'account', 'owner' => $user !== null, 'mask_path' => $lib ?? '']) ?>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%231b1f24'/><rect x='7' y='7' width='8' height='11' rx='2' fill='%23fff'/><rect x='17' y='7' width='8' height='6' rx='2' fill='%23fff'/><rect x='17' y='15' width='8' height='10' rx='2' fill='%23fff'/><rect x='7' y='20' width='8' height='5' rx='2' fill='%23fff'/></svg>">
</head>
<body>
<nav class="nav">
  <a class="brand" href="<?= e($lib ?? '/') ?>"><?= e(wb_app_name()) ?></a>
  <?php if ($user): ?>
  <?= $nav($lib, 'library', tx('Bibliothek')) ?>
  <?= $nav($lib . 'board.php?new=1', 'new', tx('Neues Board')) ?>
  <?= $nav($lib . 'targets.php', 'targets', tx('Zielscheiben')) ?>
  <?= $nav($lib . 'wordclouds.php', 'wordclouds', tx('Wortwolken')) ?>
  <?= $nav($lib . 'exams.php', 'exams', tx('Prüfungen')) ?>
  <?= $nav($lib . 'blocks.php', 'blocks', tx('Sperren')) ?>
  <?php if (wb_can('feedback.manage')): $fbOpen = ui_feedback_new_count(); ?>
  <?= $nav($lib . 'feedback.php', 'feedback', tx('Rückmeldungen') . ($fbOpen > 0 ? ' <span class="badge">' . $fbOpen . '</span>' : '')) ?>
  <?php endif; ?>
  <?= $nav($lib . 'settings.php', 'settings', tx('Einstellungen')) ?>
  <?php if ($staff): ?><?= $nav('/manage/system', 'manage', tx('Verwaltung')) ?><?php endif; ?>
  <span class="nav-right nav-account">
    <?php if (count($spaces) > 1): ?>
    <form method="post" action="/account/workspace" class="inline ws-form"><?= ui_csrf_field() ?>
      <select id="ws-select" name="org" onchange="this.form.submit()" title="<?= e(tx('Arbeitsbereich')) ?>" aria-label="<?= e(tx('Arbeitsbereich')) ?>">
        <?php foreach ($spaces as $w): ?><option value="<?= $w['org_id'] ?? 0 ?>" <?= $w['org_id'] === wb_workspace_org_id() ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?>
      </select><noscript><button type="submit" class="btn-ghost"><?= e(tx('Wechseln')) ?></button></noscript>
    </form>
    <?php endif; ?>
    <?= $nav('/account', 'account', e(user_name($user))) ?>
    <?= $nav('/help', 'help', '?') ?>
    <form method="post" action="/logout" class="inline"><?= ui_csrf_field() ?><button type="submit" class="nav-link"><?= e(tx('Abmelden')) ?></button></form>
  </span>
  <?php else: ?>
  <span class="nav-right nav-account"><?= $nav('/help', 'help', tx('Hilfe')) ?><?= $nav('/login', 'login', tx('Anmelden')) ?></span>
  <?php endif; ?>
</nav>
<main class="wrap">
<?php if ($flash): $fu = is_array($flash['extra'] ?? null) && !empty($flash['extra']['undo_id']) ? $flash['extra'] : null; ?><div class="flash"><?= e((string)$flash['msg']) ?><?php if ($fu && $lib): ?> <form method="post" action="<?= e($lib) ?>board.php?key=<?= e((string)$fu['key']) ?>" class="inline"><?= ui_csrf_field() ?><input type="hidden" name="action" value="undo"><input type="hidden" name="id" value="<?= (int)$fu['undo_id'] ?>"><button class="link-undo" type="submit"><?= e(tx('Rückgängig')) ?></button></form><?php endif; ?></div><?php endif; ?>
<?php
}

function ui_footer(): void {
    ?></main>
<footer class="admin-foot"><a class="tfb-link" href="#" data-feedback><?= e(tx('Rückmeldung')) ?></a> · <a href="/help"><?= e(tx('Hilfe')) ?></a> · Version <?= e(WB_VERSION) ?></footer>
<script src="/assets/admin.js?v=<?= WB_VERSION ?>"></script>
</body>
</html><?php
}

/** Anzahl neuer Rückmeldungen für das Menü (nur für Super-Admin und Admin aufgerufen). */
function ui_feedback_new_count(): int {
    try {
        return (int)(db_row("SELECT COUNT(*) AS n FROM feedback WHERE status = 'new' AND deleted_at IS NULL")['n'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** Wartungshinweis, solange das Schema älter ist als der Code (während des Ausrollens). */
function ui_maintenance(): never {
    http_response_code(503);
    header('Retry-After: 30');
    header('Cache-Control: no-store');
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if (str_starts_with($uri, '/api/') || str_starts_with($uri, '/mcp/') || str_starts_with($uri, '/oauth/token') || str_starts_with($uri, '/oauth/register') || str_starts_with($uri, '/.well-known/')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Wartung: Die Datenbank wird gerade aktualisiert. Bitte in einer Minute wieder versuchen.', 'code' => 'maintenance'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><meta http-equiv="refresh" content="30"><title>' . e(wb_app_name()) . '</title>'
        . '<style>body{font:16px/1.5 -apple-system,"Helvetica Neue",Arial,sans-serif;color:#1d2128;background:#f3f4f6;margin:0;padding:14vh 6vw;text-align:center}</style></head>'
        . '<body><h1>' . e(wb_app_name()) . ' wird gerade aktualisiert</h1><p>Das dauert etwa eine Minute. Die Seite lädt von selbst neu.</p><p lang="en">Tafula is being updated. This page reloads by itself.</p></body></html>';
    exit;
}
