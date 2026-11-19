<?php
declare(strict_types=1);
// Die Anmeldung liegt seit 0.22.0 für alle unter /login.
require_once dirname(__DIR__) . '/lib/bootstrap.php';
header('Location: /login' . (isset($_GET['next']) ? '?next=' . urlencode((string)$_GET['next']) : ''), true, 302);
