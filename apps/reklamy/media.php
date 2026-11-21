<?php
declare(strict_types=1);
// Náhled souboru z galerie v administraci (jen přihlášený). Veřejné doručování bannerů je jinde.
use Reklamy\App;

/** @var App $app */
$app = require __DIR__ . '/lib/bootstrap.php';
if (!$app->nainstalovano() || !$app->relace()->jePrihlasen($app->limitNecinnosti(), true)) { http_response_code(403); exit; }
session_write_close();
try {
    $f = $app->galerie()->soubor((int)($_GET['id'] ?? 0));
} catch (RuntimeException) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $f['mime']);
header('Content-Length: ' . filesize($f['cesta']));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
readfile($f['cesta']);
