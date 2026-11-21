<?php
// Pomůcky pro testy aplikace TWB Reklamy (spouštějte přes: php tests/run.php reklamy).
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';
require KOREN . '/apps/reklamy/lib/autoload.php';

use Reklamy\App;

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
    $d = sys_get_temp_dir() . '/twb-reklamy-' . $predpona . '-' . bin2hex(random_bytes(4));
    $slozky[] = $d;
    return $d;
}

/** Nová aplikace s prázdnou databází v dočasné složce. @param array<string, mixed> $cfg */
function novaApp(array $cfg = []): App
{
    $dir = docasna('app');
    @mkdir($dir, 0775, true);
    $app = new App(KOREN . '/apps/reklamy', $cfg + ['data_dir' => $dir]);
    $app->migruj();
    return $app;
}

/** Obrázek PNG daného rozměru v dočasném souboru. */
function pngSoubor(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 80, 200));
    $f = docasna('png');
    imagepng($im, $f);
    return $f;
}

/** Kampaň s výchozími hodnotami. @param array<string, mixed> $v */
function kampan(App $app, array $v = []): int { return $app->kampane()->uloz($v + ['name' => 'Kampaň ' . bin2hex(random_bytes(2))]); }

/** HTML banner daného rozměru. */
function htmlBanner(App $app, int $kampan, int $w, int $h, array $v = []): int
{
    return $app->bannery()->uloz($v + ['campaign_id' => $kampan, 'type' => 'html', 'name' => "Banner {$w}x{$h}", 'width' => $w, 'height' => $h, 'html' => '<b>Ahoj</b>']);
}
