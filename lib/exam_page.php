<?php
declare(strict_types=1);
// Seite einer Gemeinschaftsprüfung, eingebunden von activity.php ($activity, $lang, $uid, $owner sind gesetzt).
// Etappe G1: Fragen beisteuern in der Sammelphase. Lösen und Ergebnisse folgen in G2.
if (!defined('WB_ROOT')) exit;
require_once WB_ROOT . '/lib/qr.php';

$boot = [
    'activity' => activity_public($activity, $lang),
    'me' => ['owner' => $owner, 'uid' => $uid, 'fresh' => !empty($GLOBALS['wb_uid_fresh'])],
    'strings' => wb_lang_strings($lang),
    'state' => activity_state($activity, $uid, null, $owner),
    'api' => '/api/a/' . $activity['akey'],
    'limits' => ['prompt' => EXAM_PROMPT_LENGTH, 'option' => EXAM_OPTION_LENGTH, 'solution' => EXAM_SOLUTION_LENGTH, 'min_options' => EXAM_MIN_OPTIONS, 'max_options' => EXAM_MAX_OPTIONS],
    'library_url' => $owner ? wb_admin_url() . 'exam.php?key=' . $activity['akey'] : null,
];
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
<link rel="stylesheet" href="/assets/exam.css?v=<?= WB_VERSION ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%231b1f24'/><path d='M9 11h14M9 16h14M9 21h9' stroke='%23fff' stroke-width='2.4' stroke-linecap='round'/></svg>">
<?= feedback_head($lang, ['page' => 'exam', 'activity_key' => $activity['akey'], 'owner' => $owner]) ?>
</head>
<body class="bg-<?= e($activity['background']) ?> page-target page-exam">
<header class="bar">
  <h1 class="bar-title"><?= e($activity['name']) ?></h1>
  <div class="bar-tools">
    <a class="bar-btn" href="?lang=<?= $lang === 'de' ? 'en' : 'de' ?>" title="<?= e(t('language', $lang)) ?>"><?= $lang === 'de' ? 'EN' : 'DE' ?></a>
    <button class="bar-btn" id="access-btn" type="button" aria-controls="access" aria-expanded="false" title="<?= e(t('access', $lang)) ?>" aria-label="<?= e(t('access', $lang)) ?>">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 20h4v-3"/></svg>
    </button>
    <button class="bar-btn" type="button" data-feedback title="<?= e(t('fb_link', $lang)) ?>" aria-label="<?= e(t('fb_link', $lang)) ?>">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M12 7v4M12 14h.01"/></svg>
    </button>
    <?php if ($owner): ?>
    <a class="bar-btn" href="<?= e(wb_admin_url()) ?>exam.php?key=<?= e($activity['akey']) ?>" title="Bibliothek" aria-label="Bibliothek"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></a>
    <?php endif; ?>
  </div>
</header>

<div id="notice" class="notice" hidden></div>

<main class="exam-stage" id="stage">
  <div class="exam-col">
    <section class="exam-card exam-head">
      <p class="exam-lead" id="lead"></p>
      <p class="exam-sub" id="sub"></p>
      <p class="exam-count" id="count" aria-live="polite"></p>
      <?php if ($owner): ?><p class="exam-actions"><a class="btn-primary" href="<?= e(wb_admin_url()) ?>exam.php?key=<?= e($activity['akey']) ?>"><?= e(t('exam_open_library', $lang)) ?></a></p><?php endif; ?>
    </section>

    <section class="exam-mine" id="mine-box" hidden>
      <h2 class="exam-h"><?= e(t('exam_my_questions', $lang)) ?> <span class="exam-progress" id="progress"></span></h2>
      <ol class="exam-list" id="mine"></ol>
    </section>

    <p class="exam-new"><button class="btn-primary" id="new-q" type="button" hidden>+ <?= e(t('exam_new_question', $lang)) ?></button></p>

    <form class="exam-card exam-form" id="qform" hidden autocomplete="off" novalidate>
      <fieldset class="exam-types">
        <legend class="exam-label"><?= e(t('exam_type_label', $lang)) ?></legend>
        <div class="exam-seg" role="radiogroup">
          <?php foreach (EXAM_QUESTION_TYPES as $qt): ?>
          <label class="exam-seg-item"><input type="radio" name="type" value="<?= $qt ?>"><span><?= e(t('exam_type_' . $qt, $lang)) ?></span></label>
          <?php endforeach; ?>
        </div>
        <p class="exam-hint" id="type-hint"></p>
      </fieldset>
      <label class="exam-label" for="q-prompt"><?= e(t('exam_prompt_label', $lang)) ?></label>
      <textarea id="q-prompt" rows="3" maxlength="<?= EXAM_PROMPT_LENGTH ?>" placeholder="<?= e(t('exam_prompt_ph', $lang)) ?>"></textarea>
      <div id="opt-box">
        <p class="exam-label" id="opt-label"></p>
        <ul class="exam-opts" id="opts"></ul>
        <button class="btn-ghost small" id="add-opt" type="button"></button>
      </div>
      <div id="sol-box" hidden>
        <label class="exam-label" for="q-solution"><?= e(t('exam_solution_label', $lang)) ?></label>
        <textarea id="q-solution" rows="2" maxlength="<?= EXAM_SOLUTION_LENGTH ?>"></textarea>
        <p class="exam-hint"><?= e(t('exam_solution_hint', $lang)) ?></p>
      </div>
      <p class="exam-error" id="form-error" hidden></p>
      <div class="exam-form-actions">
        <button class="btn-ghost" id="q-cancel" type="button"><?= e(t('exam_cancel', $lang)) ?></button>
        <button class="btn-primary" id="q-save" type="submit"><?= e(t('exam_save', $lang)) ?></button>
      </div>
    </form>
  </div>
</main>

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
<script src="/assets/exam.js?v=<?= WB_VERSION ?>"></script>
</body>
</html>
