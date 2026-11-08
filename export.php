<?php
declare(strict_types=1);
/**
 * Inhaltlicher Export eines Boards als Druckseite (daraus PDF über den Browser), für alle, die das Board öffnen
 * dürfen. Adresse: /b/<schlüssel>/export?ai=none|board|units&list=1&group=columns|bundles|time&img=thumb|large|none
 * &comments=1&reactions=1&newpage=0. Enthält keine Fantasienamen, keine Browser-Schlüssel und keine Zeitstempel
 * einzelner Beiträge. Die KI-Zusammenfassung erzeugt die Seite nicht selbst: Fehlt sie, fordert das Skript sie
 * per POST an (?summary=<art>), wartet bei «pending» per Abfrage (?summary=<art>&status=1) und lädt dann neu.
 */
require __DIR__ . '/lib/bootstrap.php';

$board = db_is_installed() ? board_by_key((string)($_GET['key'] ?? '')) : null;
$lang = wb_resolve_lang($board['lang'] ?? (string)wb_config('default_lang', 'de'));
if (!$board || $board['status'] === 'archived') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit(t('not_found', $lang));
}
$owner = wb_can('board.manage', $board);
$uid = wb_client_uid();

/* ---------- Zusammenfassung anfordern oder Zustand abfragen (JSON) ---------- */
if (isset($_GET['summary'])) {
    $mode = (string)$_GET['summary'];
    if (!in_array($mode, SUMMARY_MODES, true)) wb_error('mode', 400);
    $pub = function (array $st) use ($lang): array {
        return ['ok' => true, 'state' => $st['state'], 'error' => $st['error'] ?? null,
            'reason' => $st['reason'] ?? null, 'message' => !empty($st['reason']) ? t('export_reason_' . $st['reason'], $lang, ['n' => SUMMARY_MIN_POSTS]) : null];
    };
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') wb_json($pub(summary_status($board, $mode)));
    // Eigener Header erzwingt eine gleiche Herkunft (fremde Seiten können ihn ohne Freigabe nicht setzen).
    if (($_SERVER['HTTP_X_TAFULA'] ?? '') !== '1') wb_error('forbidden', 403);
    $force = false;
    if ($owner && !empty(wb_read_json_body()['force'])) { wb_check_csrf_header(); $force = true; }
    if (!$owner && guest_block_active($uid, $board['id']) !== null) {
        $st = summary_status($board, $mode);
        if ($st['state'] === 'generate') $st['state'] = 'blocked';
        wb_json($pub($st));
    }
    session_write_close(); // Die Sitzung nicht während des KI-Laufs sperren.
    wb_json($pub(summary_request($board, $mode, $owner ? 'teacher' : 'guest', $force)));
}

/* ---------- Optionen ---------- */
$isColumns = $board['type'] === 'columns';
$opt = fn(string $k, array $allowed, string $def): string => in_array($_GET[$k] ?? '', $allowed, true) ? (string)$_GET[$k] : $def;
$ai = $opt('ai', ['none', 'board', 'units'], 'none');
$list = $ai === 'none' || ($_GET['list'] ?? '1') !== '0';
$data = export_collect($board);
$group = $opt('group', ['columns', 'bundles', 'time'], $isColumns ? 'columns' : 'bundles');
if ($group === 'columns' && !$isColumns) $group = 'bundles';
$img = $opt('img', ['thumb', 'large', 'none'], 'thumb');
$withComments = ($_GET['comments'] ?? '1') !== '0';
$withReactions = ($_GET['reactions'] ?? '1') !== '0';
$newPage = ($_GET['newpage'] ?? '0') === '1';

$st = $ai !== 'none' ? summary_status($board, $ai, $data) : null;
$summary = $st['summary'] ?? null;
$sum = $summary['content'] ?? null;

