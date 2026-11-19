<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

// Die Bibliothek ist nur unter wb_admin_url() erreichbar: solange die Registrierung auf «aus» steht unter
// <base_url>/<admin_path>/ (config.php), sonst unter /library/. Aufrufe über den echten Ordnernamen /admin/
// ergeben 404, wenn der Pfad anders lautet.
if (!str_starts_with((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), wb_admin_url())) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Nicht gefunden.');
}

if (!db_is_installed()) {
    header('Location: /install.php');
    exit;
}

$adminLang = 'de';

/** Angemeldet mit einer Sitzung; sonst weiter zu /login. Rechte am einzelnen Objekt prüft jede Seite mit wb_can(). */
function admin_require_login(): void {
    ui_require_login();
}

function admin_check_csrf(): void {
    ui_check_csrf();
}

function admin_flash(?string $msg = null, int $undoId = 0, string $boardKey = ''): ?string {
    if ($msg !== null) {
        wb_flash($msg, $undoId > 0 ? ['undo_id' => $undoId, 'key' => $boardKey] : null);
        return null;
    }
    return wb_flash()['msg'] ?? null;
}

function admin_header(string $title, string $active = ''): void {
    ui_header($title, $active);
}

function admin_footer(): void {
    ui_footer();
}

/** Urheber einer protokollierten Änderung lesbar machen. */
function admin_actor_label(?string $actor): string {
    if ($actor === 'teacher') return 'Lehrperson';
    if ($actor === 'claude') return 'Claude';
    if ($actor !== null && str_starts_with($actor, 'ai:')) return 'KI (' . substr($actor, 3) . ')';
    return (string)$actor;
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
