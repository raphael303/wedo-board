<?php
declare(strict_types=1);

/*
 * Mail (Nutzerverwaltung, Abschnitt 15): Warteschlange mail_queue, Versand über SMTP in reinem PHP, Sammelpostfach
 * ausserhalb der Produktion, Rückläufer über IMAP in reinem PHP.
 *
 * config.php: 'smtp' => ['host', 'port', 'secure' => 'ssl', 'user', 'pass', 'from', 'from_name'].
 * Nur Sandbox und Testinstanz: 'mail_allowlist' => [Adressen, die echte Mails erhalten]; alle anderen Mails
 * bleiben mit Status «sink» in mail_queue und sind unter /test/mail lesbar. Ohne 'smtp' gilt das für jede Mail.
 * 'mail_bounces' => true lässt den Cronjob dieser Installation das Postfach des Absenders lesen (genau eine
 * Installation pro Postfach).
 *
 * Regeln: nie an Kennungen unter k.tafula.top, nie an eine Adresse in mail_suppressions, höchstens
 * MAIL_HOURLY_LIMIT Mails pro Stunde (cyon erlaubt 100 pro Stunde und Adresse).
 */

const MAIL_HOURLY_LIMIT = 90;
const MAIL_MAX_ATTEMPTS = 5;
/** Arten, die sofort hinausgehen, weil jemand an der Tastatur darauf wartet. */
const MAIL_URGENT = ['login_code', 'verify', 'reset', 'invite', 'gate'];

function mail_config(): ?array {
    $c = wb_config('smtp');
    return is_array($c) && !empty($c['host']) && !empty($c['user']) && !empty($c['from']) ? $c : null;
}

/** Geht diese Mail wirklich hinaus? Ausserhalb der Produktion nur an Adressen der mail_allowlist. */
function mail_goes_out(string $to): bool {
    if (mail_config() === null) return false;
    if (wb_is_production()) return true;
    $allow = array_map(fn($a) => mb_strtolower(trim((string)$a)), (array)wb_config('mail_allowlist', []));
    return in_array(mb_strtolower($to), $allow, true);
}

/**
 * Stellt eine Mail in die Warteschlange. $body ist Klartext; die Fusszeile kommt hier dazu.
 * Gibt die Nummer in mail_queue zurück (0, wenn an diese Adresse nie etwas geht).
 */
function mail_enqueue(string $to, string $kind, string $subject, string $body, ?int $userId = null): int {
    $to = wb_email_normalize($to);
    if ($to === null || wb_email_is_card($to)) return 0;
    $body = rtrim($body) . "\n\n-- \n" . wb_app_name() . ' · ' . wb_base_url() . "\n" . tx('Diese Nachricht wurde automatisch verschickt. Antworten werden nicht gelesen.') . "\n";
    $status = 'queued';
    if (db_row('SELECT id FROM mail_suppressions WHERE email = ?', [$to])) $status = 'suppressed';
    elseif (!mail_goes_out($to)) $status = wb_is_production() ? 'failed' : 'sink';
    $now = wb_now();
    $id = db_insert('INSERT INTO mail_queue (to_addr, to_user_id, kind, subject, body, status, next_attempt_at, last_error, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$to, $userId, mb_substr($kind, 0, 32), mb_substr($subject, 0, 250), $body, $status, $status === 'queued' ? $now : null,
         $status === 'failed' ? 'Kein SMTP-Zugang in config.php.' : null, $now]);
    if ($status === 'queued' && in_array($kind, MAIL_URGENT, true)) mail_send_one($id);
    return $id;
}

function mail_sent_last_hour(): int {
    return (int)db_row('SELECT COUNT(*) AS n FROM mail_queue WHERE status = ? AND sent_at > ?', ['sent', wb_utc(time() - 3600)])['n'];
}