$posts = export_order($board, $data['posts'], array_column($data['bundles'], null, 'id'), $group);
$numById = export_number($posts);
foreach ($posts as &$p) $p['n'] = $numById[$p['id']];
unset($p);
$bundleById = array_column($data['bundles'], null, 'id');
$colTitle = array_column($board['columns'], 'title', 'id');
$palette = ['#2f6f9f', '#c0612f', '#7a4b8f', '#2e7d5b', '#a8792a', '#a33f5f', '#3f6b8a', '#6b6f2e'];
$colColor = [];
foreach ($board['columns'] as $i => $c) $colColor[$c['id']] = $palette[$i % count($palette)];
$unitColor = function (string $unit) use ($colColor, $palette, $data): string {
    if (str_starts_with($unit, 'c:')) return $colColor[substr($unit, 2)] ?? $palette[0];
    if (str_starts_with($unit, 'b:')) { $i = array_search((int)substr($unit, 2), array_column($data['bundles'], 'id'), true); return $palette[((int)$i) % count($palette)]; }
    return '#6b7280';
};
$postColor = fn(array $p): string => $isColumns ? ($colColor[$p['column']] ?? $palette[0]) : '#3a4350';

$fmtDate = fn(?string $utc): string => $utc ? (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone((string)wb_config('timezone', 'Europe/Zurich')))->format('j.n.Y') : '';
$fmtTime = fn(int $ts): string => (new DateTime('@' . $ts))->setTimezone(new DateTimeZone((string)wb_config('timezone', 'Europe/Zurich')))->format('H:i');
$refsHtml = function (array $ids, ?string $color = null) use ($numById, $list): string {
    if (!$list) return '';
    $ns = [];
    foreach ($ids as $id) if (isset($numById[$id])) $ns[] = $numById[$id];
    if (!$ns) return '';
    sort($ns);
    return ' <span class="ref"' . ($color ? ' style="--c:' . e($color) . '"' : '') . '>' . e(implode(', ', $ns)) . '</span>';
};
$postsLabel = fn(int $n): string => $n === 1 ? t('export_posts_1', $lang) : t('export_posts_n', $lang, ['n' => $n]);
$kicker = $ai === 'none' ? t('export_kicker_list', $lang) : ($list ? t('export_kicker_report', $lang) : t('export_kicker_summary', $lang));
$unitsLabel = $isColumns ? 'columns' : 'bundles';
$cssString = fn(string $s): string => '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', ' '], $s) . '"';
$accessLabel = preg_replace('~^https?://(www\.)?~i', '', (string)$board['url']);
$needsJs = $st && in_array($st['state'], ['generate', 'pending'], true) && !$summary;

