<?php
declare(strict_types=1);
// Abmelden geht seit 0.22.0 mit POST an /logout (CSRF-Feld); diese Adresse führt nur noch zum Konto.
require_once dirname(__DIR__) . '/lib/bootstrap.php';
header('Location: /account', true, 302);
