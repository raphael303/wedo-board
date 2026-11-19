<?php
declare(strict_types=1);
// /logout: nur mit POST und CSRF-Feld, damit ein fremder Link niemanden abmeldet.
if (!defined('WB_ROOT')) exit;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ui_check_csrf();
    wb_logout();
    ui_redirect('/login', tx('Du bist abgemeldet.'));
}
ui_redirect($u ? '/account' : '/login');
