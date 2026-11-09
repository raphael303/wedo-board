<?php
declare(strict_types=1);
// Rückmeldungen: Liste mit Filtern und Detailseite (Kontext, Analyse, Vorschlag, Freigaben).
require __DIR__ . '/_layout.php';
admin_require_login();
admin_check_csrf();

$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $pid = (int)($_POST['id'] ?? 0);
    $back = wb_admin_url() . 'feedback.php' . ($id > 0 ? '?id=' . $id : '');
    try {
        $fb = feedback_get($pid);
        if (!$fb) throw new InvalidArgumentException('Rückmeldung nicht gefunden.');
        switch ($action) {
            case 'approve':
                feedback_update($pid, ['status' => 'approved'], 'library');
                admin_flash('Rückmeldung ' . $pid . ' freigegeben. Der nächste Lauf setzt sie um.');
                break;
            case 'approve_deploy':
                if (!$fb['branch']) throw new InvalidArgumentException('Es gibt noch keinen Zweig, der ausgerollt werden könnte.');
                feedback_update($pid, ['status' => 'approved'], 'library');
                admin_flash('Ausrollen von Rückmeldung ' . $pid . ' (Zweig ' . $fb['branch'] . ') freigegeben.');
                break;
            case 'reject':
                feedback_update($pid, ['status' => 'rejected']);
                admin_flash('Rückmeldung ' . $pid . ' abgelehnt.');
                break;
            case 'done':
                feedback_update($pid, ['status' => 'done']);
                admin_flash('Rückmeldung ' . $pid . ' als erledigt markiert.');
                break;
            case 'reopen':
                feedback_update($pid, ['status' => 'new']);
                admin_flash('Rückmeldung ' . $pid . ' wieder geöffnet.');
                break;
            case 'trash':
                feedback_trash($pid);
                admin_flash('Rückmeldung ' . $pid . ' in den Papierkorb gelegt.');
                $back = wb_admin_url() . 'feedback.php';
                break;
            case 'restore':
                feedback_restore($pid);
                admin_flash('Rückmeldung ' . $pid . ' wiederhergestellt.');
                break;
            case 'delete_forever':
                feedback_delete_forever($pid);
                admin_flash('Rückmeldung ' . $pid . ' endgültig gelöscht.');
                $back = wb_admin_url() . 'feedback.php?status=trash';
                break;
            default:
                throw new InvalidArgumentException('Unbekannte Aktion.');
        }
    } catch (InvalidArgumentException $ex) {
        admin_flash($ex->getMessage());
    }
    header('Location: ' . $back);
    exit;
}

$csrf = wb_csrf_token();

/** Knopf als kleines POST-Formular. */
function fb_button(int $id, string $action, string $label, string $class = 'btn-ghost', string $confirm = ''): string {
    return '<form method="post" class="inline"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
        . '<input type="hidden" name="csrf" value="' . e(wb_csrf_token()) . '">'
        . '<input type="hidden" name="action" value="' . e($action) . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button class="' . e($class) . '" type="submit">' . e($label) . '</button></form>';
}

function fb_status_pill(array $fb): string {
    if ($fb['deleted_at']) return '<span class="pill status-trash">Papierkorb</span>';
    $deploy = $fb['status'] === 'approved' && $fb['branch'];
    return '<span class="pill fb-' . e($fb['status']) . '">' . e($deploy ? 'Ausrollen freigegeben' : feedback_status_label($fb['status'])) . '</span>';
}

function fb_actions(array $fb): string {
    $id = $fb['id'];
    if ($fb['deleted_at']) {
        return fb_button($id, 'restore', 'Wiederherstellen') . fb_button($id, 'delete_forever', 'Endgültig löschen', 'btn-ghost danger', 'Rückmeldung jetzt endgültig löschen?');
    }
    $out = '';
    $s = $fb['status'];
    if (in_array($s, ['new', 'analysed', 'proposed'], true)) $out .= fb_button($id, 'approve', 'Freigeben', 'btn');
    if ($s === 'ready' && $fb['branch']) $out .= fb_button($id, 'approve_deploy', 'Ausrollen freigeben', 'btn');
    if (!in_array($s, ['done', 'rejected', 'duplicate'], true)) {
        $out .= fb_button($id, 'reject', 'Ablehnen');
        $out .= fb_button($id, 'done', 'Erledigt');
    } else {
        $out .= fb_button($id, 'reopen', 'Wieder öffnen');
    }
    $out .= fb_button($id, 'trash', 'Löschen', 'btn-ghost danger');
    return $out;
}

