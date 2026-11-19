<?php
declare(strict_types=1);

/*
 * Hilfe: Markdown-Dateien unter lang/help/<sprache>/<thema>.md, ausgeliefert unter /help/<thema>.
 * Der Darsteller kennt nur, was die Hilfe braucht: Überschriften, Absätze, Listen, **fett**, `Code`, Links auf
 * eigene Seiten und auf https-Adressen. Alles andere wird als Text ausgegeben; HTML in den Dateien wird entschärft.
 */

function help_file(string $topic, string $lang): ?string {
    if (!preg_match('/^[a-z0-9-]{1,40}$/', $topic)) return null;
    foreach (array_unique([$lang, 'de']) as $l) {
        if (!preg_match('/^[a-z]{2}$/', $l)) continue;
        $f = WB_ROOT . '/lang/help/' . $l . '/' . $topic . '.md';
        if (is_file($f)) return $f;
    }
    return null;
}

function help_inline(string $s): string {
    $s = e($s);
    $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s) ?? $s;
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s) ?? $s;
    // Links nur auf eigene Pfade («/…», nicht «//…») und auf https-Adressen.
    $s = preg_replace_callback('/\[([^\]]+)\]\(((?:\/(?!\/)|https:\/\/)[^)\s]*)\)/', fn($m) => '<a href="' . $m[2] . '">' . $m[1] . '</a>', $s) ?? $s;
    return $s;
}

function help_render(string $md): string {
    $out = '';
    $para = [];
    $list = null;
    $flush = function () use (&$out, &$para, &$list): void {
        if ($para) { $out .= '<p>' . help_inline(implode(' ', $para)) . "</p>\n"; $para = []; }
        if ($list !== null) { $out .= "</$list>\n"; $list = null; }
    };
    foreach (preg_split('/\r?\n/', $md) ?: [] as $line) {
        if (trim($line) === '') { $flush(); continue; }
        if (preg_match('/^(#{1,3})\s+(.*)$/', $line, $m)) {
            $flush();
            $h = strlen($m[1]) + 1; // «#» ist der Seitentitel unter dem h1 «Hilfe»
            $out .= "<h$h>" . help_inline($m[2]) . "</h$h>\n";
            continue;
        }
        if (preg_match('/^\s*(?:[-*]|(\d+)\.)\s+(.*)$/', $line, $m)) {
            $tag = ($m[1] ?? '') !== '' ? 'ol' : 'ul';
            if ($para) { $out .= '<p>' . help_inline(implode(' ', $para)) . "</p>\n"; $para = []; }
            if ($list !== $tag) { if ($list !== null) $out .= "</$list>\n"; $out .= "<$tag>\n"; $list = $tag; }
            $out .= '<li>' . help_inline($m[2]) . "</li>\n";
            continue;
        }
        if ($list !== null) { $out .= "</$list>\n"; $list = null; }
        $para[] = trim($line);
    }
    $flush();
    return $out;
}

/** Einfache Suche über alle Hilfeseiten einer Sprache (mit deutschem Ersatz). */
function help_search(string $q, string $lang): array {
    $q = mb_strtolower(trim($q));
    if (mb_strlen($q) < 2) return [];
    $hits = [];
    foreach (array_unique(['de', $lang]) as $l) {
        if (!preg_match('/^[a-z]{2}$/', $l)) continue;
        foreach (glob(WB_ROOT . '/lang/help/' . $l . '/*.md') ?: [] as $f) {
            $topic = basename($f, '.md');
            $text = (string)file_get_contents($f);
            $pos = mb_stripos($text, $q);
            if ($pos === false) continue;
            $title = preg_match('/^#\s+(.*)$/m', $text, $m) ? $m[1] : $topic;
            $hits[$topic] = ['topic' => $topic, 'title' => $title, 'excerpt' => '… ' . trim(preg_replace('/\s+/', ' ', mb_substr($text, max(0, $pos - 40), 140)) ?? '') . ' …'];
        }
    }
    return array_values($hits);
}
