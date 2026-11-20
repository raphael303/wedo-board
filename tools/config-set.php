<?php
/**
 * Setzt einzelne Werte in einer config.php, ohne Kommentare und übrige Zeilen zu verändern, und prüft das Ergebnis.
 *
 *   php tools/config-set.php <config.php> <schlüssel> <json-wert> [<schlüssel> <json-wert> …]
 *
 * Erlaubt sind Werte, die in einer Zeile stehen (Zeichenkette, Zahl, true, false, null). Gibt es den Schlüssel schon in
 * einer eigenen Zeile, wird nur der Wert ersetzt; sonst kommt eine neue Zeile vor das Ende des Arrays. Der Wert
 * "__random_path__" erzeugt einen zufälligen Pfad (für admin_path). Werte werden nie ausgegeben. Die Datei wird nur
 * ersetzt, wenn die neue Fassung lädt, alle bisherigen Schlüssel behält und genau die gewünschten Werte trägt.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function cs_fail(string $m): never { fwrite(STDERR, "config-set: $m\n"); exit(1); }

$file = (string)($argv[1] ?? '');
$pairs = array_slice($argv, 2);
if ($file === '' || !$pairs || count($pairs) % 2) cs_fail('Aufruf: php tools/config-set.php <config.php> <schlüssel> <json-wert> …');
if (!is_file($file)) cs_fail("$file fehlt");
$before = (static fn($f) => require $f)($file);
if (!is_array($before)) cs_fail('config.php liefert kein Array');
$src = (string)file_get_contents($file);
$want = [];

for ($i = 0; $i < count($pairs); $i += 2) {
    $k = $pairs[$i];
    if (!preg_match('/^[a-z0-9_]+$/', $k)) cs_fail("ungültiger Schlüssel $k");
    $v = json_decode($pairs[$i + 1], true);
    if ($v === null && trim($pairs[$i + 1]) !== 'null') cs_fail("Wert für $k ist kein JSON");
    if ($v === '__random_path__') {
        do { $v = 'b-' . bin2hex(random_bytes(9)); } while (($before[$k] ?? null) === $v);
    }
    if (is_array($v) || is_object($v)) cs_fail("$k: nur einzeilige Werte");
    $want[$k] = $v;
    $lit = var_export($v, true);
    $re = '/^(\h*([\'"])' . preg_quote($k, '/') . '\2\h*=>\h*)(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|true|false|null|-?\d+(?:\.\d+)?)(\h*,)/mi';
    $n = preg_match_all($re, $src);
    if ($n === 1) {
        $src = (string)preg_replace_callback($re, fn($m) => $m[1] . $lit . $m[3], $src);
    } elseif ($n === 0 && !array_key_exists($k, $before)) {
        $end = max((int)strrpos($src, '];'), (int)strrpos($src, ');'));
        if ($end === 0) cs_fail('Ende des Arrays nicht gefunden');
        $indent = preg_match('/^(\h+)[\'"][a-z_]+[\'"]\h*=>/m', $src, $m) ? $m[1] : '    ';
        $src = substr($src, 0, $end) . $indent . var_export($k, true) . ' => ' . $lit . ",\n" . substr($src, $end);
    } else {
        cs_fail("$k steht nicht genau einmal in einer eigenen Zeile; bitte von Hand ändern");
    }
}

$tmp = $file . '.new-' . bin2hex(random_bytes(3));
file_put_contents($tmp, $src);
chmod($tmp, 0600);
$after = (static fn($f) => require $f)($tmp);
$ok = is_array($after) && !array_diff_key($before, $after);
foreach ($before as $k => $v) if (!array_key_exists($k, $want) && ($after[$k] ?? null) !== $v) $ok = false;
foreach ($want as $k => $v) if (!array_key_exists($k, $after) || $after[$k] !== $v) $ok = false;
if (!$ok) { unlink($tmp); cs_fail('Prüfung der neuen Fassung fehlgeschlagen; nichts geändert'); }
rename($tmp, $file);
echo 'gesetzt: ', implode(', ', array_keys($want)), "\n";
