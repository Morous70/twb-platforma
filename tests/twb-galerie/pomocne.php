<?php
// Pomůcky pro testy aplikace TWB Media galerie (spouštějte přes: php tests/run.php twb-galerie).
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';
require KOREN . '/apps/twb-galerie/lib/autoload.php';

use MediaGalerie\App;

/** Dočasné složky testů se po skončení smažou. */
function docasna(string $predpona): string
{
    static $slozky = null;
    if ($slozky === null) {
        $slozky = [];
        register_shutdown_function(function () use (&$slozky) {
            foreach ($slozky as $d) if (is_dir($d) || is_file($d)) exec('rm -rf ' . escapeshellarg($d));
        });
    }
    $d = sys_get_temp_dir() . '/twb-galerie-' . $predpona . '-' . bin2hex(random_bytes(4));
    $slozky[] = $d;
    return $d;
}

/** Nová aplikace s prázdnou databází v dočasné složce. @param array<string, mixed> $cfg */
function novaApp(array $cfg = []): App
{
    $dir = docasna('app');
    @mkdir($dir, 0775, true);
    $app = new App(KOREN . '/apps/twb-galerie', $cfg + ['data_dir' => $dir]);
    $app->migruj();
    return $app;
}

/** Soubor MP4 (hlavička ftyp + výplň), který finfo pozná jako video/mp4 – náhodná data by občas poznal jako jiný typ. */
function mp4(int $n): string { return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x07", max(0, $n - 24)); }
