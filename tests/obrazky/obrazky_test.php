<?php
// Obrázky: zmenšení, PNG → JPG, průhlednost, EXIF, animovaný GIF (přeneseno z testů Plánovače médií 1.0.0).
require dirname(__DIR__) . '/lib.php';

use TWB\Obrazky\Obrazky;

$TMP = sys_get_temp_dir() . '/twb-obr-' . bin2hex(random_bytes(3));
mkdir($TMP);

/** Obrázek s šumem (aby se špatně komprimoval). */
function sumovy(int $w, int $h, bool $alfa = false): GdImage
{
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    mt_srand(42);
    for ($y = 0; $y < $h; $y += 2) for ($x = 0; $x < $w; $x += 2) {
        $col = imagecolorallocatealpha($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), $alfa && $x < $w / 4 ? 127 : 0);
        imagefilledrectangle($im, $x, $y, $x + 1, $y + 1, $col);
    }
    return $im;
}
function ulozPng(GdImage $im, string $cesta): void { imagepng($im, $cesta, 0); }
$S = ['max_w' => 1920, 'max_h' => 1080, 'quality' => 85, 'min_kb' => 1024, 'auto' => true];

test('velké neprůhledné PNG → menší JPG v limitu 1920×1080', function () use ($S, $TMP) {
    $p = "$TMP/big.png"; ulozPng(sumovy(3000, 2000), $p);
    $r = Obrazky::komprimuj($p, $S);
    ok($r !== null, 'má se zkomprimovat');
    eq(['jpg', 'image/jpeg', 1620, 1080, 3000, 2000], [$r['ext'], $r['mime'], $r['w'], $r['h'], $r['ow'], $r['oh']]);
    ok(strlen($r['data']) < filesize($p), 'výsledek je menší');
});

test('průhledné PNG zůstane PNG (logo)', function () use ($S, $TMP) {
    $p = "$TMP/logo.png"; ulozPng(sumovy(2400, 1200, true), $p);
    $r = Obrazky::komprimuj($p, $S);
    eq('png', $r['ext'] ?? null);
    eq([1920, 960], [$r['w'], $r['h']]);
});

test('malý obrázek v limitu se nemění, s „vynutit“ ano', function () use ($S, $TMP) {
    $p = "$TMP/small.png"; ulozPng(sumovy(400, 300), $p);
    ok(filesize($p) < 1024 * 1024, 'testovací soubor je pod prahem');
    eq(null, Obrazky::komprimuj($p, $S));
    $r = Obrazky::komprimuj($p, $S, true);
    eq([400, 300, 'jpg'], [$r['w'] ?? null, $r['h'] ?? null, $r['ext'] ?? null]);
});

test('nepodporovaný soubor se ignoruje', function () use ($S, $TMP) {
    file_put_contents("$TMP/x.txt", 'není obrázek');
    eq(null, Obrazky::komprimuj("$TMP/x.txt", $S));
    eq(null, Obrazky::komprimuj("$TMP/neexistuje.png", $S));
});

