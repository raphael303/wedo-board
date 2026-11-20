<?php
declare(strict_types=1);
/*
 * Notfall-Skript für den Super-Admin (Nutzerverwaltung, Abschnitt 6). Nur Kommandozeile, auf cyon im Ordner der
 * Installation; Claude startet es vom Pi aus über SSH. Jede Benutzung steht im Protokoll (audit_log).
 *
 *   php tools/rescue.php status                       Super-Admins mit Adresse, Bestätigung und App-Stufe
 *   php tools/rescue.php reset-password <adresse>     schickt einen Link zum Zurücksetzen an die Adresse des Kontos
 *   php tools/rescue.php remove-totp <adresse>        entfernt die zweite Stufe sofort und beendet alle Sitzungen
 *   php tools/rescue.php set-email <alt> <neu>        ändert die Adresse (wenn das Postfach nicht mehr erreichbar ist)
 *                                                     und benachrichtigt die alte Adresse
 *   php tools/rescue.php unlock <adresse>             hebt die Sperre des Kontos und die Bremsen der Anmeldung auf
 *   php tools/rescue.php unsuppress <adresse>         hebt die Unterdrückung einer Adresse im Mailversand auf
 *                                                     (für jede Adresse, nicht nur für Super-Admins)
 *   php tools/rescue.php gate-code                    (nur Testinstanz) neuer Zugangscode, per Mail an owner_email
 *
 * Das Skript wirkt (ausser unsuppress) nur auf Konten mit der Rolle Super-Admin und gibt nie ein Passwort oder
 * einen Code aus: Das Passwort setzt die Person über den Link in der Mail selbst.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/bootstrap.php';

function rescue_fail(string $msg, int $code = 1): never { fwrite(STDERR, "rescue: $msg\n"); exit($code); }

function rescue_superadmin(string $email): array {
    $u = user_by_email($email);
    if (!$u || !in_array('superadmin', user_system_roles((int)$u['id']), true)) rescue_fail('Zu dieser Adresse gibt es keinen Super-Admin.');
    return $u;
}

$cmd = $argv[1] ?? '';
if (!db_is_installed() || !db_schema_current()) rescue_fail('Das Schema ist nicht aktuell; zuerst ausrollen.');

switch ($cmd) {
    case 'status':
        foreach (db_all("SELECT u.* FROM users u JOIN memberships m ON m.user_id = u.id AND m.scope_type = 'system' AND m.role = 'superadmin' ORDER BY u.id") as $u) {
            echo $u['id'], ' ', $u['email'], ' · ', $u['email_verified_at'] ? 'bestätigt' : 'UNBESTÄTIGT', ' · App-Stufe ', $u['totp_secret_enc'] ? 'eingerichtet' : 'fehlt',
                $u['disabled_at'] ? ' · GESPERRT' : '', mail_suppressed((string)$u['email']) ? ' · MAILS UNTERDRÜCKT (unsuppress)' : '',
                ' · letzte Anmeldung ', $u['last_login_at'] ?? '–', "\n";
        }
        break;
    case 'reset-password':
        $u = rescue_superadmin((string)($argv[2] ?? ''));
        $token = login_token_create((int)$u['id'], 'reset_password', 3600);
        $id = mail_enqueue((string)$u['email'], 'reset', 'Passwort für ' . wb_app_name() . ' zurücksetzen (Notfall-Skript)',
            "Hallo " . user_name($u) . "\n\nÜber das Notfall-Skript wurde ein Link zum Zurücksetzen deines Passworts verlangt:\n\n" . account_link('/login/reset', $token) . "\n\nEr gilt eine Stunde und nur einmal.", (int)$u['id']);
        audit_log('rescue.reset_password', 'user', (int)$u['id']);
        $st = db_row('SELECT status, last_error FROM mail_queue WHERE id = ?', [$id]);
        echo 'Link verschickt an die Adresse des Kontos (Mail ', $id, ', Stand ', $st['status'] ?? '?', $st['last_error'] ? ': ' . $st['last_error'] : '', ").\n";
        break;
    case 'remove-totp':
        $u = rescue_superadmin((string)($argv[2] ?? ''));
        totp_disable((int)$u['id']);
        wb_sessions_revoke_user((int)$u['id']);
        db_exec('DELETE FROM known_devices WHERE user_id = ?', [(int)$u['id']]);
        audit_log('rescue.remove_totp', 'user', (int)$u['id']);
        account_notify($u, 'Die zweite Stufe deines Kontos bei ' . wb_app_name() . ' wurde entfernt (Notfall-Skript)', 'Die Anmeldung mit der Authenticator-App wurde über das Notfall-Skript ausgeschaltet. Richte sie nach der nächsten Anmeldung wieder ein.');
        echo "Zweite Stufe entfernt, alle Sitzungen beendet. Bei der nächsten Anmeldung kommt ein Mailcode, danach muss die App neu eingerichtet werden.\n";
        break;
    case 'set-email':
        $u = rescue_superadmin((string)($argv[2] ?? ''));
        $new = wb_email_normalize((string)($argv[3] ?? ''));
        if ($new === null || wb_email_is_card($new)) rescue_fail('Die neue Adresse ist ungültig.');
        $taken = user_by_email($new);
        if ($taken && (int)$taken['id'] !== (int)$u['id']) rescue_fail('Die neue Adresse gehört schon einem Konto.');
        $old = (string)$u['email'];
        db_exec('UPDATE users SET email = ? WHERE id = ?', [$new, (int)$u['id']]);
        wb_sessions_revoke_user((int)$u['id']);
        audit_log('rescue.set_email', 'user', (int)$u['id']);
        // Die alte Adresse erfährt davon (N10): Hat jemand anderes das Skript benutzt, merkt sie es.
        mail_enqueue($old, 'notice', 'Die Adresse deines Kontos bei ' . wb_app_name() . ' wurde geändert (Notfall-Skript)',
            'Hallo ' . user_name($u) . "\n\nÜber das Notfall-Skript wurde die Adresse deines Kontos bei " . wb_app_name() . " von $old auf $new geändert, und alle Sitzungen wurden beendet.\n\nWenn du das nicht veranlasst hast, wende dich sofort an die Betreiberin oder den Betreiber.", (int)$u['id']);
        echo "Adresse geändert, alle Sitzungen beendet, die alte Adresse ist benachrichtigt.\n";
        break;
    case 'unlock':
        $u = rescue_superadmin((string)($argv[2] ?? ''));
        db_exec('UPDATE users SET disabled_at = NULL WHERE id = ?', [(int)$u['id']]);
        $norm = (string)$u['email'];
        $keys = array_map('brake_key', ['login-acct|' . $norm, 'login-try|' . $norm, 'second|' . $u['id'], 'mailcode|' . $u['id'], 'reset-acct|' . $norm]);
        db_exec('DELETE FROM rate_limits WHERE author_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', $keys);
        audit_log('rescue.unlock', 'user', (int)$u['id']);
        echo "Konto freigegeben und Bremsen der Anmeldung für dieses Konto aufgehoben.\n";
        break;
    case 'unsuppress':
        $email = wb_email_normalize((string)($argv[2] ?? ''));
        if ($email === null) rescue_fail('Die Adresse ist ungültig.');
        $had = mail_unsuppress($email);
        audit_log('rescue.unsuppress', 'mail', null, ['email' => $email, 'had' => $had]);
        echo $had ? "Unterdrückung aufgehoben; Mails an diese Adresse gehen wieder hinaus.\n" : "Diese Adresse war nicht unterdrückt.\n";
        break;
    case 'gate-code':
        if (wb_instance() !== 'test') rescue_fail('Nur auf der Testinstanz.');
        $to = wb_email_normalize((string)wb_config('owner_email', ''));
        if ($to === null || !mail_goes_out($to)) rescue_fail('owner_email fehlt in config.php oder steht nicht in mail_allowlist; der Code käme nicht an.');
        $words = [];
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        for ($i = 0; $i < 3; $i++) { $w = ''; for ($j = 0; $j < 5; $j++) $w .= $alphabet[random_int(0, 30)]; $words[] = $w; }
        $code = implode('-', $words);
        gate_set_code(1, $code);
        $id = mail_enqueue($to, 'gate', 'Zugangscode der Testumgebung von ' . wb_app_name(),
            "Der Zugangscode der Testumgebung " . wb_base_url() . " lautet:\n\n    $code\n\nEr schützt Anmeldung, Registrierung, Testrollen und Sammelpostfach und wird 30 Tage gemerkt. Du kannst ihn unter /manage/system ändern.");
        audit_log('rescue.gate_code', 'settings');
        $st = db_row('SELECT status, last_error FROM mail_queue WHERE id = ?', [$id]);
        echo 'Neuer Zugangscode gesetzt und per Mail verschickt (Stand ', $st['status'] ?? '?', $st['last_error'] ? ': ' . $st['last_error'] : '', "). Er wird hier nicht angezeigt.\n";
        if (($st['status'] ?? '') !== 'sent') db_exec("UPDATE mail_queue SET body = '(Zugangscode, nicht zugestellt)' WHERE id = ?", [$id]);
        break;
    default:
        rescue_fail("Aufruf: php tools/rescue.php status | reset-password <adresse> | remove-totp <adresse> | set-email <alt> <neu> | unlock <adresse> | unsuppress <adresse> | gate-code", 2);
}
