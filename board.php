<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$board = null;
if (db_is_installed()) {
    $board = isset($_GET['slug']) ? board_by_slug((string)$_GET['slug']) : board_by_key((string)($_GET['key'] ?? ''));
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

$name = wb_fantasy_name($uid, $board['bkey'], $board['lang']);
$blocked = !$owner && guest_block_active($uid, $board['id']) !== null;
$boot = [
    'board' => board_public($board, $lang),
    'me' => ['name' => $name, 'hue' => wb_author_hue($name), 'owner' => $owner, 'uid' => $uid, 'blocked' => $blocked, 'fresh' => !empty($GLOBALS['wb_uid_fresh'])],
    'strings' => wb_lang_strings($lang),
    'state' => board_state($board, $uid, null, $owner),
    'api' => '/api/b/' . $board['bkey'],
    'csrf' => $owner ? wb_csrf_token() : null,
];
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
</head>
<body class="bg-<?= e($board['background']) ?> type-<?= e($board['type']) ?>">
<header class="bar">
  <h1 class="bar-title"><?= e($board['name']) ?></h1>
  <div class="bar-tools">
    <span class="me" title="<?= e(t('you_are', $lang)) ?>"><i class="dot" style="--hue:<?= wb_author_hue($name) ?>"></i><span class="me-name"><?= e($name) ?></span></span>
    <a class="bar-btn" href="?lang=<?= $lang === 'de' ? 'en' : 'de' ?>" title="<?= e(t('language', $lang)) ?>"><?= $lang === 'de' ? 'EN' : 'DE' ?></a>
    <button class="bar-btn" id="presenter" type="button" title="<?= e(t('presenter', $lang)) ?>" aria-label="<?= e(t('presenter', $lang)) ?>">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3M16 21h3a2 2 0 0 0 2-2v-3"/></svg>
    </button>
    <?php if ($owner): ?><a class="bar-btn" href="/admin/board.php?key=<?= e($board['bkey']) ?>" title="Bibliothek" aria-label="Bibliothek"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3h0a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8v0a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg></a><?php endif; ?>
    <?php if ($owner): ?><button class="bar-btn" id="history-btn" type="button" title="<?= e(t('history', $lang)) ?>" aria-label="<?= e(t('history', $lang)) ?>"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 7v5l3 2"/></svg></button><?php endif; ?>
    <?php if ($owner): ?><button class="btn-ghost bar-ghost" id="new-bundle" type="button"><?= e(t('bundle_new', $lang)) ?></button><?php endif; ?>
    <button class="btn-primary" id="new-post" type="button"><?= e(t('new_post', $lang)) ?></button>
  </div>
</header>

<div id="notice" class="notice" hidden></div>

<main id="board" class="board" aria-live="polite"></main>

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

<dialog id="lightbox" class="lightbox"><img alt=""><button type="button" class="lightbox-close" aria-label="<?= e(t('close', $lang)) ?>">×</button></dialog>

<script>window.WB = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="/assets/board.js?v=<?= WB_VERSION ?>"></script>
</body>
</html>
