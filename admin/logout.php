<?php
declare(strict_types=1);
require __DIR__ . '/_layout.php';
wb_admin_logout();
header('Location: ' . wb_admin_url() . 'login.php');
