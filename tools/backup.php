<?php
/**
 * Nächtliche, verschlüsselte Sicherung einer Tafula-Installation (docs/SICHERUNG.md).
 *
 *   php tools/backup.php [--site=<ordner>] [--db-only] [--out=<ordner>] [--keep-days=14] [--quiet]
 *
 * Ohne --site sichert das Skript die Installation, in der es liegt. Die Produktion wird vollständig gesichert
 * (Datenbank, uploads/, config.php), die Testinstanz nur mit der Datenbank (sie erkennt sich an 'instance' => 'test'
 * in config.php; --db-only erzwingt das). Das Archiv (tar.gz) wird mit dem öffentlichen Schlüssel
 * 'backup_public_key' aus config.php verschlüsselt und nach ~/backups_pi/nightly/<art>/ gelegt; der private Schlüssel
 * liegt nur auf dem Pi. Eigene Archive, die älter als --keep-days Tage sind, löscht das Skript.
 *
 * Dateiformat (.tfbk): "TAFULABACKUP0001", dann der mit sodium_crypto_box_seal versiegelte Schlüssel (80 Bytes),
 * der Kopf von secretstream (24 Bytes) und Blöcke aus je 4 Bytes Länge (big endian) und Geheimtext; der letzte Block
 * trägt die Marke FINAL. Entschlüsseln: tools/backup-decrypt.php auf dem Pi.
 *
 * Nur Kommandozeile; benutzt weder die Datenbankverbindung von Tafula noch bootstrap.php, damit es auch dann läuft,
 * wenn das Schema veraltet ist.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const TFBK_MAGIC = 'TAFULABACKUP0001';
const TFBK_CHUNK = 65536;

function bk_fail(string $msg): void {
    fwrite(STDERR, "backup: $msg\n");
    bk_log("FEHLER $msg");
    exit(1);
}

function bk_log(string $line): void {
    $dir = ($_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir()) . '/backups_pi/nightly';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    @file_put_contents($dir . '/backup.log', date('c') . ' ' . ($GLOBALS['bk_kind'] ?? '?') . ' ' . $line . "\n", FILE_APPEND);
}

/** Führt ein Programm ohne Shell aus; stdout optional in eine Datei. */
function bk_run(array $cmd, ?string $stdoutFile = null): void {
    $desc = [0 => ['file', '/dev/null', 'r'], 1 => $stdoutFile ? ['file', $stdoutFile, 'w'] : ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = proc_open($cmd, $desc, $pipes);
    if (!is_resource($p)) bk_fail('Programm nicht startbar: ' . $cmd[0]);
    $out = isset($pipes[1]) ? stream_get_contents($pipes[1]) : '';
    $err = stream_get_contents($pipes[2]);
    foreach ($pipes as $pp) fclose($pp);
    $code = proc_close($p);
    if ($code !== 0) bk_fail($cmd[0] . " endete mit $code: " . trim($err . ' ' . $out));
}

function bk_rmtree(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($dir);
}

/** Verschlüsselt $in nach $out (Format siehe oben). */
function bk_encrypt(string $in, string $out, string $publicKey): void {
    $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
    [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
    $sealed = sodium_crypto_box_seal($key, $publicKey);
    sodium_memzero($key);
    $fi = fopen($in, 'rb');
    $fo = fopen($out, 'wb');
    if (!$fi || !$fo) bk_fail('Dateien für die Verschlüsselung nicht offen');
    fwrite($fo, TFBK_MAGIC . $sealed . $header);
    $size = filesize($in);
    $done = 0;
    do {
        $chunk = (string)fread($fi, TFBK_CHUNK);
        $done += strlen($chunk);
        $final = $done >= $size || feof($fi);
        $tag = $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
        $c = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
        fwrite($fo, pack('N', strlen($c)) . $c);
    } while (!$final);
    fclose($fi);
    if (!fflush($fo) || !fclose($fo)) bk_fail('Schreiben des Archivs fehlgeschlagen');
}

// ---- Argumente ----
$opt = getopt('', ['site:', 'db-only', 'out:', 'keep-days:', 'quiet']);
$site = rtrim((string)($opt['site'] ?? dirname(__DIR__)), '/');
$home = (string)($_SERVER['HOME'] ?? getenv('HOME') ?: '');
if (!is_file("$site/config.php")) bk_fail("keine config.php in $site");
$cfg = require "$site/config.php";
if (!is_array($cfg) || !isset($cfg['db']['name'])) bk_fail('config.php ohne Datenbank');
$kind = ($cfg['instance'] ?? '') === 'test' ? 'test' : ((string)($cfg['instance'] ?? '') === '' ? 'prod' : (string)$cfg['instance']);
$GLOBALS['bk_kind'] = $kind;
$dbOnly = isset($opt['db-only']) || $kind !== 'prod';
$out = rtrim((string)($opt['out'] ?? "$home/backups_pi/nightly/$kind"), '/');
$keepDays = max(1, (int)($opt['keep-days'] ?? 14));
$pk = base64_decode((string)($cfg['backup_public_key'] ?? ''), true);
if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) bk_fail("'backup_public_key' fehlt oder ist ungültig in config.php");

// ---- Arbeitsordner ----
umask(077);
$stamp = date('Ymd-His');
$tmp = sys_get_temp_dir() . "/tafula-backup-$kind-$stamp-" . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0700) || !mkdir("$tmp/b", 0700)) bk_fail('Arbeitsordner nicht anlegbar');
register_shutdown_function(fn() => bk_rmtree($tmp));

