<?php
/**
 * Entschlüsselt ein Archiv von tools/backup.php (nur auf dem Pi, docs/SICHERUNG.md).
 *
 *   php tools/backup-decrypt.php <archiv.tfbk> <ziel.tar.gz>
 *
 * Der private Schlüssel kommt aus der Umgebungsvariable TAFULA_BACKUP_SECRET oder aus .env im Wurzelordner des
 * Workspace (Zeile TAFULA_BACKUP_SECRET=…, Rechte 600). Bricht ab, wenn das Archiv verändert oder abgeschnitten ist.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
if ($argc !== 3) { fwrite(STDERR, "Aufruf: php tools/backup-decrypt.php <archiv.tfbk> <ziel.tar.gz>\n"); exit(2); }
[$_, $in, $out] = $argv;

$secret = getenv('TAFULA_BACKUP_SECRET') ?: '';
if ($secret === '' && is_file(dirname(__DIR__) . '/.env')) {
    foreach (file(dirname(__DIR__) . '/.env') as $line) {
        if (preg_match('/^TAFULA_BACKUP_SECRET=(\S+)/', $line, $m)) $secret = $m[1];
    }
}
$sk = base64_decode($secret, true);
if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) { fwrite(STDERR, "Privater Schlüssel fehlt (TAFULA_BACKUP_SECRET).\n"); exit(1); }
$kp = sodium_crypto_box_keypair_from_secretkey_and_publickey($sk, sodium_crypto_box_publickey_from_secretkey($sk));

$fi = fopen($in, 'rb');
if (!$fi || fread($fi, 16) !== 'TAFULABACKUP0001') { fwrite(STDERR, "Kein Tafula-Archiv: $in\n"); exit(1); }
$key = sodium_crypto_box_seal_open((string)fread($fi, 32 + SODIUM_CRYPTO_BOX_SEALBYTES), $kp);
if ($key === false) { fwrite(STDERR, "Schlüssel passt nicht zu diesem Archiv.\n"); exit(1); }
$state = sodium_crypto_secretstream_xchacha20poly1305_init_pull((string)fread($fi, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $key);
$fo = fopen($out, 'xb');
if (!$fo) { fwrite(STDERR, "Ziel existiert schon oder ist nicht schreibbar: $out\n"); exit(1); }
$final = false;
while (!$final) {
    $len = fread($fi, 4);
    if ($len === false || strlen($len) !== 4) break;
    $c = (string)fread($fi, unpack('N', $len)[1]);
    $r = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $c);
    if ($r === false) { fclose($fo); unlink($out); fwrite(STDERR, "Archiv beschädigt oder verändert.\n"); exit(1); }
    fwrite($fo, $r[0]);
    $final = $r[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
}
fclose($fo);
if (!$final || !feof($fi) && fread($fi, 1) !== '') { unlink($out); fwrite(STDERR, "Archiv abgeschnitten oder mit Anhang.\n"); exit(1); }
echo "$out\n";
