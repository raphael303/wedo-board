<?php
declare(strict_types=1);
// /help und /help/<thema>: Hilfe aus Markdown-Dateien unter lang/help/<sprache>/ (Gerüst seit 0.22.0; die Texte
// vervollständigt U7). Fehlt eine Seite in der Sprache des Kontos, erscheint die deutsche.
if (!defined('WB_ROOT')) exit;
require_once WB_ROOT . '/lib/help.php';
$lang = $GLOBALS['wb_ui_lang'] ?? 'de';
$topic = $sub === '' ? 'index' : $sub;
$q = trim((string)($_GET['q'] ?? ''));
$file = help_file($topic, $lang);
if ($file === null && $q === '') page_not_found();
ui_header(tx('Hilfe'), 'help');
?>
<div class="page-head"><div><h1><?= e(tx('Hilfe')) ?></h1></div>
  <form method="get" action="/help" class="filters"><input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(tx('In der Hilfe suchen')) ?>"><button class="btn-ghost" type="submit"><?= e(tx('Suchen')) ?></button></form></div>
<?php if ($q !== ''): $hits = help_search($q, $lang); ?>
<section class="card"><h2><?= e(tx('Treffer für «{q}»', ['q' => $q])) ?></h2>
  <?php if (!$hits): ?><p class="muted"><?= e(tx('Nichts gefunden.')) ?></p><?php else: ?><ul><?php foreach ($hits as $h): ?><li><a href="/help/<?= e($h['topic'] === 'index' ? '' : $h['topic']) ?>"><?= e($h['title']) ?></a> <span class="muted"><?= e($h['excerpt']) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
  <p><a href="/help">← <?= e(tx('Übersicht')) ?></a></p></section>
<?php else: ?>
<section class="card help"><?= help_render((string)file_get_contents($file)) ?><?php if ($topic !== 'index'): ?><p><a href="/help">← <?= e(tx('Übersicht')) ?></a></p><?php endif; ?></section>
<?php endif;
ui_footer();
