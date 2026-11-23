<?php
declare(strict_types=1);
// Vložení odkazem (e-maily, stránky bez JavaScriptu): <a href=".../d/klik.php?zona=ID&n=X"><img src=".../d/obr.php?zona=ID&n=X"></a>.
// Vybere obrázkový banner, zapamatuje si ho pod n (proklik pak vede na stejný banner) a pošle obrázek.
use Reklamy\{App, Vydej, Bannery};

/** @var App $app */
$app = require dirname(__DIR__) . '/lib/bootstrap.php';
Vydej::bezCache();
try {
    $v = new Vydej($app);
    $z = $v->zona($_GET['zona'] ?? '');
    if ($z) {
        $k = $v->kontext($_GET, (int)$z['id']);
        $b = $app->dorucovani()->vyber($z, $k, Bannery::OBRAZKOVE, Vydej::sirka($_GET['sirka'] ?? ''));
        if ($b) {
            $n = is_string($_GET['n'] ?? null) && $_GET['n'] !== '' ? mb_substr($_GET['n'], 0, 64) : '';
            if ($n !== '') $app->dorucovani()->zapamatuj($n, (int)$z['id'], (int)$b['id'], $k->ted(), (int)$b['campaign_id']);
            $app->dorucovani()->zobrazeni((int)$b['id'], (int)$z['id'], $k, (int)$b['campaign_id']);
            Vydej::ulozCookies($k);
            if ($b['type'] === 'external') {
                header('Location: ' . $b['image_url'], true, 302);
                exit;
            }
            $f = $b['type'] === 'slide' ? ['cesta' => $app->slideBannery()->soubor($b), 'mime' => 'image/jpeg'] : $app->galerie()->soubor((int)$b['media_id']);
            header('Content-Type: ' . $f['mime']);
            header('Content-Length: ' . filesize($f['cesta']));
            readfile($f['cesta']);
            exit;
        }
    }
} catch (Throwable $e) {
    if (isset($v)) $v->chyba($e, 'obr.php');
}
header('Content-Type: image/gif');
echo Vydej::GIF;
