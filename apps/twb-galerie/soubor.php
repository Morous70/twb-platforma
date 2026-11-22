<?php
declare(strict_types=1);
// Veřejný odkaz na soubor: soubor.php/<klíč>/<název>, náhled soubor.php/<klíč>/w<px>/<název>. Klíč je náhodný (Media::novyKlic), název jen pro čitelnost.
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
// varianta náhledu: soubor.php/<klíč>/w<px>/<název> (jen obrázky a velikosti z Media::NAHLEDY; menší obrázek = původní soubor)
$px = preg_match('~^/[0-9a-f]{24}/w(\d{2,4})(/|$)~', $cesta, $mw) && in_array((int)$mw[1], Media::NAHLEDY, true) ? (int)$mw[1] : null;
$soubor = $app->data . '/media/' . basename((string)$r['filename']);
$mime = (string)$r['mime'];
$nazev = (string)$r['original_name'];
if ($px !== null && $r['kind'] === 'image') {
    $v = (new Media($app))->varianta($r, $px);
    if ($v !== null) { $soubor = $v; $mime = 'image/jpeg'; $nazev = pathinfo($nazev, PATHINFO_FILENAME) . "-{$px}.jpg"; }
}
if (!is_file($soubor)) $nenalezeno();
// s ?v=<začátek md5> se adresa po nahrazení souboru změní, takže smí být v mezipaměti napořád
$v = (string)($_GET['v'] ?? '');
$cache = $v !== '' && strlen($v) >= 8 && str_starts_with((string)$r['md5'], $v) ? 'public, max-age=31536000, immutable' : 'public, max-age=3600';
Vydej::posli($soubor, $mime, $nazev, ($px !== null ? "w{$px}" : '') . (string)$r['md5'], $cache,
    !empty($_GET['stahnout']), ['Access-Control-Allow-Origin' => '*', 'Cross-Origin-Resource-Policy' => 'cross-origin', 'X-Robots-Tag' => 'noindex']);
