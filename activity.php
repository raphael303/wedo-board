<?php
declare(strict_types=1);
// Seite einer Aktivität (Feedbackzielscheibe oder Wortwolke). Aufruf über /a/<schlüssel> oder über das Kurzwort
// (board.php reicht ein Kurzwort hierher weiter, wenn es zu keiner Board, aber zu einer Aktivität gehört).
if (!defined('WB_ROOT')) require __DIR__ . '/lib/bootstrap.php';
require_once WB_ROOT . '/lib/qr.php';

if (!isset($activity)) {
    $activity = db_is_installed() ? activity_by_key((string)($_GET['key'] ?? '')) : null;
}
$lang = wb_resolve_lang($activity['lang'] ?? (string)wb_config('default_lang', 'de'));
$uid = wb_client_uid();
$owner = $activity ? wb_can('board.manage') : false;
$isCloud = ($activity['type'] ?? '') === 'wordcloud';
$isExam = ($activity['type'] ?? '') === 'exam';

if (!$activity || $activity['status'] === 'archived') {
    http_response_code(404);
    ?><!doctype html><html lang="<?= e($lang) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e(wb_app_name()) ?></title><link rel="stylesheet" href="/assets/board.css?v=<?= WB_VERSION ?>"></head>
    <body class="bg-graphit"><main class="notfound"><h1><?= e(t($isExam ? 'exam_not_found' : ($isCloud ? 'wc_not_found' : 'target_not_found'), $lang)) ?></h1></main></body></html><?php
    exit;
}

// Gemeinschaftsprüfung: eigene Seite.
if ($isExam) { require WB_ROOT . '/lib/exam_page.php'; exit; }

$round = $owner && isset($_GET['round']) ? max(1, (int)$_GET['round']) : null;
$boot = [
    'activity' => activity_public($activity, $lang),
    'me' => ['owner' => $owner, 'uid' => $uid, 'fresh' => !empty($GLOBALS['wb_uid_fresh'])],
    'strings' => wb_lang_strings($lang),
    'state' => activity_state($activity, $uid, null, $owner, $round),
    'api' => '/api/a/' . $activity['akey'],
    'csrf' => $owner ? wb_csrf_token() : null,
    'view_round' => $round,
];
if ($isCloud && $owner) $boot['ai'] = ai_wordcloud_status($activity) + ['settings_url' => wb_admin_url() . 'settings.php#ki', 'edit_url' => wb_admin_url() . 'wordcloud.php?key=' . $activity['akey']];
$accessUrl = (string)$activity['url'];
$accessLabel = preg_replace('~^https?://(www\.)?~i', '', $accessUrl);
$accessQr = wb_qr_svg($accessUrl);
?>
<!doctype html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title><?= e($activity['name']) ?> – <?= e(wb_app_name()) ?></title>
<link rel="stylesheet" href="/assets/board.css?v=<?= WB_VERSION ?>">
<link rel="stylesheet" href="/assets/target.css?v=<?= WB_VERSION ?>">
<?php if ($isCloud): ?>
<link rel="stylesheet" href="/assets/wordcloud.css?v=<?= WB_VERSION ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%231b1f24'/><text x='16' y='15' font-family='sans-serif' font-size='11' font-weight='700' fill='%23fff' text-anchor='middle'>Aa</text><text x='16' y='25' font-family='sans-serif' font-size='7' fill='%23fff' text-anchor='middle'>abc</text></svg>">
<?php else: ?>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%231b1f24'/><circle cx='16' cy='16' r='10' fill='none' stroke='%23fff' stroke-width='2'/><circle cx='16' cy='16' r='5' fill='none' stroke='%23fff' stroke-width='2'/><circle cx='16' cy='16' r='1.6' fill='%23fff'/></svg>">
<?php endif; ?>
<?= feedback_head($lang, ['page' => $activity['type'], 'activity_key' => $activity['akey'], 'owner' => $owner]) ?>
</head>
<body class="bg-<?= e($activity['background']) ?> page-target<?= $isCloud ? ' page-wordcloud' : '' ?>">
<header class="bar">
  <h1 class="bar-title"><?= e($activity['name']) ?></h1>
  <div class="bar-tools">
    <a class="bar-btn" href="?lang=<?= $lang === 'de' ? 'en' : 'de' ?>" title="<?= e(t('language', $lang)) ?>"><?= $lang === 'de' ? 'EN' : 'DE' ?></a>
    <button class="bar-btn" id="access-btn" type="button" aria-controls="access" aria-expanded="false" title="<?= e(t('access', $lang)) ?>" aria-label="<?= e(t('access', $lang)) ?>">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 20h4v-3"/></svg>
    </button>
    <button class="bar-btn" id="presenter" type="button" title="<?= e(t('presenter', $lang)) ?>" aria-label="<?= e(t('presenter', $lang)) ?>">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3M16 21h3a2 2 0 0 0 2-2v-3"/></svg>
    </button>
    <button class="bar-btn" type="button" data-feedback title="<?= e(t('fb_link', $lang)) ?>" aria-label="<?= e(t('fb_link', $lang)) ?>">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M12 7v4M12 14h.01"/></svg>
    </button>
    <?php if ($owner): ?>
    <a class="bar-btn" href="<?= e(wb_admin_url()) ?><?= $isCloud ? 'wordcloud' : 'target' ?>.php?key=<?= e($activity['akey']) ?>" title="Bibliothek" aria-label="Bibliothek"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3h0a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8v0a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg></a>
    <?php if ($isCloud && $round === null): ?>
    <button class="btn-ghost bar-ghost" id="wc-hidden" type="button" hidden></button>
    <button class="btn-ghost bar-ghost" id="wc-undo" type="button" hidden><?= e(t('wc_undo', $lang)) ?></button>
    <button class="btn-ghost bar-ghost wc-merge" id="wc-merge" type="button" title="<?= e(t('wc_merge_title', $lang)) ?>">✨ <?= e(t('wc_merge', $lang)) ?></button>
    <?php endif; ?>
    <?php if ($round === null): ?><button class="btn-ghost bar-ghost" id="new-round" type="button"><?= e(t('target_new_round', $lang)) ?></button><?php endif; ?>
    <?php endif; ?>
  </div>