/** Versendet eine Mail aus der Warteschlange. true, wenn sie hinausging. */
function mail_send_one(int $id): bool {
    $m = db_row("SELECT * FROM mail_queue WHERE id = ? AND status = 'queued'", [$id]);
    if (!$m) return false;
    if (mail_sent_last_hour() >= MAIL_HOURLY_LIMIT) return false; // bleibt in der Warteschlange
    // Vormerken, damit ein gleichzeitiger Lauf dieselbe Mail nicht noch einmal nimmt.
    $attempts = (int)$m['attempts'] + 1;
    if (db_exec("UPDATE mail_queue SET attempts = ?, next_attempt_at = ? WHERE id = ? AND status = 'queued' AND attempts = ?",
        [$attempts, wb_utc(time() + min(3600, 60 * (2 ** $attempts))), $id, (int)$m['attempts']]) !== 1) return false;
    $cfg = mail_config();
    $messageId = bin2hex(random_bytes(12)) . '@' . wb_email_domain((string)$cfg['from']);
    try {
        smtp_send($cfg, (string)$m['to_addr'], (string)$m['subject'], (string)$m['body'], $messageId);
        db_exec("UPDATE mail_queue SET status = 'sent', sent_at = ?, message_id = ?, last_error = NULL, next_attempt_at = NULL WHERE id = ?", [wb_now(), $messageId, $id]);
        return true;
    } catch (Throwable $e) {
        $err = mb_substr($e->getMessage(), 0, 500);
        // 5xx des Servers ist endgültig, alles andere wird später noch einmal versucht.
        $final = $attempts >= MAIL_MAX_ATTEMPTS || preg_match('/^SMTP 5\d\d/', $err);
        // Der Server lehnt die Empfängerin endgültig ab: wie ein Rückläufer behandeln.
        if (preg_match('/^SMTP 5\d\d bei RCPT TO/', $err)) db_exec('INSERT IGNORE INTO mail_suppressions (email, reason, detail, created_at) VALUES (?, ?, ?, ?)', [(string)$m['to_addr'], 'rejected', $err, wb_now()]);
        db_exec('UPDATE mail_queue SET status = ?, last_error = ? WHERE id = ?', [$final ? 'failed' : 'queued', $err, $id]);
        error_log(wb_app_name() . ' mail: ' . $err);
        return false;
    }
}

/** Arbeitet die Warteschlange ab (Cronjob): höchstens $max Mails mit einer Pause dazwischen. */
function mail_send_queued(int $max = 20, int $pauseMs = 1500): int {
    $sent = 0;
    $rows = db_all("SELECT id FROM mail_queue WHERE status = 'queued' AND (next_attempt_at IS NULL OR next_attempt_at <= ?) ORDER BY id LIMIT " . max(1, min(100, $max)), [wb_now()]);
    foreach ($rows as $i => $r) {
        if (mail_sent_last_hour() >= MAIL_HOURLY_LIMIT) break;
        if ($i > 0) usleep($pauseMs * 1000);
        if (mail_send_one((int)$r['id'])) $sent++;
    }
    return $sent;
}

/* ---------- SMTP ---------- */

