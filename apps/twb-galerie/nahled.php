<?php
declare(strict_types=1);
// Podepsaný náhled pro jiné aplikace: nahled.php/<id>/<px>/<platí do>/<podpis>/<název>. Adresy vydává API (pole nahledy)
// u médií bez veřejného odkazu; platí 1–2 hodiny a dají se vložit do <img> bez klíče. Bez platného podpisu 404.
use MediaGalerie\{App, Media, Vydej};

/** @var App $app */
$app = require __DIR__ . '/lib/bootstrap.php';
$nenalezeno = static function (): never {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit('Náhled nenalezen nebo jeho adresa vypršela.');
};
if (!preg_match('~^/(\d{1,10})/(\d{2,4})/(\d{10})/([0-9a-f]{32})(/|$)~', (string)($_SERVER['PATH_INFO'] ?? ''), $m)) $nenalezeno();
[, $id, $px, $do, $podpis] = $m;
try {
    if ((int)$app->nastaveni()->dej('schema_version', 0) !== MediaGalerie\Schema::verze()) $app->migruj();
    $media = new Media($app);
    if (!$media->overPodpis((int)$id, (int)$px, (int)$do, $podpis)) $nenalezeno();
    $r = $media->radek((int)$id);
    $soubor = $r['kind'] === 'image' ? ($media->varianta($r, (int)$px) ?? $app->data . '/media/' . basename((string)$r['filename'])) : null;
} catch (Throwable $e) {
    $nenalezeno();
}
if ($soubor === null || !is_file($soubor)) $nenalezeno();
$jpeg = str_ends_with($soubor, '.jpg') && str_contains($soubor, '/nahledy/');
Vydej::posli($soubor, $jpeg ? 'image/jpeg' : (string)$r['mime'], pathinfo((string)$r['original_name'], PATHINFO_FILENAME) . "-{$px}" . ($jpeg ? '.jpg' : '.' . pathinfo((string)$r['original_name'], PATHINFO_EXTENSION)),
    "w{$px}" . $r['md5'], 'public, max-age=' . max(60, (int)$do - time()), false,
    ['Access-Control-Allow-Origin' => '*', 'Cross-Origin-Resource-Policy' => 'cross-origin', 'X-Robots-Tag' => 'noindex']);
