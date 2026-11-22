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
