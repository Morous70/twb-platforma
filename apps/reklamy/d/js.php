<?php
declare(strict_types=1);
// Klasické vložení JavaScriptem: <script src=".../d/js.php?zona=ID"></script> zapíše banner do stránky (document.write).
use Reklamy\{App, Vydej};

/** @var App $app */
$app = require dirname(__DIR__) . '/lib/bootstrap.php';
header('Content-Type: application/javascript; charset=utf-8');
Vydej::bezCache();
$html = '';
try {
    $v = new Vydej($app);
    $z = $v->zona($_GET['zona'] ?? '');
    if ($z) {
        $k = $v->kontext($_GET, (int)$z['id']);
        $html = $v->proZonu($z, $k, [], Vydej::sirka($_GET['sirka'] ?? ''))['html'];
        Vydej::ulozCookies($k);
    }
} catch (Throwable $e) {
    if (isset($v)) $v->chyba($e, 'js.php');
}
if ($html !== '') echo 'document.write(' . json_encode($html, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ");\n";
