<?php
declare(strict_types=1);
// Veřejný odkaz na soubor: soubor.php/<klíč>/<název>. Klíč je náhodný (Media::novyKlic), název jen pro čitelnost.
// Funguje bez přihlášení i pro archivovaná média; vypnutý nebo nový odkaz starý zneplatní. ?stahnout=1 = stáhnout.
use MediaGalerie\{App, Media, Vydej};

/** @var App $app */
$app = require __DIR__ . '/lib/bootstrap.php';
$cesta = (string)($_SERVER['PATH_INFO'] ?? '');
$klic = (string)($_GET['k'] ?? (preg_match('~^/([0-9a-f]{24})(/|$)~', $cesta, $m) ? $m[1] : ''));
$nenalezeno = static function (): never {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit('Soubor nenalezen.');
};
if ($klic === '') $nenalezeno();
try {
    if ((int)$app->nastaveni()->dej('schema_version', 0) !== MediaGalerie\Schema::verze()) $app->migruj();
    $r = (new Media($app))->podleKlice($klic);
} catch (Throwable $e) {
    $app->log('error', 'Veřejný odkaz: ' . $e->getMessage());
    $nenalezeno();
}
if (!$r) $nenalezeno();
$soubor = $app->data . '/media/' . basename((string)$r['filename']);
if (!is_file($soubor)) $nenalezeno();
Vydej::posli($soubor, (string)$r['mime'], (string)$r['original_name'], (string)$r['md5'], 'public, max-age=3600',
    !empty($_GET['stahnout']), ['Access-Control-Allow-Origin' => '*', 'Cross-Origin-Resource-Policy' => 'cross-origin', 'X-Robots-Tag' => 'noindex']);
