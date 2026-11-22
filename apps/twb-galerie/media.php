<?php
declare(strict_types=1);
// Soubor z galerie v administraci (jen přihlášený): ?id= soubor, &nahled=1 zmenšený náhled obrázku pro mřížku (480 px),
// &nahled=<px> náhled v dané velikosti (Media::NAHLEDY, např. 1920 pro okno galerie), &original=1 původní soubor
// před kompresí, &stahnout=1 stažení.
use MediaGalerie\{App, Media, Vydej};

/** @var App $app */
$app = require __DIR__ . '/lib/bootstrap.php';
if (!$app->nainstalovano() || !$app->relace()->jePrihlasen($app->limitNecinnosti(), true)) { http_response_code(403); exit; }
session_write_close();
$media = new Media($app);
try {
    $r = $media->radek((int)($_GET['id'] ?? 0));
    $original = !empty($_GET['original']) && !empty($r['orig_filename']);
    $px = (int)($_GET['nahled'] ?? 0);
    $px = $px === 1 ? Media::NAHLED_PX : (in_array($px, Media::NAHLEDY, true) ? $px : 0);
    $nahled = $px && !$original ? $media->varianta($r, $px) : null;
    if ($nahled !== null) {
        Vydej::posli($nahled, 'image/jpeg', pathinfo((string)$r['original_name'], PATHINFO_FILENAME) . '-nahled.jpg', 'n' . $px . $r['md5'], 'private, max-age=86400');
    }
    $f = $app->galerie()->soubor((int)$r['id'], $original);
} catch (RuntimeException) {
    http_response_code(404);
    exit;
}
Vydej::posli($f['cesta'], $f['mime'], $f['nazev'], ($original ? 'o' : '') . (string)($f['md5'] ?? md5_file($f['cesta'])), 'private, max-age=3600', !empty($_GET['stahnout']));