</header>

<div id="notice" class="notice" hidden></div>

<?php if ($isCloud): ?>
<main class="target-stage wc-stage" id="stage">
  <?php if ($round !== null): ?><p class="target-past"><span id="past-label"></span> <a href="?">· <?= e(t('target_back_current', $lang)) ?></a></p><?php endif; ?>
  <h2 class="wc-question"><?= e($activity['name']) ?></h2>
  <p class="target-hint" id="hint" hidden></p>
  <div class="wc-panel"><div class="wc-cloud" id="cloud" role="list"></div><p class="wc-empty" id="cloud-empty" hidden></p></div>
</main>

<div class="wc-dock" id="dock" hidden>
  <ul class="wc-mine" id="mine" aria-label="<?= e(t('wc_mine', $lang)) ?>"></ul>
  <form class="wc-form" id="wc-form" autocomplete="off">
    <input id="wc-input" type="text" maxlength="<?= WORDCLOUD_TERM_LENGTH ?>" enterkeyhint="send" placeholder="<?= e(t('wc_placeholder', $lang)) ?>" aria-label="<?= e(t('wc_placeholder', $lang)) ?>">
    <span class="wc-progress" id="progress"></span>
    <button class="btn-primary" id="wc-send" type="submit"><?= e(t('wc_send', $lang)) ?></button>
  </form>
</div>

<div class="target-count" id="count-box">
  <span class="target-count-n" id="count" aria-live="polite"></span>
  <?php if ($owner && $round === null && !$activity['config']['live']): ?><button class="btn-primary target-reveal" id="reveal" type="button"><?= e(t('target_reveal', $lang)) ?></button><?php endif; ?>
</div>

<dialog id="wc-term" class="wc-dialog">
  <form method="dialog" class="wc-dialog-inner" id="wc-term-body"></form>
</dialog>

<?php else: ?>
<main class="target-stage" id="stage">
  <?php if ($round !== null): ?><p class="target-past"><span id="past-label"></span> <a href="?">· <?= e(t('target_back_current', $lang)) ?></a></p><?php endif; ?>
  <p class="target-hint" id="hint"></p>
  <div class="target-disc" id="disc" role="application" aria-label="<?= e(t('target_disc_label', $lang, ['n' => count($activity['config']['aspects'])])) ?>"></div>
  <p class="target-legend"><span class="lg lg-center"></span><?= e(t('target_center', $lang)) ?><span class="lg lg-edge"></span><?= e(t('target_edge', $lang)) ?></p>
</main>

<div class="target-dock" id="dock" hidden>
  <span class="target-progress" id="progress"></span>
  <button class="btn-ghost" id="discard" type="button" hidden><?= e(t('target_cancel_change', $lang)) ?></button>
  <button class="btn-primary" id="confirm" type="button"><?= e(t('target_confirm', $lang)) ?></button>
</div>

<div class="target-count" id="count-box">
  <?php if ($owner && $round === null): ?><span class="target-count-hint" id="owner-hint"><?= e(t('target_hint_owner', $lang)) ?></span><?php endif; ?>
  <span class="target-count-n" id="count" aria-live="polite"></span>
  <?php if ($owner && $round === null): ?><button class="btn-primary target-reveal" id="reveal" type="button"><?= e(t('target_reveal', $lang)) ?></button><?php endif; ?>
</div>

<?php endif; ?>
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

<script>window.WT = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="/assets/<?= $isCloud ? 'wordcloud' : 'target' ?>.js?v=<?= WB_VERSION ?>"></script>
</body>
</html>
