<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once WB_ROOT . '/lib/qr.php';

// Eigener Pfad der Bibliothek (config: admin_path): /<pfad> leitet auf /<pfad>/ um, /<pfad>/ zeigt die Bibliothek.
if (isset($_GET['slug']) && strtolower((string)$_GET['slug']) === wb_admin_path() && wb_admin_path() !== 'admin') {
    if (!str_ends_with((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/')) {
        header('Location: ' . wb_admin_url(), true, 302);
        exit;
    }
    require __DIR__ . '/admin/index.php';
    exit;
}

$board = null;
if (db_is_installed()) {
    $board = isset($_GET['slug']) ? board_by_slug((string)$_GET['slug']) : board_by_key((string)($_GET['key'] ?? ''));
    // Boards und Aktivitäten teilen sich die Kurzwörter: Gehört das Wort zu einer Zielscheibe, zeigt activity.php sie.
    if (!$board && isset($_GET['slug'])) {
        $activity = activity_by_slug((string)$_GET['slug']);
        if ($activity) { require __DIR__ . '/activity.php'; exit; }
    }
}
$lang = wb_resolve_lang($board['lang'] ?? (string)wb_config('default_lang', 'de'));
$uid = wb_client_uid();
$owner = $board ? wb_can('board.manage', $board) : false;

if (!$board || $board['status'] === 'archived') {
    http_response_code(404);
    ?><!doctype html><html lang="<?= e($lang) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e(wb_app_name()) ?></title><link rel="stylesheet" href="/assets/board.css"></head>
    <body class="bg-graphit"><main class="notfound"><h1><?= e(t('not_found', $lang)) ?></h1></main></body></html><?php
    exit;
}

$name = wb_guest_name($board, $uid);
$blocked = !$owner && guest_block_active($uid, $board['id']) !== null;
$boot = [
    'board' => board_public($board, $lang),
    'me' => ['name' => $name, 'hue' => wb_author_hue($name), 'owner' => $owner, 'uid' => $uid, 'blocked' => $blocked, 'fresh' => !empty($GLOBALS['wb_uid_fresh'])],
    'strings' => wb_lang_strings($lang),
    'state' => board_state($board, $uid, null, $owner),
    'api' => '/api/b/' . $board['bkey'],
    'csrf' => $owner ? wb_csrf_token() : null,
];
// Export-Dialog: Welche KI-Zusammenfassungen sind möglich (oder schon gespeichert)?
$exportData = export_collect($board);
$exportAi = [];
foreach (SUMMARY_MODES as $m) {
    $r = summary_unavailable_reason($board, $m, $exportData);
    $stored = $r !== null && $r !== 'no_table' && summary_row($board['id'], $m) !== null;
    $exportAi[$m] = ['ok' => $r === null || $stored, 'reason' => $r === null || $stored ? null : t('export_reason_' . $r, $lang, ['n' => SUMMARY_MIN_POSTS])];
}
$exportDefaultAi = $exportAi['board']['ok'] ? 'board' : 'none';
// Zugangs-Panel: Kurzlink ohne Protokoll anzeigen, QR-Code direkt einbetten (kein zusätzlicher Abruf).
$accessUrl = (string)$board['url'];
$accessLabel = preg_replace('~^https?://(www\.)?~i', '', $accessUrl);
$accessQr = wb_qr_svg($accessUrl);
?>
<!doctype html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title><?= e($board['name']) ?> – <?= e(wb_app_name()) ?></title>
<link rel="stylesheet" href="/assets/board.css?v=<?= WB_VERSION ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%231b1f24'/><rect x='7' y='7' width='8' height='11' rx='2' fill='%23fff'/><rect x='17' y='7' width='8' height='6' rx='2' fill='%23fff'/><rect x='17' y='15' width='8' height='10' rx='2' fill='%23fff'/><rect x='7' y='20' width='8' height='5' rx='2' fill='%23fff'/></svg>">
<?= feedback_head($lang, ['page' => 'board', 'board_key' => $board['bkey'], 'owner' => $owner]) ?>
</head>
<body class="bg-<?= e($board['background']) ?> type-<?= e($board['type']) ?>">
<header class="bar">
  <h1 class="bar-title"><?= e($board['name']) ?></h1>
  <div class="bar-tools">
    <span class="me" title="<?= e(t('you_are', $lang)) ?>"><i class="dot" style="--hue:<?= wb_author_hue($name) ?>"></i><span class="me-name"><?= e($name) ?></span></span>
    <a class="bar-btn" href="?lang=<?= $lang === 'de' ? 'en' : 'de' ?>" title="<?= e(t('language', $lang)) ?>"><?= $lang === 'de' ? 'EN' : 'DE' ?></a>
    <button class="bar-btn" id="access-btn" type="button" aria-controls="access" aria-expanded="false" title="<?= e(t('access', $lang)) ?>" aria-label="<?= e(t('access', $lang)) ?>">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 20h4v-3"/></svg>
    </button>
    <button class="bar-btn" id="export-btn" type="button" title="<?= e(t('export', $lang)) ?>" aria-label="<?= e(t('export', $lang)) ?>">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/></svg>
    </button>
    <button class="bar-btn" id="presenter" type="button" title="<?= e(t('presenter', $lang)) ?>" aria-label="<?= e(t('presenter', $lang)) ?>">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3M16 21h3a2 2 0 0 0 2-2v-3"/></svg>
    </button>
    <button class="bar-btn" type="button" data-feedback title="<?= e(t('fb_link', $lang)) ?>" aria-label="<?= e(t('fb_link', $lang)) ?>">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M12 7v4M12 14h.01"/></svg>
    </button>
    <?php if ($owner): ?><a class="bar-btn" href="<?= e(wb_admin_url()) ?>board.php?key=<?= e($board['bkey']) ?>" title="Bibliothek" aria-label="Bibliothek"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3h0a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8v0a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg></a><?php endif; ?>
    <?php if ($owner): ?><button class="bar-btn" id="history-btn" type="button" title="<?= e(t('history', $lang)) ?>" aria-label="<?= e(t('history', $lang)) ?>"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 7v5l3 2"/></svg></button><?php endif; ?>
    <?php if ($owner): ?><button class="btn-ghost bar-ghost" id="new-bundle" type="button"><?= e(t('bundle_new', $lang)) ?></button><button class="btn-ghost bar-ghost" id="ai-bundle" type="button"><?= e(t('ai_bundle', $lang)) ?></button><?php endif; ?>
    <button class="btn-primary" id="new-post" type="button"><?= e(t('new_post', $lang)) ?></button>
  </div>
</header>

<div id="notice" class="notice" hidden></div>

<main id="board" class="board" aria-live="polite"></main>

<aside id="access" class="access" hidden aria-label="<?= e(t('access_join', $lang)) ?>">
  <div class="access-head">
    <span class="access-title"><?= e(t('access_join', $lang)) ?></span>
    <button class="access-x" id="access-close" type="button" title="<?= e(t('access_hide', $lang)) ?>" aria-label="<?= e(t('access_hide', $lang)) ?>">×</button>
  </div>
  <button class="access-qr" id="access-enlarge" type="button" title="<?= e(t('access_enlarge', $lang)) ?>" aria-label="<?= e(t('access_enlarge', $lang)) ?>"><?= $accessQr ?></button>
  <p class="access-url"><?= e($accessLabel) ?></p>
  <p class="access-hint"><?= e(t('access_hint', $lang)) ?></p>
  <button class="btn-ghost small access-copy" id="access-copy" type="button" data-url="<?= e($accessUrl) ?>"><?= e(t('access_copy', $lang)) ?></button>
</aside>

<dialog id="access-big" class="access-big">
  <div class="access-big-inner">
    <div class="access-big-qr"><?= $accessQr ?></div>
    <p class="access-big-url"><?= e($accessLabel) ?></p>
  </div>
  <button type="button" class="lightbox-close" id="access-big-close" aria-label="<?= e(t('close', $lang)) ?>">×</button>
</dialog>

<button class="fab" id="fab" type="button" aria-label="<?= e(t('new_post', $lang)) ?>">+</button>

<dialog id="composer" class="sheet">
  <form id="composer-form" method="dialog" novalidate>
    <input type="hidden" name="post_id" value="">
    <?php if ($board['type'] === 'columns'): ?>
    <fieldset class="column-choice">
      <legend><?= e(t('column_choice', $lang)) ?></legend>
      <?php foreach ($board['columns'] as $i => $col): ?>
        <label class="choice"><input type="radio" name="column" value="<?= e($col['id']) ?>" <?= $i === 0 ? 'checked' : '' ?>><span><?= e($col['title']) ?></span></label>
      <?php endforeach; ?>
    </fieldset>
    <?php else: ?>
    <input type="hidden" name="column" value="">
    <?php endif; ?>
    <input class="field field-title" name="title" maxlength="200" placeholder="<?= e(t('post_title', $lang)) ?>" autocomplete="off">
    <textarea class="field field-body" name="body" maxlength="5000" rows="5" placeholder="<?= e(t('post_body', $lang)) ?>"></textarea>
    <div class="composer-extras">
      <input class="field field-link" name="link" maxlength="1000" placeholder="<?= e(t('post_link', $lang)) ?>" inputmode="url" autocomplete="off">
      <div class="image-row">
        <label class="btn-ghost image-pick"><input type="file" name="image" accept="image/*" hidden><?= e(t('post_image', $lang)) ?></label>
        <button type="button" class="btn-ghost" id="image-remove" hidden><?= e(t('post_image_remove', $lang)) ?></button>
        <img id="image-preview" alt="" hidden>
      </div>
    </div>
    <p class="form-error" id="composer-error" hidden></p>
    <div class="sheet-actions">
      <button type="button" class="btn-ghost" id="composer-cancel"><?= e(t('cancel', $lang)) ?></button>
      <button type="submit" class="btn-primary" id="composer-submit"><?= e(t('publish', $lang)) ?></button>
    </div>
  </form>
</dialog>

<?php if ($owner): ?>
<dialog id="bundle-dialog" class="sheet">
  <form id="bundle-form" method="dialog" novalidate>
    <?php if ($board['type'] === 'columns'): ?>
    <fieldset class="column-choice">
      <legend><?= e(t('column_choice', $lang)) ?></legend>
      <?php foreach ($board['columns'] as $i => $col): ?>
        <label class="choice"><input type="radio" name="column" value="<?= e($col['id']) ?>" <?= $i === 0 ? 'checked' : '' ?>><span><?= e($col['title']) ?></span></label>
      <?php endforeach; ?>
    </fieldset>
    <?php endif; ?>
    <input class="field field-title" name="title" maxlength="200" placeholder="<?= e(t('bundle_title', $lang)) ?>" autocomplete="off" required>
    <textarea class="field field-body" name="summary" maxlength="5000" rows="3" placeholder="<?= e(t('bundle_summary', $lang)) ?>"></textarea>
    <p class="hint-text"><?= e(t('bundle_hint', $lang)) ?></p>
    <div class="sheet-actions">
      <button type="button" class="btn-ghost" id="bundle-cancel"><?= e(t('cancel', $lang)) ?></button>
      <button type="submit" class="btn-primary" id="bundle-submit"><?= e(t('bundle_create', $lang)) ?></button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($owner): ?>
<dialog id="ai-dialog" class="sheet">
  <form id="ai-form" method="dialog" novalidate>
    <h2 class="sheet-title"><?= e(t('ai_title', $lang)) ?></h2>
    <div id="ai-unavailable" hidden>
      <p class="ai-msg" id="ai-unavailable-text"></p>
      <div class="sheet-actions"><button type="button" class="btn-ghost ai-close"><?= e(t('close', $lang)) ?></button><a class="btn-primary" id="ai-settings-link" data-admin="<?= e(wb_admin_url()) ?>" href="<?= e(wb_admin_url()) ?>settings.php#ki"><?= e(t('ai_to_settings', $lang)) ?></a></div>
    </div>
    <div id="ai-body" hidden>
      <label class="field-label" for="ai-instruction"><?= e(t('ai_instruction', $lang)) ?></label>
      <textarea class="field" id="ai-instruction" name="instruction" rows="3" maxlength="2000" placeholder="<?= e(t('ai_instruction_placeholder', $lang)) ?>"></textarea>
      <div class="chips" id="ai-recent" hidden></div>
      <div class="ai-options">
        <label class="radio-row"><input type="radio" name="scope" value="all" checked> <span><?= e(t('ai_scope_all', $lang)) ?></span></label>
        <label class="radio-row"><input type="radio" name="scope" value="loose"> <span><?= e(t('ai_scope_loose', $lang)) ?></span></label>
        <label class="check-row"><input type="checkbox" name="collect_rest"> <span><?= e(t('ai_collect_rest', $lang)) ?></span></label>
      </div>
      <p class="hint-text" id="ai-warn" hidden></p>
      <p class="hint-text" id="ai-model-hint"></p>
      <p class="form-error" id="ai-error" hidden></p>
      <div class="sheet-actions">
        <button type="button" class="btn-ghost ai-close"><?= e(t('cancel', $lang)) ?></button>
        <button type="submit" class="btn-primary" id="ai-submit"><?= e(t('ai_run_auto', $lang)) ?></button>
      </div>
    </div>
    <div id="ai-running" hidden>
      <p class="ai-msg"><span class="spinner"></span> <?= e(t('ai_running', $lang)) ?></p>
      <div class="sheet-actions"><button type="button" class="btn-ghost" id="ai-abort"><?= e(t('cancel', $lang)) ?></button></div>
    </div>
  </form>
</dialog>
<dialog id="block-dialog" class="sheet">
  <form id="block-form" method="dialog" novalidate>
    <h2 class="sheet-title" id="block-title"></h2>
    <input type="hidden" name="guest_key" value="">
    <input type="hidden" name="display_name" value="">
    <label class="radio-row"><input type="radio" name="scope" value="board" checked> <span><?= e(t('block_board', $lang)) ?></span></label>
    <label class="radio-row"><input type="radio" name="scope" value="all"> <span><?= e(t('block_all', $lang)) ?></span></label>
    <label class="check-row"><input type="checkbox" name="hide"> <span><?= e(t('block_hide', $lang)) ?></span></label>
    <p class="hint-text"><?= e(t('block_hint', $lang)) ?></p>
    <div class="sheet-actions">
      <button type="button" class="btn-ghost" id="block-cancel"><?= e(t('cancel', $lang)) ?></button>
      <button type="submit" class="btn-primary" id="block-submit"><?= e(t('block_board', $lang)) ?></button>
    </div>
  </form>
</dialog>
<dialog id="history-dialog" class="sheet">
  <div class="sheet-body">
    <h2 class="sheet-title"><?= e(t('history', $lang)) ?></h2>
    <p class="hint-text"><?= e(t('history_hint', $lang)) ?></p>
    <ul class="history" id="history-list"></ul>
    <div class="sheet-actions"><button type="button" class="btn-ghost" id="history-close"><?= e(t('close', $lang)) ?></button></div>
  </div>
</dialog>
<?php endif; ?>

<dialog id="export-dialog" class="sheet">
  <form id="export-form" method="get" action="/b/<?= e($board['bkey']) ?>/export" target="_blank" novalidate>
    <h2 class="sheet-title"><?= e(t('export_title', $lang)) ?></h2>
    <span class="field-label"><?= e(t('export_ai', $lang)) ?></span>
    <label class="radio-row"><input type="radio" name="ai" value="none" <?= $exportDefaultAi === 'none' ? 'checked' : '' ?>> <span><?= e(t('export_ai_none', $lang)) ?></span></label>
    <?php foreach (['board' => 'export_ai_board', 'units' => 'export_ai_units_' . ($board['type'] === 'columns' ? 'columns' : 'bundles')] as $m => $label): ?>
    <label class="radio-row<?= $exportAi[$m]['ok'] ? '' : ' is-disabled' ?>"><input type="radio" name="ai" value="<?= $m ?>" <?= $exportAi[$m]['ok'] ? '' : 'disabled' ?> <?= $exportDefaultAi === $m ? 'checked' : '' ?>> <span><?= e(t($label, $lang)) ?><?php if (!$exportAi[$m]['ok']): ?> <small class="muted-inline"><?= e($exportAi[$m]['reason']) ?></small><?php endif; ?></span></label>
    <?php endforeach; ?>
    <input type="hidden" name="list" value="0">
    <label class="check-row" id="export-list-row"><input type="checkbox" name="list" value="1" checked> <span><?= e(t('export_list', $lang)) ?></span></label>
    <span class="field-label"><?= e(t('export_group', $lang)) ?></span>
    <div class="radio-inline">
      <?php foreach (($board['type'] === 'columns' ? ['columns', 'bundles', 'time'] : ['bundles', 'time']) as $i => $g): ?>
      <label class="radio-row"><input type="radio" name="group" value="<?= $g ?>" <?= $i === 0 ? 'checked' : '' ?>> <span><?= e(t('export_group_' . $g, $lang)) ?></span></label>
      <?php endforeach; ?>
    </div>
    <span class="field-label"><?= e(t('export_images', $lang)) ?></span>
    <div class="radio-inline">
      <?php foreach (['thumb', 'large', 'none'] as $i => $g): ?>
      <label class="radio-row"><input type="radio" name="img" value="<?= $g ?>" <?= $i === 0 ? 'checked' : '' ?>> <span><?= e(t('export_img_' . $g, $lang)) ?></span></label>
      <?php endforeach; ?>
    </div>
    <input type="hidden" name="comments" value="0">
    <label class="check-row"><input type="checkbox" name="comments" value="1" checked> <span><?= e(t('export_comments', $lang)) ?></span></label>
    <input type="hidden" name="reactions" value="0">
    <label class="check-row"><input type="checkbox" name="reactions" value="1" checked> <span><?= e(t('export_reactions', $lang)) ?></span></label>
    <?php if ($board['type'] === 'columns'): ?>
    <input type="hidden" name="newpage" value="0">
    <label class="check-row"><input type="checkbox" name="newpage" value="1"> <span><?= e(t('export_newpage', $lang)) ?></span></label>
    <?php endif; ?>
    <p class="hint-text"><?= e(t('export_hint', $lang)) ?></p>
    <div class="sheet-actions">
      <button type="button" class="btn-ghost" id="export-cancel"><?= e(t('cancel', $lang)) ?></button>
      <button type="submit" class="btn-primary" id="export-submit"><?= e(t('export_open', $lang)) ?></button>
    </div>
  </form>
</dialog>

<dialog id="lightbox" class="lightbox"><img alt=""><button type="button" class="lightbox-close" aria-label="<?= e(t('close', $lang)) ?>">×</button></dialog>

<script>window.WB = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="/assets/board.js?v=<?= WB_VERSION ?>"></script>
</body>
</html>
