<?php
declare(strict_types=1);
require __DIR__ . '/_layout.php';
admin_require_login();
admin_check_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'lift') {
    try {
        $r = guest_block_lift((int)($_POST['id'] ?? 0));
        admin_flash('Gast «' . $r['display_name'] . '» entsperrt.' . (!empty($r['note']) ? ' ' . $r['note'] : ($r['hide_content'] ? ' Ausgeblendete Beiträge und Kommentare sind wieder sichtbar.' : '')));
    } catch (InvalidArgumentException $ex) {
        admin_flash($ex->getMessage());
    }
    header('Location: ' . wb_admin_url() . 'blocks.php');
    exit;
}
$showAll = isset($_GET['all']);
$blocks = guest_blocks_list(!$showAll);
$csrf = wb_csrf_token();
admin_header('Sperren', 'blocks');
?>
<div class="page-head">
  <h1>Gesperrte Gäste</h1>
  <a class="btn-ghost" href="<?= e(wb_admin_url()) ?>blocks.php<?= $showAll ? '' : '?all=1' ?>"><?= $showAll ? 'Nur aktive Sperren' : 'Auch aufgehobene anzeigen' ?></a>
</div>
<section class="card">
  <p class="muted">Gäste werden auf der Board-Seite gesperrt: als angemeldeter Besitzer auf den Fantasienamen eines Beitrags oder Kommentars klicken. Eine Sperre gilt für den Browser des Gastes (Cookie und localStorage); ein anderer Browser oder ein privates Fenster umgeht sie. Gesperrte Gäste lesen weiter, schreiben aber nicht mehr. Entsperren bringt ausgeblendete Inhalte zurück.</p>
  <?php if (!$blocks): ?>
    <p class="muted">Zurzeit ist niemand gesperrt.</p>
  <?php else: ?>
  <ul class="postlist">
    <?php foreach ($blocks as $b): ?>
    <li class="postitem">
      <div class="postitem-head">
        <strong><?= e($b['display_name']) ?></strong>
        <span class="muted">
          <?= $b['board_id'] === null ? 'alle Boards' : ($b['board_name'] !== null ? 'Board «' . e($b['board_name']) . '»' : 'Board gelöscht') ?>
          · seit <?= e(admin_time($b['created_at'])) ?>
          <?= $b['hide_content'] ? ' · Inhalte ausgeblendet' : '' ?>
          <?= $b['active'] ? '' : ' · aufgehoben am ' . e(admin_time($b['lifted_at'])) ?>
          · Schlüssel <code><?= e(substr($b['guest_key'], 0, 8)) ?>…</code>
        </span>
        <?php if ($b['active']): ?>
        <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="lift"><input type="hidden" name="id" value="<?= $b['id'] ?>"><button class="btn-ghost small" type="submit">Entsperren</button></form>
        <?php endif; ?>
      </div>
      <?php if ($b['board_key']): ?><p class="postitem-body muted"><a href="<?= e(wb_admin_url()) ?>board.php?key=<?= e($b['board_key']) ?>">Zum Board</a></p><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php admin_footer();
