<?php
declare(strict_types=1);
require __DIR__ . '/_layout.php';
admin_require_login();

$q = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? 'active');
$sort = (string)($_GET['sort'] ?? 'activity');
$tag = trim((string)($_GET['tag'] ?? ''));
$boards = boards_list(['q' => $q, 'status' => $status, 'sort' => $sort, 'tag' => $tag]);

$allTags = [];
foreach (db_all("SELECT tags FROM boards WHERE status <> 'archived' AND tags <> ''") as $r) {
    foreach (wb_parse_tags($r['tags']) as $t) $allTags[$t] = ($allTags[$t] ?? 0) + 1;
}
arsort($allTags);

admin_header('Bibliothek', 'library');
?>
<div class="page-head">
  <h1>Bibliothek</h1>
  <a class="btn" href="/admin/board.php?new=1">Neues Board</a>
</div>

<form class="filters" method="get">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Suche in Name, Notiz und Tags" aria-label="Suche">
  <select name="status" aria-label="Status">
    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Aktiv (offen und geschlossen)</option>
    <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Nur offen</option>
    <option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>>Nur geschlossen</option>
    <option value="archived" <?= $status === 'archived' ? 'selected' : '' ?>>Archiv</option>
    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Alle</option>
  </select>
  <select name="sort" aria-label="Sortierung">
    <option value="activity" <?= $sort === 'activity' ? 'selected' : '' ?>>Letzte Aktivität</option>
    <option value="created" <?= $sort === 'created' ? 'selected' : '' ?>>Erstellt</option>
    <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name</option>
  </select>
  <?php if ($tag): ?><input type="hidden" name="tag" value="<?= e($tag) ?>"><?php endif; ?>
  <button class="btn-ghost" type="submit">Filtern</button>
</form>

<?php if ($allTags): ?>
<div class="tagcloud">
  <?php foreach (array_slice($allTags, 0, 30, true) as $t => $n): ?>
    <a class="tag <?= $t === $tag ? 'active' : '' ?>" href="?<?= http_build_query(['q' => $q, 'status' => $status, 'sort' => $sort, 'tag' => $t === $tag ? '' : $t]) ?>"><?= e($t) ?> <small><?= $n ?></small></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$boards): ?>
  <section class="card empty">
    <p>Keine Boards gefunden.</p>
    <a class="btn" href="/admin/board.php?new=1">Erstes Board anlegen</a>
  </section>
<?php else: ?>
<ul class="boardlist">
  <?php foreach ($boards as $b): ?>
  <li class="boardrow bg-<?= e($b['background']) ?>">
    <div class="boardrow-main">
      <a class="boardrow-name" href="/admin/board.php?key=<?= e($b['key']) ?>"><?= e($b['name']) ?></a>
      <div class="boardrow-meta">
        <span class="pill status-<?= e($b['status']) ?>"><?= e(admin_status_label($b['status'])) ?></span>
        <span><?= $b['type'] === 'columns' ? count($b['columns']) . ' Spalten' : 'Frei' ?></span>
        <span><?= $b['post_count'] ?> Beiträge<?= $b['comment_count'] ? ', ' . $b['comment_count'] . ' Kommentare' : '' ?></span>
        <span title="Letzte Aktivität"><?= e(admin_time($b['last_activity_at'])) ?></span>
      </div>
      <?php if ($b['note']): ?><p class="boardrow-note"><?= e(mb_strimwidth($b['note'], 0, 180, '…')) ?></p><?php endif; ?>
      <?php if ($b['tags']): ?><div class="tags"><?php foreach ($b['tags'] as $t): ?><a class="tag" href="?tag=<?= urlencode($t) ?>"><?= e($t) ?></a><?php endforeach; ?></div><?php endif; ?>
    </div>
    <div class="boardrow-actions">
      <a class="btn-ghost" href="<?= e($b['url']) ?>" target="_blank" rel="noopener">Öffnen</a>
      <button class="btn-ghost" type="button" data-copy="<?= e($b['url']) ?>">Link kopieren</button>
      <a class="btn-ghost" href="/admin/board.php?key=<?= e($b['key']) ?>#qr">QR</a>
    </div>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
<?php admin_footer();