/* ---------------- Detailseite ---------------- */
if ($id > 0) {
    $fb = feedback_get($id);
    if (!$fb) {
        admin_flash('Rückmeldung nicht gefunden.');
        header('Location: ' . wb_admin_url() . 'feedback.php');
        exit;
    }
    $fb = feedback_with_refs($fb);
    $ctx = $fb['context'];
    admin_header('Rückmeldung ' . $id, 'feedback');
    ?>
<p><a href="<?= e(wb_admin_url()) ?>feedback.php">← Alle Rückmeldungen</a></p>
<div class="page-head">
  <div>
    <h1>Rückmeldung <?= $id ?> <?= fb_status_pill($fb) ?></h1>
    <p class="muted"><?= e(feedback_kind_label($fb['kind'])) ?> · eingegangen <?= e(admin_time($fb['created_at'])) ?><?= $fb['updated_at'] !== $fb['created_at'] ? ' · geändert ' . e(admin_time($fb['updated_at'])) : '' ?></p>
  </div>
  <div class="page-actions"><?= fb_actions($fb) ?></div>
</div>

<section class="card">
  <h2>Text</h2>
  <p class="postitem-body fb-text"><?= e($fb['text']) ?></p>
  <?php if ($fb['contact_email']): ?><p class="muted">Kontakt für Rückfragen: <a href="mailto:<?= e($fb['contact_email']) ?>"><?= e($fb['contact_email']) ?></a> (erscheint nicht im Connector)</p><?php endif; ?>
</section>

<section class="card">
  <h2>Kontext</h2>
  <dl class="fb-ctx">
    <?php if (!empty($ctx['url'])): ?><dt>Seite</dt><dd><code><?= e($ctx['url']) ?></code></dd><?php endif; ?>
    <?php if ($fb['board_id'] !== null): ?><dt>Board</dt><dd><?php if ($fb['board_key']): ?><a href="<?= e(wb_admin_url()) ?>board.php?key=<?= e($fb['board_key']) ?>"><?= e((string)$fb['board_name']) ?></a><?php else: ?>gelöscht<?php endif; ?></dd><?php endif; ?>
    <?php if ($fb['activity_id'] !== null): ?><dt>Aktivität</dt><dd><?php if ($fb['activity_key']): ?><a href="<?= e(wb_admin_url()) ?><?= $fb['activity_type'] === 'wordcloud' ? 'wordcloud' : 'target' ?>.php?key=<?= e($fb['activity_key']) ?>"><?= e((string)$fb['activity_name']) ?></a><?php else: ?>gelöscht<?php endif; ?></dd><?php endif; ?>
    <?php foreach (['page' => 'Seitenart', 'version' => 'App-Version', 'user_agent' => 'Browser', 'viewport' => 'Fenster', 'screen' => 'Bildschirm', 'language' => 'Sprachen', 'page_lang' => 'Seitensprache'] as $k => $label): ?>
      <?php if (!empty($ctx[$k])): ?><dt><?= e($label) ?></dt><dd><?= e((string)$ctx[$k]) ?></dd><?php endif; ?>
    <?php endforeach; ?>
    <dt>Besitzer angemeldet</dt><dd><?= !empty($ctx['owner']) ? 'ja' : 'nein' ?></dd>
    <dt>Browser-Schlüssel</dt><dd><code><?= e(substr($fb['browser_key'], 0, 8)) ?>…</code></dd>
  </dl>
  <?php if (!empty($ctx['errors'])): ?>
  <h3>JavaScript-Fehler (letzte <?= count($ctx['errors']) ?>)</h3>
  <ul class="fb-errors">
    <?php foreach ($ctx['errors'] as $err): ?>
    <li><strong><?= e($err['message'] ?? '') ?></strong><?php if (!empty($err['source'])): ?> <span class="muted"><?= e($err['source']) ?>:<?= (int)($err['line'] ?? 0) ?>:<?= (int)($err['col'] ?? 0) ?></span><?php endif; ?><?php if (!empty($err['at'])): ?> <span class="muted">· <?= e($err['at']) ?></span><?php endif; ?>
      <?php if (!empty($err['stack'])): ?><pre><?= e($err['stack']) ?></pre><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?>
  <p class="muted">Keine JavaScript-Fehler aufgezeichnet.</p>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Analyse und Vorschlag</h2>
  <?php if (!$fb['analysis'] && !$fb['proposal']): ?><p class="muted">Noch nicht analysiert. Der geplante Lauf schreibt Analyse und Vorschlag hierher.</p><?php endif; ?>
  <?php if ($fb['analysis']): ?><h3>Analyse</h3><p class="postitem-body"><?= e($fb['analysis']) ?></p><?php endif; ?>
  <?php if ($fb['proposal']): ?><h3>Vorschlag</h3><p class="postitem-body"><?= e($fb['proposal']) ?></p><?php endif; ?>
  <dl class="fb-ctx">
    <?php if ($fb['needs_consult']): ?><dt>Rücksprache</dt><dd>betrifft Datenmodell oder Schnittstelle</dd><?php endif; ?>
    <?php if ($fb['duplicate_of']): ?><dt>Duplikat von</dt><dd><a href="?id=<?= $fb['duplicate_of'] ?>">Rückmeldung <?= $fb['duplicate_of'] ?></a></dd><?php endif; ?>
    <?php if ($fb['approved_at']): ?><dt>Freigegeben</dt><dd><?= e(admin_time($fb['approved_at'])) ?> über <?= $fb['approved_via'] === 'chat' ? 'Claude-Sitzung' : 'Bibliothek' ?></dd><?php endif; ?>
    <?php if ($fb['digest_message_id']): ?><dt>Sammelmail</dt><dd><code><?= e($fb['digest_message_id']) ?></code></dd><?php endif; ?>
    <?php if ($fb['branch']): ?><dt>Zweig</dt><dd><code><?= e($fb['branch']) ?></code></dd><?php endif; ?>
    <?php if ($fb['commit']): ?><dt>Commit</dt><dd><code><?= e($fb['commit']) ?></code></dd><?php endif; ?>
  </dl>
</section>
<?php
    admin_footer();
    exit;
}

