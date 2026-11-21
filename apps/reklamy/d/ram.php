<?php
declare(strict_types=1);
// Vložení rámem: <iframe src=".../d/ram.php?zona=ID" width=… height=…></iframe>. Používá ho i vyskakovací okno.
use Reklamy\{App, Vydej};

/** @var App $app */
$app = require dirname(__DIR__) . '/lib/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');
Vydej::bezCache();
$obsah = '';
$cil = '_blank';
try {
    $v = new Vydej($app);
    $z = $v->zona($_GET['zona'] ?? '');
    if ($z) {
        $k = $v->kontext($_GET, (int)$z['id']);
        $b = $app->dorucovani()->vyber($z, $k);
        if ($b) {
            $app->dorucovani()->zobrazeni((int)$b['id'], (int)$z['id'], $k);
            Vydej::ulozCookies($k);
            $cil = $b['target'] === '_self' ? '_self' : ($b['target'] ?: '_blank');
            // HTML banner je přímo obsahem rámu (bez dalšího vnořeného rámu)
            if ($b['type'] === 'html') { header('Content-Security-Policy: ' . Vydej::SANDBOX); echo $app->vykresleni()->dokumentHtml($b, (int)$z['id']); exit; }
            $obsah = $app->vykresleni()->html($b, (int)$z['id']);
        } else {
            $obsah = (string)($z['fallback'] ?? '');
        }
    }
} catch (Throwable $e) {
    if (isset($v)) $v->chyba($e, 'ram.php');
}
echo "<!doctype html>\n<html lang=\"cs\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><meta name=\"robots\" content=\"noindex\">"
    . '<base target="' . htmlspecialchars($cil, ENT_QUOTES) . '"><title>Reklama</title><style>html,body{margin:0;padding:0;overflow:hidden;background:transparent}</style></head><body>'
    . $obsah . "</body></html>\n";
