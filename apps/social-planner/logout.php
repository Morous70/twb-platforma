<?php
declare(strict_types=1);
require __DIR__ . '/app/lib/bootstrap.php';
session_start_safe();
$_SESSION = [];
session_destroy();
header('Location: login.php');