function smtp_send(array $cfg, string $to, string $subject, string $body, string $messageId): void {
    $secure = ($cfg['secure'] ?? 'ssl') === 'ssl';
    $fp = @stream_socket_client(($secure ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . (int)($cfg['port'] ?? 465), $errno, $errstr, 15);
    if (!$fp) throw new RuntimeException('SMTP-Verbindung fehlgeschlagen: ' . $errstr);
    stream_set_timeout($fp, 20);
    $read = function () use ($fp): string {
        $out = '';
        while (($l = fgets($fp, 2048)) !== false) { $out .= $l; if (isset($l[3]) && $l[3] === ' ') break; }
        return $out;
    };
    $expect = function (string $resp, array $codes, string $what): void {
        if (!in_array((int)substr($resp, 0, 3), $codes, true)) throw new RuntimeException('SMTP ' . substr($resp, 0, 3) . ' bei ' . $what . ': ' . trim(substr($resp, 4, 200)));
    };
    $cmd = function (string $line, array $codes, string $what) use ($fp, $read, $expect): string {
        fwrite($fp, $line . "\r\n");
        $r = $read();
        $expect($r, $codes, $what);
        return $r;
    };
    try {
        $expect($read(), [220], 'Begrüssung');
        $helo = parse_url(wb_base_url(), PHP_URL_HOST) ?: 'localhost';
        $cmd('EHLO ' . $helo, [250], 'EHLO');
        if (!$secure && ($cfg['secure'] ?? '') === 'tls') {
            $cmd('STARTTLS', [220], 'STARTTLS');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP: TLS fehlgeschlagen');
            $cmd('EHLO ' . $helo, [250], 'EHLO');
        }
        $cmd('AUTH LOGIN', [334], 'AUTH');
        $cmd(base64_encode((string)$cfg['user']), [334], 'Benutzer');
        $cmd(base64_encode((string)($cfg['pass'] ?? '')), [235], 'Anmeldung');
        $cmd('MAIL FROM:<' . $cfg['from'] . '>', [250], 'MAIL FROM');
        $cmd('RCPT TO:<' . $to . '>', [250, 251], 'RCPT TO');
        $cmd('DATA', [354], 'DATA');
        $enc = fn(string $s): string => preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
        $clean = fn(string $s): string => trim(preg_replace('/[\r\n]+/', ' ', $s) ?? '');
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $enc($clean((string)($cfg['from_name'] ?? wb_app_name()))) . ' <' . $cfg['from'] . '>',
            'To: <' . $to . '>',
            'Subject: ' . $enc($clean($subject)),
            'Message-ID: <' . $messageId . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: base64',
            'Auto-Submitted: auto-generated',
            'X-Auto-Response-Suppress: All',
        ];
        $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $body)), 76, "\r\n");
        $cmd(rtrim($data, "\r\n") . "\r\n.", [250], 'Nachricht');
        @fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}

/* ---------- Rückläufer ---------- */

/**
 * Liest ungelesene Nachrichten im Postfach des Absenders über IMAP. Eine Zustellmeldung (DSN) mit dauerhaftem
 * Fehler (5.x.x) trägt die Adresse in mail_suppressions ein und setzt die Mail auf «bounced»; erkannte Meldungen
 * werden im Postfach gelöscht, alles andere bleibt als gelesen liegen. Gibt die Anzahl erkannter Rückläufer zurück.
 */
function mail_read_bounces(int $max = 30): int {
    $cfg = mail_config();
    if ($cfg === null || !wb_config('mail_bounces', false)) return 0;
    $host = (string)($cfg['imap_host'] ?? $cfg['host']);
    $fp = @stream_socket_client('ssl://' . $host . ':' . (int)($cfg['imap_port'] ?? 993), $errno, $errstr, 15);
    if (!$fp) throw new RuntimeException('IMAP-Verbindung fehlgeschlagen: ' . $errstr);
    stream_set_timeout($fp, 20);
    $n = 0;
    $tag = 0;
    $cmd = function (string $line) use ($fp, &$tag): array {
        $t = 'A' . (++$tag);
        fwrite($fp, $t . ' ' . $line . "\r\n");
        $lines = [];
        while (($l = fgets($fp, 8192)) !== false) {
            if (str_starts_with($l, $t . ' ')) { $lines[] = $l; break; }
            // Literal {n}: die folgenden n Bytes gehören zur Antwort.
            if (preg_match('/\{(\d+)\}\r?\n$/', $l, $m)) {
                $left = (int)$m[1]; $buf = '';
                while ($left > 0 && ($chunk = fread($fp, min(8192, $left))) !== false && $chunk !== '') { $buf .= $chunk; $left -= strlen($chunk); }
                $lines[] = $l . $buf;
                continue;
            }
            $lines[] = $l;
        }
        $last = (string)end($lines);
        if (!preg_match('/^' . $t . ' OK/i', $last)) throw new RuntimeException('IMAP: ' . trim(substr($last, 0, 200)));
        return $lines;
    };
    $quote = fn(string $s): string => '"' . addcslashes($s, "\\\"") . '"';
    try {
        fgets($fp, 2048);
        $cmd('LOGIN ' . $quote((string)$cfg['user']) . ' ' . $quote((string)($cfg['pass'] ?? '')));
        $cmd('SELECT INBOX');
        $ids = [];
        foreach ($cmd('UID SEARCH UNSEEN') as $l) {
            if (preg_match('/^\* SEARCH(.*)$/i', trim($l), $m)) $ids = array_filter(array_map('intval', preg_split('/\s+/', trim($m[1])) ?: []));
        }
        foreach (array_slice(array_values($ids), 0, $max) as $uid) {
            $raw = implode('', $cmd('UID FETCH ' . $uid . ' (BODY.PEEK[]<0.40000>)'));
            $b = mail_parse_bounce($raw);
            if ($b === null) { $cmd('UID STORE ' . $uid . ' +FLAGS (\Seen)'); continue; }
            if ($b['permanent']) {
                db_exec('INSERT IGNORE INTO mail_suppressions (email, reason, detail, created_at) VALUES (?, ?, ?, ?)', [$b['email'], 'bounce', mb_substr($b['detail'], 0, 500), wb_now()]);
            }
            if ($b['message_id'] !== null) {
                db_exec("UPDATE mail_queue SET status = 'bounced', last_error = ? WHERE message_id = ? AND status = 'sent'", [mb_substr($b['detail'], 0, 500), $b['message_id']]);
            } elseif ($b['permanent']) {
                db_exec("UPDATE mail_queue SET status = 'bounced', last_error = ? WHERE to_addr = ? AND status = 'sent' AND sent_at > ?", [mb_substr($b['detail'], 0, 500), $b['email'], wb_utc(time() - 3 * 86400)]);
            }
            $cmd('UID STORE ' . $uid . ' +FLAGS (\Seen \Deleted)');
            $n++;
        }
        if ($n > 0) $cmd('EXPUNGE');
        @fwrite($fp, "Z1 LOGOUT\r\n");
    } finally {
        fclose($fp);
    }
    return $n;
}