/* ---------------- Liste ---------------- */
$status = (string)($_GET['status'] ?? 'open');
$kind = (string)($_GET['kind'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));
$list = feedback_list(['status' => $status, 'kind' => $kind, 'q' => $q]);
$counts = feedback_counts();
admin_header('Rückmeldungen', 'feedback');
?>
<div class="page-head">
  <div>
    <h1>Rückmeldungen</h1>
    <p class="muted">Fehler und Wünsche aus dem Formular «Rückmeldung», das auf allen Seiten steht. Ein geplanter Claude-Lauf analysiert neue Rückmeldungen, schreibt Analyse und Vorschlag hierher und schickt alle zwei Tage eine Sammelmail mit einem Text, den du einer Claude-Sitzung im Projekt geben kannst. Freigeben geht dort oder hier; Mails werden nie gelesen. Die Texte stammen von anonymen Personen.</p>
  </div>
</div>

<form class="filters" method="get">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Suche in Text, Analyse und Vorschlag" aria-label="Suche">
  <select name="status" aria-label="Status">
    <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Offen (<?= (int)$counts['open'] ?>)</option>
    <?php foreach (FEEDBACK_STATUSES as $s): ?>
    <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst(feedback_status_label($s))) ?> (<?= (int)($counts[$s] ?? 0) ?>)</option>
    <?php endforeach; ?>
    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Alle</option>
    <option value="trash" <?= $status === 'trash' ? 'selected' : '' ?>>Papierkorb</option>
  </select>
  <select name="kind" aria-label="Art">
    <option value="">Alle Arten</option>
    <?php foreach (FEEDBACK_KINDS as $k): ?>
    <option value="<?= e($k) ?>" <?= $kind === $k ? 'selected' : '' ?>><?= e(feedback_kind_label($k)) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn-ghost" type="submit">Filtern</button>
</form>

<?php if (!$list): ?>
  <section class="card empty"><p>Keine Rückmeldungen gefunden.</p></section>
<?php else: ?>
<ul class="boardlist">
  <?php foreach ($list as $fb): ?>
  <li class="boardrow fb-row">
    <div class="boardrow-main">
      <a class="boardrow-name" href="?id=<?= $fb['id'] ?>">Nr. <?= $fb['id'] ?> · <?= e(mb_strimwidth(preg_replace('/\s+/', ' ', $fb['text']) ?? '', 0, 90, '…')) ?></a>
      <div class="boardrow-meta">
        <?= fb_status_pill($fb) ?>
        <span><?= e(feedback_kind_label($fb['kind'])) ?></span>
        <span><?= e(admin_time($fb['created_at'])) ?></span>
        <?php if ($fb['board_name']): ?><span>Board «<?= e($fb['board_name']) ?>»</span><?php endif; ?>
        <?php if ($fb['activity_name']): ?><span><?= $fb['activity_type'] === 'wordcloud' ? 'Wortwolke' : 'Zielscheibe' ?> «<?= e($fb['activity_name']) ?>»</span><?php endif; ?>
        <?php if (!empty($fb['context']['page']) && !$fb['board_name'] && !$fb['activity_name']): ?><span><?= e(['start' => 'Startseite', 'library' => 'Bibliothek'][$fb['context']['page']] ?? $fb['context']['page']) ?></span><?php endif; ?>
        <?php if (!empty($fb['context']['errors'])): ?><span class="bad"><?= count($fb['context']['errors']) ?> JS-Fehler</span><?php endif; ?>
        <?php if ($fb['needs_consult']): ?><span>Rücksprache nötig</span><?php endif; ?>
      </div>
      <?php if ($fb['proposal']): ?><p class="boardrow-note muted">Vorschlag: <?= e(mb_strimwidth(preg_replace('/\s+/', ' ', $fb['proposal']) ?? '', 0, 200, '…')) ?></p><?php endif; ?>
    </div>
    <div class="boardrow-actions"><?= fb_actions($fb) ?></div>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
<?php admin_footer();
