<?php
declare(strict_types=1);
// Startseite: zur Bibliothek (oder zur Installation).
require __DIR__ . '/lib/bootstrap.php';
header('Location: ' . (db_is_installed() ? '/admin/' : '/install.php'));