/**
 * Erkennt eine Zustellmeldung im Rohtext einer Mail. Gibt ['email', 'permanent', 'detail', 'message_id'] zurück
 * oder null, wenn die Nachricht keine ist. Der Inhalt ist Fremdtext und wird nur nach festen Mustern gelesen.
 */
function mail_parse_bounce(string $raw): ?array {
    if (!preg_match('/^(?:Final|Original)-Recipient:\s*rfc822;\s*<?([^\s<>]+@[^\s<>]+)>?/mi', $raw, $m)) {
        // Exim ohne DSN-Teil: «A message that you sent could not be delivered» mit der Adresse auf einer eigenen Zeile.
        if (!preg_match('/could not be delivered|Undelivered Mail|Delivery Status Notification|Unzustellbar/i', $raw)) return null;
        if (!preg_match('/^\s{1,4}<?([^\s<>@]+@[^\s<>@]+\.[a-z]{2,})>?\s*$/mi', $raw, $m)) return null;
    }
    $email = wb_email_normalize($m[1]);
    if ($email === null) return null;
    $status = preg_match('/^Status:\s*([245])\.\d+\.\d+/mi', $raw, $s) ? $s[1] : null;
    $diag = preg_match('/^Diagnostic-Code:\s*(.+(?:\r?\n[ \t].+)*)/mi', $raw, $d) ? trim(preg_replace('/\s+/', ' ', $d[1]) ?? '') : '';
    if ($status === null) $status = preg_match('/\b5\d\d[ -]/', $diag . ' ' . substr($raw, 0, 20000)) ? '5' : '4';
    $mid = null;
    $own = preg_quote(wb_email_domain((string)(mail_config()['from'] ?? '')), '/');
    if ($own !== '' && preg_match('/Message-ID:\s*<([0-9a-f]{24}@' . $own . ')>/i', $raw, $x)) $mid = $x[1];
    if ($status === '2') return null;
    return ['email' => $email, 'permanent' => $status === '5', 'detail' => $diag !== '' ? $diag : 'Zustellmeldung ohne Diagnose', 'message_id' => $mid];
}

/** Sammelpostfach der Testumgebung: alle Mails, neueste zuerst. */
function mail_sink_list(int $limit = 100): array {
    return db_all('SELECT id, to_addr, kind, subject, status, created_at, last_error FROM mail_queue ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
}