// Datenbank: Zugangsdaten nur über eine kurzlebige Datei mit Rechten 600.
$db = $cfg['db'];
$cnf = "$tmp/my.cnf";
file_put_contents($cnf, "[client]\nuser=" . $db['user'] . "\npassword=\"" . addcslashes((string)$db['pass'], "\\\"") . "\"\nhost=" . $db['host'] . "\n" . (isset($db['port']) ? 'port=' . (int)$db['port'] . "\n" : ''));
chmod($cnf, 0600);
$dumpBin = is_executable('/usr/bin/mariadb-dump') ? '/usr/bin/mariadb-dump' : 'mysqldump';
bk_run([$dumpBin, "--defaults-extra-file=$cnf", '--single-transaction', '--no-tablespaces', '--routines', '--default-character-set=utf8mb4', (string)$db['name']], "$tmp/b/db.sql");
unlink($cnf);
if (filesize("$tmp/b/db.sql") < 200 || !str_contains((string)file_get_contents("$tmp/b/db.sql", false, null, -400), 'Dump completed')) bk_fail('Abzug der Datenbank unvollständig');

// Angaben zur Sicherung.
$commit = trim((string)@shell_exec('git -C ' . escapeshellarg($site) . ' rev-parse HEAD 2>/dev/null'));
$schema = null;
if (preg_match("/\\('schema_version','(\\d+)'\\)/", (string)file_get_contents("$tmp/b/db.sql"), $m)) $schema = (int)$m[1];
$files = ['db.sql', 'meta.json'];
if (!$dbOnly) {
    copy("$site/config.php", "$tmp/b/config.php");
    $files[] = 'config.php';
}
file_put_contents("$tmp/b/meta.json", json_encode([
    'format' => 1, 'kind' => $kind, 'created' => date('c'), 'site' => $site, 'commit' => $commit ?: null,
    'schema_version' => $schema, 'db_only' => $dbOnly, 'host' => php_uname('n'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$tar = "$tmp/backup.tar.gz";
$cmd = ['tar', '-czf', $tar, '-C', "$tmp/b", ...$files];
if (!$dbOnly && is_dir("$site/uploads")) array_push($cmd, '-C', $site, 'uploads');
bk_run($cmd);

// ---- Verschlüsseln und ablegen ----
if (!is_dir($out) && !mkdir($out, 0700, true)) bk_fail("Zielordner $out nicht anlegbar");
$name = "tafula-$kind-$stamp.tfbk";
bk_encrypt($tar, "$out/.$name.part", $pk);
chmod("$out/.$name.part", 0600);
rename("$out/.$name.part", "$out/$name");

// ---- Aufräumen: eigene Archive älter als keep-days ----
$removed = 0;
foreach (glob("$out/tafula-$kind-*.tfbk") ?: [] as $f) {
    if (preg_match('/-(\d{8})-\d{6}\.tfbk$/', $f, $m) && strtotime($m[1]) < strtotime("-$keepDays days midnight")) { unlink($f); $removed++; }
}
foreach (glob("$out/.tafula-$kind-*.part") ?: [] as $f) { if (filemtime($f) < time() - 86400) unlink($f); }

$size = filesize("$out/$name");
bk_log("ok $name " . $size . " Bytes, Schema " . ($schema ?? '?') . ", entfernt $removed");
if (!isset($opt['quiet'])) echo "$out/$name ($size Bytes, Schema " . ($schema ?? '?') . ($dbOnly ? ', nur Datenbank' : ', mit uploads und config.php') . ")\n";
