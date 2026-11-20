<?php
declare(strict_types=1);
/*
 * Cronjob von Tafula (seit 0.22.0), jede Minute über die crontab auf cyon:
 *
 *   * * * * * /opt/alt/php83/usr/bin/php -f <ordner>/tools/cron.php >/dev/null 2>&1
 *
 * Jede Minute: Warteschlange der Mails. Alle fünf Minuten: Rückläufer lesen (nur mit 'mail_bounces' => true in
 * config.php). Stündlich: unbestätigte Konten, fällige Entfernungen der zweiten Stufe, abgelaufene Sitzungen,
 * Links und Geräte, Papierkorb. Eine Sperrdatei verhindert zwei gleichzeitige Läufe. Ausgabe nur bei --verbose.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/bootstrap.php';

$verbose = in_array('--verbose', $argv, true);
$force = in_array('--all', $argv, true);
$say = function (string $s) use ($verbose): void { if ($verbose) echo $s, "\n"; };

if (!db_is_installed() || !db_schema_current()) { $say('Schema nicht aktuell, nichts getan.'); exit(0); }
$lock = fopen(sys_get_temp_dir() . '/tafula-cron-' . substr(hash('sha256', WB_ROOT), 0, 12) . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { $say('Ein anderer Lauf ist noch unterwegs.'); exit(0); }

$minute = intdiv(time(), 60);
try {
    $say('Mails versendet: ' . mail_send_queued(20));
    if ($force || $minute % 5 === 0) {
        try {
            $say('Rückläufer erkannt: ' . mail_read_bounces());
        } catch (Throwable $e) {
            error_log(wb_app_name() . ' cron (Rückläufer): ' . $e->getMessage());
            $say('Rückläufer: ' . $e->getMessage());
        }
    }
    $last = (int)setting_get('cron_hourly_at', '0');
    if ($force || time() - $last >= 3600) {
        setting_set('cron_hourly_at', (string)time());
        $r = accounts_cleanup();
        $say("Aufgeräumt: {$r['unverified']} unbestätigte Konten gelöscht, {$r['totp_removed']} Entfernungen der zweiten Stufe wirksam.");
        $say('Papierkorb: ' . board_purge_deleted(30) . ' Elemente endgültig entfernt.');
        $m = mail_cleanup();
        $say("Mails: {$m['suppressions']} Unterdrückungen verfallen, {$m['bodies']} Texte entfernt.");
    }
    setting_set('cron_last_at', (string)time());
} catch (Throwable $e) {
    error_log(wb_app_name() . ' cron: ' . $e->getMessage());
    $say('Fehler: ' . $e->getMessage());
    exit(1);
}