test('animovaný GIF (i jen dva snímky) se nekomprimuje; jednosnímkový, poškozený a chybějící soubor ne', function () use ($S, $TMP) {
    // dva snímky: hlavička s 2barevnou paletou + 2× (řízení grafiky + popis snímku + data) + konec
    $snimek = "\x21\xF9\x04\x00\x0A\x00\x00\x00" . "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00";
    $hlavicka = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF";
    file_put_contents("$TMP/anim2.gif", $hlavicka . $snimek . $snimek . "\x3B");
    ok(Obrazky::jeAnimovanyGif("$TMP/anim2.gif"), 'dva snímky');
    file_put_contents("$TMP/anim3.gif", $hlavicka . $snimek . $snimek . $snimek . "\x3B");
    ok(Obrazky::jeAnimovanyGif("$TMP/anim3.gif"), 'tři snímky');
    file_put_contents("$TMP/jeden-rucne.gif", $hlavicka . $snimek . "\x3B");
    ok(!Obrazky::jeAnimovanyGif("$TMP/jeden-rucne.gif"), 'jeden snímek');
    $im = imagecreatetruecolor(10, 10); imagegif($im, "$TMP/jeden.gif");
    ok(!Obrazky::jeAnimovanyGif("$TMP/jeden.gif"), 'jeden snímek z GD');
    file_put_contents("$TMP/rozbity.gif", "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF\x99\x99");
    ok(!Obrazky::jeAnimovanyGif("$TMP/rozbity.gif"), 'poškozený');
    file_put_contents("$TMP/neni.gif", 'neni gif');
    ok(!Obrazky::jeAnimovanyGif("$TMP/neni.gif"), 'není GIF');
    ok(!Obrazky::jeAnimovanyGif("$TMP/neexistuje.gif"), 'chybějící soubor');
    eq(null, Obrazky::komprimuj("$TMP/anim2.gif", $S, true), 'animace se při komprimaci nechá být');
});

test('EXIF: orientace 6 otočí rozměry; bez EXIF je 1', function () use ($S, $TMP) {
    $im = sumovy(600, 300);
    imagejpeg($im, "$TMP/bez.jpg", 90);
    eq(1, Obrazky::exifOrientace("$TMP/bez.jpg"));
    // vložit APP1 s orientací 6 (big endian) hned za SOI
    $jpg = (string)file_get_contents("$TMP/bez.jpg");
    $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00" . "\x00\x00\x00\x00";
    $app1 = "Exif\0\0" . $tiff;
    $seg = "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1;
    file_put_contents("$TMP/exif6.jpg", substr($jpg, 0, 2) . $seg . substr($jpg, 2));
    eq(6, Obrazky::exifOrientace("$TMP/exif6.jpg"));
    $r = Obrazky::komprimuj("$TMP/exif6.jpg", $S);
    ok($r !== null, 'otočená fotka se uloží i když není větší');
    eq([300, 600], [$r['ow'], $r['oh']], 'rozměry po otočení');
});

test('průhlednost: PNG s alfa kanálem vs. bez něj', function () use ($TMP) {
    ulozPng(sumovy(100, 100, true), "$TMP/a.png");
    ulozPng(sumovy(100, 100, false), "$TMP/b.png");
    ok(Obrazky::muzeMitPruhlednost("$TMP/a.png", IMAGETYPE_PNG));
    ok(Obrazky::maPruhlednost(imagecreatefrompng("$TMP/a.png")), 'má průhledný pixel');
    ok(!Obrazky::maPruhlednost(imagecreatefrompng("$TMP/b.png")), 'plné barvy');
    ok(!Obrazky::muzeMitPruhlednost("$TMP/b.png", IMAGETYPE_JPEG), 'JPG nikdy');
});

test('nastavení: výchozí hodnoty a meze', function () {
    $h = novyHost();
    eq(['auto' => true, 'max_w' => 1920, 'max_h' => 1080, 'quality' => 85, 'min_kb' => 1024], Obrazky::nastaveni($h->nastaveni));
    $h->nastaveni->uloz('img_max_w', 10);
    $h->nastaveni->uloz('img_quality', 5);
    $h->nastaveni->uloz('img_auto', '0');
    $s = Obrazky::nastaveni($h->nastaveni);
    eq([320, 30, false], [$s['max_w'], $s['quality'], $s['auto']]);
});

test('název s jinou příponou a velikost česky', function () {
    eq('plakát.jpg', Obrazky::nazevSPriponou('plakát.PNG', 'jpg'));
    eq('bez-pripony.jpg', Obrazky::nazevSPriponou('bez-pripony', 'jpg'));
    eq('obrazek.png', Obrazky::nazevSPriponou('.', 'png'));
    eq('1,5 MB', Obrazky::velikost(1572864));
    eq('230 kB', Obrazky::velikost(235520));
    eq('1 kB', Obrazky::velikost(10));
});
