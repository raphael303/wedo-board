<?php
declare(strict_types=1);
// Bibliothek der Gemeinschaftsprüfungen (Aktivitäten vom Typ exam).
require __DIR__ . '/_layout.php';
admin_require_login();

$q = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? 'active');
$sort = (string)($_GET['sort'] ?? 'activity');
$tag = trim((string)($_GET['tag'] ?? ''));
$list = activities_list(['q' => $q, 'status' => $status, 'sort' => $sort, 'tag' => $tag, 'type' => 'exam']);

admin_header('Prüfungen', 'exams');
?>
<div class="page-head">
  <div>
    <h1>Prüfungen</h1>
    <p class="muted">Gemeinschaftsprüfungen: Die Klasse steuert unter einem Link Fragen bei, du sichtest und nimmst sie an und startest später die Prüfung unter demselben Link.</p>
  </div>
  <a class="btn" href="<?= e(wb_admin_url()) ?>exam.php?new=1">Neue Prüfung</a>
</div>

<form class="filters" method="get">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Suche in Titel, Notiz und Tags" aria-label="Suche">
  <select name="status" aria-label="Status">
    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Aktiv (offen und geschlossen)</option>
    <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Nur offen</option>
    <option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>>Nur geschlossen</option>
    <option value="archived" <?= $status === 'archived' ? 'selected' : '' ?>>Archiv</option>
    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Alle</option>
    <option value="trash" <?= $status === 'trash' ? 'selected' : '' ?>>Papierkorb</option>
  </select>
  <select name="sort" aria-label="Sortierung">
    <option value="activity" <?= $sort === 'activity' ? 'selected' : '' ?>>Letzte Aktivität</option>
    <option value="created" <?= $sort === 'created' ? 'selected' : '' ?>>Erstellt</option>
    <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name</option>
  </select>
  <?php if ($tag): ?><input type="hidden" name="tag" value="<?= e($tag) ?>"><?php endif; ?>
  <button class="btn-ghost" type="submit">Filtern</button>
</form>

<?php if (!$list): ?>
  <section class="card empty">
    <p>Keine Prüfungen gefunden.</p>
    <a class="btn" href="<?= e(wb_admin_url()) ?>exam.php?new=1">Erste Prüfung anlegen</a>
  </section>
<?php else: ?>
<ul class="boardlist">
  <?php foreach ($list as $a): ?>
  <li class="boardrow bg-<?= e($a['background']) ?>">
    <div class="boardrow-main">
      <a class="boardrow-name" href="<?= e(wb_admin_url()) ?>exam.php?key=<?= e($a['key']) ?>"><?= e($a['name']) ?></a>
      <div class="boardrow-meta">
        <?php if ($a['deleted_at']): ?><span class="pill status-trash">Papierkorb</span><?php else: ?><span class="pill status-<?= e($a['status']) ?>"><?= e(admin_status_label($a['status'])) ?></span><?php endif; ?>
        <span><?= e(['collect' => 'Fragen sammeln', 'running' => 'läuft', 'results' => 'Ergebnisse freigegeben'][$a['phase']] ?? $a['phase']) ?></span>
        <span><?= $a['questions']['accepted'] ?> angenommen, <?= $a['questions']['submitted'] ?> offen</span>
        <span><?= $a['response_count'] ?> Personen</span>
        <span title="Letzte Aktivität"><?= e(admin_time($a['last_activity_at'])) ?></span>
      </div>
      <?php if ($a['note']): ?><p class="boardrow-note muted"><?= e(mb_strimwidth($a['note'], 0, 180, '…')) ?></p><?php endif; ?>
      <?php if ($a['tags']): ?><div class="tags"><?php foreach ($a['tags'] as $t): ?><a class="tag" href="?tag=<?= urlencode($t) ?>"><?= e($t) ?></a><?php endforeach; ?></div><?php endif; ?>
    </div>
    <div class="boardrow-actions">
      <?php if ($a['deleted_at']): ?>
      <form method="post" action="<?= e(wb_admin_url()) ?>exam.php?key=<?= e($a['key']) ?>"><input type="hidden" name="csrf" value="<?= e(wb_csrf_token()) ?>"><input type="hidden" name="action" value="restore"><button class="btn-ghost" type="submit">Wiederherstellen</button></form>
      <form method="post" action="<?= e(wb_admin_url()) ?>exam.php?key=<?= e($a['key']) ?>" data-confirm="Prüfung jetzt endgültig löschen?"><input type="hidden" name="csrf" value="<?= e(wb_csrf_token()) ?>"><input type="hidden" name="action" value="delete_forever"><button class="btn-ghost danger" type="submit">Endgültig löschen</button></form>
      <?php else: ?>
      <a class="btn-ghost" href="<?= e($a['url']) ?>" target="_blank" rel="noopener">Öffnen</a>
      <button class="btn-ghost" type="button" data-copy="<?= e($a['url']) ?>">Link kopieren</button>
      <a class="btn-ghost" href="<?= e(wb_admin_url()) ?>exam.php?key=<?= e($a['key']) ?>#qr">QR</a>
      <?php endif; ?>
    </div>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
<?php admin_footer();
