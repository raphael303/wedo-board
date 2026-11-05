<?php
declare(strict_types=1);
require __DIR__ . '/_layout.php';
admin_require_login();
$board = board_by_key((string)($_GET['key'] ?? ''));
if (!$board) { http_response_code(404); exit('Board nicht gefunden.'); }
$data = board_export_data($board);
$groups = [];
foreach ($data['posts'] as $p) $groups[$p['column'] ?? ''][] = $p;
if ($board['type'] === 'columns') {
    $order = array_map(fn($c) => $c['title'], $board['columns']);
    uksort($groups, fn($a, $z) => (int)array_search($a, $order, true) <=> (int)array_search($z, $order, true));
}
?>
<!doctype html>
<html lang="<?= e($board['lang']) ?>">
<head>
<meta charset="utf-8">
<title><?= e($board['name']) ?></title>
<style>
body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1b1f24;max-width:52rem;margin:2rem auto;padding:0 1.5rem;line-height:1.45}
h1{font-size:1.7rem;margin:0 0 .3rem}
h2{font-size:1.2rem;margin:2rem 0 .8rem;padding-bottom:.3rem;border-bottom:1px solid #ddd}
.meta{color:#5b6472;font-size:.9rem;margin-bottom:1.5rem}
article{break-inside:avoid;padding:.8rem 0;border-top:1px solid #eee}
article h3{margin:0 0 .2rem;font-size:1.05rem}
.by{color:#5b6472;font-size:.85rem}
p{margin:.3rem 0;white-space:pre-wrap}
img{max-width:60%;max-height:16rem;border-radius:6px;margin:.4rem 0}
ul{margin:.4rem 0 0;padding-left:1.2rem;font-size:.92rem}
.print{position:fixed;top:1rem;right:1rem}
@media print{.print{display:none}body{margin:0;max-width:none}}
</style>
</head>
<body>
<button class="print" onclick="window.print()">Drucken / als PDF sichern</button>
<h1><?= e($board['name']) ?></h1>
<div class="meta"><?= e($board['url']) ?> · <?= count($data['posts']) ?> Beiträge · exportiert am <?= date('d.m.Y H:i') ?><?= $board['note'] ? '<br>' . nl2br(e($board['note'])) : '' ?></div>
<?php foreach ($groups as $col => $posts): ?>
  <?php if ($col !== ''): ?><h2><?= e($col) ?></h2><?php endif; ?>
  <?php foreach ($posts as $p): ?>
  <article>
    <?php if ($p['title']): ?><h3><?= e($p['title']) ?></h3><?php endif; ?>
    <div class="by"><?= e($p['author']) ?> · <?= e($p['created_at']) ?><?= $p['reactions'] ? ' · ' . e(implode(' ', $p['reactions'])) : '' ?></div>
    <?php if ($p['body']): ?><p><?= e($p['body']) ?></p><?php endif; ?>
    <?php if ($p['image']): ?><img src="<?= e($p['image']) ?>" alt=""><?php endif; ?>
    <?php if ($p['link']): ?><p><a href="<?= e($p['link']) ?>"><?= e($p['link']) ?></a></p><?php endif; ?>
    <?php if ($p['comments']): ?><ul><?php foreach ($p['comments'] as $c): ?><li><strong><?= e($c['author']) ?>:</strong> <?= e($c['body']) ?></li><?php endforeach; ?></ul><?php endif; ?>
  </article>
  <?php endforeach; ?>
<?php endforeach; ?>
</body>
</html>