/** Ein Beitrag als Karte. */
$renderPost = function (array $p) use ($img, $withComments, $withReactions, $postColor, $lang): void {
    $c = $postColor($p);
    if ($p['owner']) {
        echo '<div class="post impulse" style="--c:' . e($c) . '"><div class="badge">' . e(t('export_impulse', $lang)) . ' · #' . (int)$p['n'] . '</div>';
    } else {
        echo '<div class="post" style="--c:' . e($c) . '"><div class="head"><span class="num">#' . (int)$p['n'] . '</span>';
        if ($withReactions && $p['reactions']) echo '<span class="rx">' . e(implode(' · ', array_map(fn($r) => $r[0] . ' ' . $r[1], $p['reactions']))) . '</span>';
        echo '</div>';
    }
    if ($p['title'] !== '') echo '<h4>' . e($p['title']) . '</h4>';
    if ($p['image'] && $img !== 'none') echo '<img class="img img-' . e($img) . '" src="' . e($img === 'large' ? $p['image'] : $p['thumb']) . '" alt="">';
    if (trim($p['body']) !== '') echo '<p>' . nl2br(e($p['body'])) . '</p>';
    if ($p['link']) echo '<div class="link">' . e(parse_url($p['link'], PHP_URL_HOST) ?: '') . ' · ' . e($p['link']) . '</div>';
    if ($withComments && $p['comments']) {
        echo '<div class="cm">';
        foreach ($p['comments'] as $cm) echo '<div>' . ($cm['owner'] ? '<b class="own">' . e(t('export_impulse', $lang)) . ':</b> ' : '') . nl2br(e($cm['body'])) . '</div>';
        echo '</div>';
    }
    echo '</div>';
};
/** Eine Gruppe von Beiträgen: Impulse über die volle Breite, die übrigen zweispaltig. */
$renderGroup = function (array $group, bool $pullImpulses = true) use ($renderPost): void {
    if ($pullImpulses) foreach ($group as $p) if ($p['owner'] && $p['bundle_id'] === null) $renderPost($p);
    $rest = $pullImpulses ? array_filter($group, fn($p) => !($p['owner'] && $p['bundle_id'] === null)) : $group;
    if (!$rest) return;
    echo '<div class="grid">';
    foreach ($rest as $p) $renderPost($p);
    echo '</div>';
};
/** Beiträge einer Spalte oder des ganzen Boards nach Bündeln gegliedert. */
$renderByBundle = function (array $group) use ($renderGroup, $bundleById, $postsLabel, $lang): void {
    $byBundle = [];
    foreach ($group as $p) $byBundle[$p['bundle_id'] ?? 0][] = $p;
    $loose = $byBundle[0] ?? [];
    unset($byBundle[0]);
    $impulses = array_filter($loose, fn($p) => $p['owner']);
    $loose = array_values(array_filter($loose, fn($p) => !$p['owner']));
    if ($impulses) $renderGroup($impulses);
    foreach ($byBundle as $bid => $ps) {
        $g = $bundleById[$bid];
        echo '<div class="bundle">' . e($g['title']) . ' <small>· ' . e($postsLabel(count($ps))) . '</small></div>';
        if ($g['summary'] !== '') echo '<p class="bundle-sum">' . e($g['summary']) . '</p>';
        $renderGroup($ps);
    }
    if ($loose) {
        if ($byBundle) echo '<div class="bundle">' . e(t('export_loose', $lang)) . ' <small>· ' . e($postsLabel(count($loose))) . '</small></div>';
        $renderGroup($loose);
    }
};
?>
<!doctype html>
<html lang="<?= e($board['lang']) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($board['name']) ?> – <?= e($kicker) ?></title>
<style>
@page {
  size: A4;
  margin: 20mm 18mm 18mm 18mm;
  @bottom-left { content: <?= $cssString($board['name'] . ' · ' . $kicker) ?>; font: 7.5pt Inter, "Helvetica Neue", Arial, sans-serif; color: #8a8f98; }
  @bottom-right { content: counter(page) " / " counter(pages); font: 7.5pt Inter, "Helvetica Neue", Arial, sans-serif; color: #8a8f98; }
}
@page :first { @bottom-left { content: ""; } }
:root {
  --ink: #1d2128; --muted: #6b7280; --line: #e3e5e9; --soft: #f6f7f9;
  --sans: Inter, -apple-system, "Helvetica Neue", "Segoe UI", Arial, sans-serif;
  --serif: Charter, "Iowan Old Style", "Bitstream Charter", Caladea, Georgia, Cambria, serif;
}
* { box-sizing: border-box; }
html { -webkit-print-color-adjust: exact; print-color-adjust: exact; background: #e9ebef; }
body { margin: 0; color: var(--ink); font: 10pt/1.5 var(--serif); }
.page { background: #fff; max-width: 210mm; margin: 16mm auto 12mm; padding: 20mm 18mm 18mm; box-shadow: 0 2px 18px rgba(0,0,0,.12); }
h1, h2, h3, h4 { font-family: var(--sans); }
.band { height: 7mm; border-radius: 2mm; margin-bottom: 6mm; }
.bg-morgen { background-image: linear-gradient(135deg, #fbe4c4 0%, #f6b39d 55%, #d8a8e6 100%); }
.bg-meer { background-image: linear-gradient(135deg, #10395a 0%, #2f8fbd 55%, #9ad9d2 100%); }
.bg-wald { background-image: linear-gradient(135deg, #1d4a38 0%, #5f9c74 55%, #d2e6a3 100%); }
.bg-daemmerung { background-image: linear-gradient(135deg, #262a5c 0%, #7a4b8f 55%, #ea9470 100%); }
.bg-graphit { background-image: linear-gradient(135deg, #1f2227 0%, #3a3f47 55%, #6a7382 100%); }
.bg-papier { background-image: linear-gradient(135deg, #e9e3d5 0%, #cfc5ae 100%); }
.kicker { font: 600 8pt var(--sans); letter-spacing: .08em; text-transform: uppercase; color: var(--muted); margin: 0 0 2mm; }
h1 { font-size: 22pt; line-height: 1.15; font-weight: 700; letter-spacing: -.01em; margin: 0 0 3mm; }
.note { font: 10.5pt/1.5 var(--sans); color: #3b414b; margin: 0 0 5mm; }
.stats { display: flex; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); margin: 3mm 0 6mm; }
.stats div { flex: 1; padding: 2.5mm 0; font: 8pt var(--sans); color: var(--muted); }
.stats b { display: block; font: 600 13pt var(--sans); color: var(--ink); }
h2 { font-size: 13pt; font-weight: 700; margin: 0 0 3mm; }
h2 .tag { font: 500 7.5pt var(--sans); color: var(--muted); border: 1px solid var(--line); border-radius: 99px; padding: .4mm 2mm; margin-left: 2mm; vertical-align: 2pt; }
.lead { font-size: 10.5pt; margin: 0 0 4.5mm; }
.sec { break-inside: avoid; margin: 0 0 4.5mm; padding-left: 4mm; border-left: 1.2mm solid var(--c, #3a4350); }
.sec h3 { font-size: 10.5pt; font-weight: 700; margin: 0 0 1.5mm; color: var(--c, var(--ink)); }
.sec h3 span { font-weight: 400; color: var(--muted); font-size: 8pt; margin-left: 1.5mm; }
.sec p { margin: 0 0 1.8mm; }
.ref { font: 600 7pt var(--sans); color: var(--c, var(--muted)); background: var(--soft); border-radius: 1mm; padding: .2mm 1mm; white-space: nowrap; }
.split { display: grid; grid-template-columns: 1fr 1fr; gap: 5mm; margin: 1mm 0 4.5mm; break-inside: avoid; }
.split.one { grid-template-columns: 1fr; }
.box { background: var(--soft); border-radius: 2mm; padding: 3.5mm 4mm; }
.box h3 { font-size: 9pt; font-weight: 700; margin: 0 0 1.5mm; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
.box ul { margin: 0; padding-left: 4mm; }
.box li { margin-bottom: 1mm; }
.quotes { break-inside: avoid; margin-bottom: 2mm; display: grid; grid-template-columns: 1fr 1fr; column-gap: 5mm; }
.quotes h2 { grid-column: 1 / -1; }
.quote { font-style: italic; font-size: 10.5pt; margin: 0 0 2mm; padding-left: 6mm; position: relative; }
.quote::before { content: "«"; position: absolute; left: 0; top: -1mm; font: 700 16pt var(--serif); color: #b8bcc4; }
.quote .ref { font-style: normal; }
.ai-note { font: 7.5pt/1.4 var(--sans); color: var(--muted); border-top: 1px solid var(--line); padding-top: 2mm; margin-top: 2mm; }
.stale { font: 8.5pt/1.45 var(--sans); color: #7a5a12; background: #fdf6e3; border-radius: 1.5mm; padding: 2mm 3mm; margin: 0 0 4mm; }
.wait { font: 10pt/1.5 var(--sans); background: var(--soft); border-radius: 2mm; padding: 5mm; margin: 0 0 6mm; display: flex; gap: 3mm; align-items: flex-start; }
.wait .actions { margin-top: 2mm; display: flex; gap: 3mm; flex-wrap: wrap; }
.spinner { flex: none; width: 4.5mm; height: 4.5mm; border: .6mm solid #c9cdd4; border-top-color: #3a4350; border-radius: 50%; animation: spin .9s linear infinite; margin-top: .6mm; }
@keyframes spin { to { transform: rotate(360deg); } }
.appendix.break { break-before: page; }
.appendix-head { margin-top: 2mm; }
.colsec.newpage + .colsec.newpage { break-before: page; }
.colhead { display: flex; align-items: baseline; gap: 3mm; margin: 6mm 0 3mm; padding-bottom: 1.5mm; border-bottom: 1.5pt solid var(--c); break-after: avoid; }
.colhead h2 { margin: 0; color: var(--c); }
.colhead span { font: 8pt var(--sans); color: var(--muted); }
.bundle { font: 600 9pt var(--sans); margin: 3mm 0 1.5mm; break-after: avoid; }
.bundle small { font-weight: 400; color: var(--muted); }
.bundle-sum { font-size: 9pt; color: #3b414b; margin: 0 0 2mm; break-after: avoid; }
.grid { columns: 2; column-gap: 5mm; }
.post { break-inside: avoid; border: 1px solid var(--line); border-radius: 2mm; padding: 2.8mm 3.2mm; margin: 0 0 4mm; }
.post .head { display: flex; justify-content: space-between; align-items: baseline; gap: 2mm; margin-bottom: .8mm; }
.post .num { font: 600 7.5pt var(--sans); color: var(--c); }
.post .rx { font: 7.5pt var(--sans); color: var(--muted); }
.post h4 { font: 600 9.5pt/1.3 var(--sans); margin: 0 0 .8mm; }
.post p { margin: 0; overflow-wrap: anywhere; }
.post .link { font: 7.5pt var(--sans); color: var(--muted); margin-top: 1.2mm; word-break: break-all; }
.post .cm { margin-top: 2mm; padding: 1.5mm 0 0 2.5mm; border-top: 1px dashed var(--line); font-size: 9pt; color: #3b414b; }
.post .cm div { margin-bottom: .8mm; overflow-wrap: anywhere; }
.post .cm div::before { content: "↳ "; color: #b8bcc4; font-family: var(--sans); }
.post .cm .own { font: 600 7.5pt var(--sans); color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
.post.impulse { background: var(--soft); border-color: transparent; }
.post .badge { font: 600 6.5pt var(--sans); letter-spacing: .06em; text-transform: uppercase; color: var(--muted); margin-bottom: .6mm; }
.post .img { display: block; max-width: 100%; border-radius: 1.5mm; margin: 1.2mm 0 1.5mm; }
.post .img-thumb { max-height: 40mm; }
.post .img-large { max-height: 120mm; }
.empty { font: 10pt var(--sans); color: var(--muted); }
.colophon { margin-top: 8mm; font: 7.5pt/1.5 var(--sans); color: var(--muted); border-top: 1px solid var(--line); padding-top: 2mm; }
.toolbar { position: fixed; top: 0; left: 0; right: 0; z-index: 5; display: flex; gap: 2mm; justify-content: center; align-items: center; padding: 2.5mm; background: rgba(255,255,255,.92); backdrop-filter: blur(6px); border-bottom: 1px solid var(--line); font: 10pt var(--sans); }
.btn { font: 600 10pt var(--sans); border: 1px solid #c9cdd4; background: #fff; color: var(--ink); border-radius: 99px; padding: 1.6mm 4mm; cursor: pointer; text-decoration: none; display: inline-block; }
.btn.primary { background: #1b1f24; color: #fff; border-color: #1b1f24; }
.btn[disabled] { opacity: .45; cursor: default; }
.linkbtn { font: inherit; background: none; border: 0; padding: 0; color: #2f6f9f; text-decoration: underline; cursor: pointer; }
@media screen and (max-width: 230mm) { .page { margin: 14mm 0 0; padding: 10mm 6mm; box-shadow: none; } .grid { columns: 1; } .split, .quotes { grid-template-columns: 1fr; } }
@media print {
  html { background: none; }
  .page { margin: 0; padding: 0; max-width: none; box-shadow: none; }
  .toolbar, .no-print { display: none !important; }
}
</style>
</head>
<body>
<div class="toolbar no-print">
  <a class="btn" href="<?= e($board['url']) ?>"><?= e(t('export_back', $lang)) ?></a>
  <button class="btn primary" id="print" type="button" <?= $needsJs ? 'disabled' : '' ?>><?= e(t('export_print', $lang)) ?></button>
</div>
<main class="page">
  <div class="band bg-<?= e($board['background']) ?>"></div>
  <p class="kicker"><?= e($kicker) ?> · <?= e(wb_app_name()) ?></p>
  <h1><?= e($board['name']) ?></h1>
  <?php if ($owner && trim((string)$board['note']) !== ''): ?><p class="note"><?= nl2br(e($board['note'])) ?></p><?php endif; ?>
  <div class="stats">
    <div><b><?= (int)$data['stats']['posts'] ?></b><?= e(t('export_stat_posts', $lang)) ?></div>
    <?php if ($isColumns): ?><div><b><?= count($board['columns']) ?></b><?= e(t('export_stat_columns', $lang)) ?></div><?php endif; ?>
    <?php if ($data['stats']['bundles']): ?><div><b><?= (int)$data['stats']['bundles'] ?></b><?= e(t('export_stat_bundles', $lang)) ?></div><?php endif; ?>
    <?php if ($withComments): ?><div><b><?= (int)$data['stats']['comments'] ?></b><?= e(t('export_stat_comments', $lang)) ?></div><?php endif; ?>
    <?php if ($data['stats']['first']): ?><div><b><?= e(export_period($data['stats']['first'], $data['stats']['last'])) ?></b><?= e(t('export_stat_period', $lang)) ?></div><?php endif; ?>
  </div>

<?php if ($ai !== 'none'): ?>
  <section class="summary" id="summary">
  <?php if ($sum): ?>
    <h2><?= e(t($ai === 'units' ? 'export_summary_units_' . $unitsLabel : 'export_summary', $lang)) ?> <span class="tag">KI · <?= e($summary['model']) ?></span></h2>
    <?php if ($st['stale']): ?>
      <p class="stale"><?= e(t('export_stale', $lang, ['date' => $fmtDate($summary['finished_at']), 'n' => $summary['post_count']])) ?><?php if (!empty($st['next_at']) && !$owner): ?> <?= e(t('export_stale_next', $lang, ['time' => $fmtTime((int)$st['next_at'])])) ?><?php endif; ?><?php if ($owner && empty($st['locked'])): ?> <button class="linkbtn no-print" type="button" data-regenerate><?= e(t('export_regenerate', $lang)) ?></button><?php endif; ?></p>
    <?php endif; ?>
    <?php if ($sum['intro'] !== ''): ?><p class="lead"><?= e($sum['intro']) ?></p><?php endif; ?>
    <?php foreach ($sum['sections'] as $sec):
        $color = isset($sec['unit']) ? $unitColor($sec['unit']) : '#3a4350';
        $count = 0;
        if (isset($sec['unit'])) foreach ($data['posts'] as $p) if (summary_unit_of($board, $p) === $sec['unit']) $count++;
    ?>
    <div class="sec" style="--c:<?= e($color) ?>">
      <h3><?= e($sec['title']) ?><?php if (isset($sec['unit'])): ?><span><?= e($postsLabel($count)) ?></span><?php endif; ?></h3>
      <?php foreach ($sec['paragraphs'] as $para): ?><p><?= e($para['text']) ?><?= $refsHtml($para['refs'], isset($sec['unit']) ? $color : null) ?></p><?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <?php if ($sum['disagreements'] || $sum['open_questions']): ?>
    <div class="split<?= $sum['disagreements'] && $sum['open_questions'] ? '' : ' one' ?>">
      <?php foreach (['disagreements' => 'export_disagreements', 'open_questions' => 'export_questions'] as $k => $label): if (!$sum[$k]) continue; ?>
      <div class="box"><h3><?= e(t($label, $lang)) ?></h3><ul>
        <?php foreach ($sum[$k] as $it): ?><li><?= e($it['text']) ?><?= $refsHtml($it['refs']) ?></li><?php endforeach; ?>
      </ul></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($sum['quotes']): ?>
    <div class="quotes">
      <h2><?= e(t('export_quotes', $lang)) ?></h2>
      <?php foreach ($sum['quotes'] as $q): ?><p class="quote"><?= e($q['text']) ?><?= $refsHtml([$q['ref']]) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <p class="ai-note"><?= e(t($list ? 'export_ai_note' : 'export_ai_note_nolist', $lang, ['date' => $fmtDate($summary['finished_at']), 'model' => $summary['model']])) ?><?php if ($owner && !$st['stale'] && empty($st['locked'])): ?> <button class="linkbtn no-print" type="button" data-regenerate><?= e(t('export_regenerate', $lang)) ?></button><?php endif; ?></p>
  <?php elseif ($st['state'] === 'unavailable'): ?>
    <div class="wait no-print"><div><?= e(t('export_reason_' . $st['reason'], $lang, ['n' => SUMMARY_MIN_POSTS])) ?></div></div>
  <?php else: ?>
    <div class="wait no-print" id="wait" data-mode="<?= e($ai) ?>" data-state="<?= e($st['state']) ?>">
      <span class="spinner" id="wait-spin" <?= $st['state'] === 'failed' ? 'hidden' : '' ?>></span>
      <div>
        <div id="wait-text"><?= e($st['state'] === 'failed' ? t('export_failed', $lang) . ($st['error'] ? ' ' . $st['error'] : '') : t($st['state'] === 'pending' ? 'export_waiting' : 'export_generating', $lang)) ?></div>
        <div class="actions" id="wait-actions" <?= $st['state'] === 'failed' ? '' : 'hidden' ?>>
          <button class="btn" type="button" id="retry"><?= e(t('export_retry', $lang)) ?></button>
          <a class="btn" href="<?= e('?' . http_build_query(array_merge($_GET, ['ai' => 'none', 'key' => null]))) ?>"><?= e(t('export_without', $lang)) ?></a>
        </div>
      </div>
    </div>
  <?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($list): ?>
  <section class="appendix<?= $ai !== 'none' && $sum ? ' break' : '' ?>">
    <?php if ($ai !== 'none'): ?>
      <p class="kicker appendix-head"><?= e(t('export_appendix', $lang)) ?></p>
      <h1 style="font-size:17pt;margin-bottom:2mm"><?= e(t('export_all_posts', $lang)) ?></h1>
    <?php endif; ?>
    <p class="note" style="margin-bottom:0"><?= e(t('export_order_' . $group, $lang)) ?></p>
    <?php if (!$posts): ?>
      <p class="empty"><?= e(t('export_empty', $lang)) ?></p>
    <?php elseif ($group === 'columns'): ?>
      <?php foreach ($board['columns'] as $col):
          $ps = array_values(array_filter($posts, fn($p) => $p['column'] === $col['id']));
          if (!$ps) continue; ?>
      <div class="colsec<?= $newPage ? ' newpage' : '' ?>" style="--c:<?= e($colColor[$col['id']]) ?>">
        <div class="colhead"><h2><?= e($col['title']) ?></h2><span><?= e($postsLabel(count($ps))) ?></span></div>
        <?php $renderByBundle($ps); ?>
      </div>
      <?php endforeach; ?>
    <?php elseif ($group === 'bundles'): ?>
      <div style="--c:#3a4350"><?php $renderByBundle($posts); ?></div>
    <?php else: ?>
      <?php $renderGroup($posts, false); ?>
    <?php endif; ?>
    <p class="colophon"><?= e(t('export_colophon', $lang, ['date' => date('j.n.Y'), 'app' => wb_app_name(), 'url' => $accessLabel])) ?></p>
  </section>
<?php endif; ?>
</main>

<script>
(function () {
  var printBtn = document.getElementById('print');
  printBtn.addEventListener('click', function () { window.print(); });
  var base = location.pathname;
  var csrf = <?= json_encode($owner ? wb_csrf_token() : null) ?>;
  var mode = <?= json_encode($ai) ?>;
  var busy = false, timer = null;

  function post(force) {
    // Ein Abruf zur Zeit: weitere Klicks warten auf das Ergebnis des laufenden.
    if (busy) return;
    busy = true;
    var headers = { 'Content-Type': 'application/json', 'X-Tafula': '1' };
    if (force && csrf) headers['X-CSRF'] = csrf;
    fetch(base + '?summary=' + encodeURIComponent(mode), { method: 'POST', headers: headers, credentials: 'same-origin', body: JSON.stringify({ force: !!force }) })
      .then(function (r) { return r.json(); })
      .then(handle)
      .catch(function () { busy = false; show('failed', <?= json_encode(t('error_generic', $lang)) ?>); });
  }
  function poll() {
    clearTimeout(timer);
    timer = setTimeout(function () {
      fetch(base + '?summary=' + encodeURIComponent(mode) + '&status=1', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(handle)
        .catch(poll);
    }, 2000);
  }
  function handle(res) {
    busy = false;
    if (!res || !res.ok) { show('failed', <?= json_encode(t('error_generic', $lang)) ?>); return; }
    if (res.state === 'ready' && res.error) {
      // Neuer Lauf fehlgeschlagen, die gespeicherte Fassung bleibt: Fehler zeigen, auf Wunsch neu laden.
      show('failed', res.error);
      var acts = document.getElementById('wait-actions');
      if (acts && !document.getElementById('show-stored')) {
        var a = document.createElement('button');
        a.className = 'btn'; a.type = 'button'; a.id = 'show-stored';
        a.textContent = <?= json_encode(t('export_show_stored', $lang)) ?>;
        a.addEventListener('click', function () { location.reload(); });
        acts.appendChild(a);
      }
      return;
    }
    if (res.state === 'ready') { location.reload(); return; }
    if (res.state === 'pending') { show('pending'); poll(); return; }
    if (res.state === 'generate') { show('generate'); post(false); return; }
    if (res.state === 'blocked') { show('failed', <?= json_encode(t('export_blocked', $lang)) ?>); return; }
    if (res.state === 'unavailable') { show('failed', res.message || ''); return; }
    show('failed', res.error || '');
  }
  var texts = { generate: <?= json_encode(t('export_generating', $lang)) ?>, pending: <?= json_encode(t('export_waiting', $lang)) ?>, failed: <?= json_encode(t('export_failed', $lang)) ?> };
  function show(state, extra) {
    var w = document.getElementById('wait');
    if (!w) { if (state !== 'failed') return; alert(texts.failed + ' ' + (extra || '')); return; }
    document.getElementById('wait-text').textContent = texts[state] + (extra ? ' ' + extra : '');
    document.getElementById('wait-spin').hidden = state === 'failed';
    document.getElementById('wait-actions').hidden = state !== 'failed';
  }

  var w = document.getElementById('wait');
  if (w) {
    document.getElementById('retry').addEventListener('click', function () { show('generate'); post(!!csrf); });
    if (w.dataset.state === 'generate') post(false);
    else if (w.dataset.state === 'pending') poll();
  }
  document.querySelectorAll('[data-regenerate]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (busy) return;
      b.disabled = true;
      printBtn.disabled = true;
      var sec = document.getElementById('summary');
      sec.innerHTML = '<div class="wait no-print" id="wait"><span class="spinner" id="wait-spin"></span><div><div id="wait-text"></div><div class="actions" id="wait-actions" hidden><button class="btn" type="button" id="retry"><?= e(t('export_retry', $lang)) ?></button></div></div></div>';
      document.getElementById('retry').addEventListener('click', function () { show('generate'); post(true); });
      show('generate');
      post(true);
    });
  });
})();
</script>
</body>
</html>
