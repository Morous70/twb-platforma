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

/** JPEG s EXIF orientací (IFD0: 0x0112), XMP a ICC segmentem; obrazová data ze šumu. */
function jpegSMetadaty(string $cesta, int $orientace, int $w = 2400, int $h = 1600): void
{
    ob_start(); imagejpeg(sumovy($w, $h), null, 95); $jpg = (string)ob_get_clean();
    $tiff = "II*\0" . pack('V', 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, $orientace, 0) . pack('V', 0);
    $exif = "Exif\0\0" . $tiff;
    $xmp = "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta xmlns:x=\"adobe:ns:meta/\">Autor: Jana</x:xmpmeta>";
    $icc = "ICC_PROFILE\0\x01\x01" . str_repeat('p', 40);
    $seg = fn(int $m, string $d) => "\xFF" . chr($m) . pack('n', strlen($d) + 2) . $d;
    file_put_contents($cesta, "\xFF\xD8" . $seg(0xE1, $exif) . $seg(0xE1, $xmp) . $seg(0xE2, $icc) . substr($jpg, 2));
}

test('0.2.0: JPEG → JPEG přenese EXIF, XMP a ICC; orientace se nastaví na 1', function () use ($S, $TMP) {
    $p = "$TMP/meta.jpg"; jpegSMetadaty($p, 6);
    $r = Obrazky::komprimuj($p, ['metadata' => true] + $S, true);
    ok($r !== null);
    eq(["jpg", 720, 1080], [$r["ext"], $r["w"], $r["h"]], "otočeno podle EXIF a zmenšeno");
    ok(str_contains($r['data'], 'Autor: Jana'), 'XMP zůstal');
    ok(str_contains($r['data'], 'ICC_PROFILE'), 'barevný profil zůstal');
    $f = "$TMP/vysledek.jpg"; file_put_contents($f, $r['data']);
    eq(1, Obrazky::exifOrientace($f), 'orientace 1 – obrázek se znovu neotočí');
    ok(@imagecreatefromstring($r['data']) !== false, 'platný JPEG');
    eq([720, 1080], array_slice((array)getimagesize($f), 0, 2));
    $bez = Obrazky::komprimuj($p, $S, true);
    ok(!str_contains($bez['data'], 'Autor: Jana'), 'bez volby metadata se nepřenášejí (dosavadní chování)');
});

test('0.2.0: výstup WebP (i s průhledností), bez podpory se použije JPG', function () use ($S, $TMP) {
    if (!function_exists('imagewebp')) { ok(true); return; }
    $p = "$TMP/w.png"; ulozPng(sumovy(2400, 1600), $p);
    $r = Obrazky::komprimuj($p, ['format' => 'webp', 'quality' => 70] + $S, true);
    eq(['webp', 'image/webp', 1620, 1080], [$r['ext'], $r['mime'], $r['w'], $r['h']]);
    eq('RIFF', substr($r['data'], 0, 4));
    $p = "$TMP/wa.png"; ulozPng(sumovy(600, 400, true), $p);
    $r = Obrazky::komprimuj($p, ['format' => 'webp', 'quality' => 80] + $S, true);
    $im = imagecreatefromstring($r['data']);
    ok(Obrazky::maPruhlednost($im), 'průhlednost zůstala');
});

test('0.2.0: vložení metadat do JPEG bez APP0 i s ním; jiný soubor beze změny', function () use ($TMP) {
    $seg = "\xFF\xE1\x00\x06Exif";
    eq("\xFF\xD8" . $seg . "\xFF\xDB", Obrazky::vlozMetadataJpeg("\xFF\xD8\xFF\xDB", $seg));
    eq("\xFF\xD8\xFF\xE0\x00\x04ab" . $seg . "\xFF\xDB", Obrazky::vlozMetadataJpeg("\xFF\xD8\xFF\xE0\x00\x04ab\xFF\xDB", $seg));
    eq('PNG', Obrazky::vlozMetadataJpeg('PNG', $seg));
    file_put_contents("$TMP/x.png", 'neni jpeg');
    eq('', Obrazky::metadataJpeg("$TMP/x.png"));
});

// ---------- 0.3.0: úprava (otočení, překlopení, ořez) ----------

/** Obrázek se čtyřmi barevnými kvadranty: vlevo nahoře červená, vpravo nahoře zelená, vlevo dole modrá, vpravo dole bílá. */
function kvadranty(int $w, int $h): GdImage
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, intdiv($w, 2) - 1, intdiv($h, 2) - 1, (int)imagecolorallocate($im, 255, 0, 0));
    imagefilledrectangle($im, intdiv($w, 2), 0, $w - 1, intdiv($h, 2) - 1, (int)imagecolorallocate($im, 0, 255, 0));
    imagefilledrectangle($im, 0, intdiv($h, 2), intdiv($w, 2) - 1, $h - 1, (int)imagecolorallocate($im, 0, 0, 255));
    imagefilledrectangle($im, intdiv($w, 2), intdiv($h, 2), $w - 1, $h - 1, (int)imagecolorallocate($im, 255, 255, 255));
    return $im;
}
/** Převažující barva pixelu: r, g, b, w (bílá) nebo ? */
function barva(GdImage $im, int $x, int $y): string
{
    $c = imagecolorat($im, $x, $y);
    [$r, $g, $b] = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
    if ($r > 200 && $g > 200 && $b > 200) return 'w';
    if ($r > 200 && $g < 60 && $b < 60) return 'r';
    if ($g > 200 && $r < 60 && $b < 60) return 'g';
    if ($b > 200 && $r < 60 && $g < 60) return 'b';
    return '?';
}
/** Barvy rohů výsledku: [levý horní, pravý horní, levý dolní, pravý dolní]. */
function rohy(string $data): array
{
    $im = imagecreatefromstring($data);
    $w = imagesx($im); $h = imagesy($im);
    return [barva($im, 2, 2), barva($im, $w - 3, 2), barva($im, 2, $h - 3), barva($im, $w - 3, $h - 3)];
}
$U = fn(array $p) => Obrazky::upravaZFormulare($p);

test('0.3.0: úprava z formuláře – otočení, překlopení, výřez, poměr; neplatné hodnoty', function () use ($U) {
    eq(['otoceni' => 0, 'h' => false, 'v' => false, 'orez' => null, 'pomer' => null], $U([]));
    ok(Obrazky::upravaPrazdna($U([])));
    eq(270, $U(['otoceni' => '-90'])['otoceni']);
    eq(90, $U(['otoceni' => '450'])['otoceni']);
    throws(fn() => $U(['otoceni' => '45']), '90°');
    // obojí překlopení = otočení o 180°
    $u = $U(['otoceni' => '90', 'preklopit_h' => '1', 'preklopit_v' => '1']);
    eq([270, false, false], [$u['otoceni'], $u['h'], $u['v']]);
    eq([0.1, 0.2, 0.5, 0.5], $U(['orez_x' => '0.1', 'orez_y' => '0.2', 'orez_w' => '0.5', 'orez_h' => '0.5'])['orez']);
    eq(null, $U(['orez_x' => '0', 'orez_y' => '0', 'orez_w' => '1', 'orez_h' => '1'])['orez'], 'celý obrázek = bez ořezu');
    eq([0.0, 0.0, 1.0, 0.5], $U(['orez_x' => '-1', 'orez_y' => '0', 'orez_w' => '3', 'orez_h' => '0.5'])['orez'], 'hodnoty mimo 0–1 se omezí');
    throws(fn() => $U(['orez_x' => 'a', 'orez_y' => '0', 'orez_w' => '1', 'orez_h' => '1']), 'výřez');
    throws(fn() => $U(['orez_x' => '0', 'orez_y' => '0', 'orez_w' => '0', 'orez_h' => '1']), 'prázdný');
    eq([9, 16], $U(['pomer' => '9:16'])['pomer'], 'prohozený poměr');
    eq([0.0, 0.0, 1.0, 1.0], $U(['pomer' => '16:9'])['orez'], 'poměr bez výřezu = největší výřez');
    throws(fn() => $U(['pomer' => '5:4']), 'Neznámý');
    throws(fn() => $U(['pomer' => 'x']), 'Neplatný');
    eq('otočeno o 90° doprava, překlopeno vodorovně, oříznuto 16:9 (1920×1080 px)', Obrazky::popisUpravy(['otoceni' => 90, 'h' => true, 'v' => false, 'orez' => [0, 0, 1, 1], 'pomer' => [16, 9]], 1920, 1080));
    eq('otočeno o 90° doleva', Obrazky::popisUpravy($U(['otoceni' => 270])));
    eq(['1:1', '16:9', '4:3', '3:2', '2:1'], array_keys(Obrazky::POMERY));
});

test('0.3.0: výřez v pixelech – přesný poměr stran, uvnitř obrázku', function () {
    eq(['x' => 100, 'y' => 50, 'width' => 200, 'height' => 100], Obrazky::vyrez(400, 200, [0.25, 0.25, 0.5, 0.5], null));
    // 16:9 z celého obrázku 1000×1000 → 992×558 (násobky 16:9), uprostřed
    eq(['x' => 4, 'y' => 221, 'width' => 992, 'height' => 558], Obrazky::vyrez(1000, 1000, [0, 0, 1, 1], [16, 9]));
    eq(['x' => 0, 'y' => 0, 'width' => 1920, 'height' => 1080], Obrazky::vyrez(1920, 1080, [0, 0, 1, 1], [16, 9]));
    $r = Obrazky::vyrez(1000, 800, [0, 0, 1, 1], [9, 16]);
    eq([450, 800], [$r['width'], $r['height']]);
    $r = Obrazky::vyrez(1000, 800, [0.9, 0.9, 0.1, 0.1], [1, 1]);
    eq(true, $r['x'] + $r['width'] <= 1000 && $r['y'] + $r['height'] <= 800 && $r['width'] === $r['height']);
    eq(['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1], Obrazky::vyrez(10, 10, [0, 0, 0.0001, 0.0001], null), 'aspoň 1 px');
});

test('0.3.0: otočení a překlopení posunou kvadranty správně; JPEG zůstane JPEG', function () use ($TMP, $U) {
    imagepng(kvadranty(200, 100), "$TMP/k.png");
    imagejpeg(kvadranty(200, 100), "$TMP/k.jpg", 95);
    $v = Obrazky::uprav("$TMP/k.png", $U(['otoceni' => '90']));
    eq([100, 200, 200, 100, 'png'], [$v['w'], $v['h'], $v['ow'], $v['oh'], $v['ext']]);
    eq(['b', 'r', 'w', 'g'], rohy($v['data']), 'doprava: levý dolní roh nahoru');
    eq(['g', 'w', 'r', 'b'], rohy(Obrazky::uprav("$TMP/k.png", $U(['otoceni' => '270']))['data']), 'doleva');
    eq(['w', 'b', 'g', 'r'], rohy(Obrazky::uprav("$TMP/k.png", $U(['otoceni' => '180']))['data']));
    eq(['g', 'r', 'w', 'b'], rohy(Obrazky::uprav("$TMP/k.png", $U(['preklopit_h' => '1']))['data']), 'vodorovně');
    eq(['b', 'w', 'r', 'g'], rohy(Obrazky::uprav("$TMP/k.png", $U(['preklopit_v' => '1']))['data']), 'svisle');
    // nejdřív otočení, pak překlopení (stejně jako náhled v prohlížeči)
    eq(['r', 'b', 'g', 'w'], rohy(Obrazky::uprav("$TMP/k.png", $U(['otoceni' => '90', 'preklopit_h' => '1']))['data']));
    $j = Obrazky::uprav("$TMP/k.jpg", $U(['otoceni' => '90']));
    eq(['jpg', 'image/jpeg', 100, 200], [$j['ext'], $j['mime'], $j['w'], $j['h']]);
    eq(['b', 'r', 'w', 'g'], rohy($j['data']));
});

test('0.3.0: ořez vybere správnou část; s otočením se výřez bere z otočeného obrázku', function () use ($TMP, $U) {
    imagepng(kvadranty(200, 100), "$TMP/k.png");
    $v = Obrazky::uprav("$TMP/k.png", $U(['orez_x' => '0.5', 'orez_y' => '0', 'orez_w' => '0.5', 'orez_h' => '0.5']));
    eq([100, 50], [$v['w'], $v['h']]);
    eq(['g', 'g', 'g', 'g'], rohy($v['data']), 'pravý horní kvadrant');
    // po otočení doprava je vlevo nahoře modrá (100×200 → horní polovina = modrá a červená)
    $v = Obrazky::uprav("$TMP/k.png", $U(['otoceni' => '90', 'orez_x' => '0', 'orez_y' => '0', 'orez_w' => '0.5', 'orez_h' => '0.5']));
    eq([50, 100], [$v['w'], $v['h']]);
    eq(['b', 'b', 'b', 'b'], rohy($v['data']));
    $v = Obrazky::uprav("$TMP/k.png", $U(['pomer' => '1:1']));
    eq([100, 100], [$v['w'], $v['h']], '1:1 z 200×100 uprostřed');
    eq(['r', 'g', 'b', 'w'], rohy($v['data']));
    $v = Obrazky::uprav("$TMP/k.png", $U(['pomer' => '9:16']));
    eq([54, 96], [$v['w'], $v['h']], 'prohozený poměr na výšku');
});

test('0.3.0: průhlednost PNG zůstane, EXIF orientace se použije před úpravou a metadata JPEG zůstanou s orientací 1', function () use ($TMP, $U) {
    $im = imagecreatetruecolor(100, 50);
    imagealphablending($im, false); imagesavealpha($im, true);
    imagefill($im, 0, 0, (int)imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagefilledrectangle($im, 50, 0, 99, 49, (int)imagecolorallocatealpha($im, 255, 0, 0, 0));
    imagepng($im, "$TMP/a.png");
    $v = Obrazky::uprav("$TMP/a.png", $U(['otoceni' => '90']));
    $o = imagecreatefromstring($v['data']);
    eq([50, 100], [imagesx($o), imagesy($o)]);
    eq(127, (imagecolorat($o, 10, 10) >> 24) & 0x7F, 'horní polovina (dřív vlevo) průhledná');
    eq(0, (imagecolorat($o, 10, 90) >> 24) & 0x7F);
    // JPEG s EXIF orientací 6: nejdřív se narovná (200×100 → 100×200), pak teprve úprava
    $jpg = (string)(function () { ob_start(); imagejpeg(kvadranty(200, 100), null, 95); return ob_get_clean(); })();
    $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00" . "\x00\x00\x00\x00";
    $app1 = "Exif\0\0" . $tiff;
    file_put_contents("$TMP/e6.jpg", substr($jpg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpg, 2));
    $v = Obrazky::uprav("$TMP/e6.jpg", $U(['preklopit_h' => '1']));
    eq([100, 200, 100, 200], [$v['w'], $v['h'], $v['ow'], $v['oh']]);
    eq(['r', 'b', 'g', 'w'], rohy($v['data']), 'EXIF 6 (doprava) + vodorovně');
    file_put_contents("$TMP/vysledek.jpg", $v['data']);
    eq(1, Obrazky::exifOrientace("$TMP/vysledek.jpg"), 'orientace v metadatech 1');
    ok(str_contains($v['data'], "Exif\0\0"), 'EXIF zůstal');
});

test('0.3.0: animovaný GIF a ne-obrázek upravit nejde; GIF a BMP se uloží jako PNG', function () use ($TMP, $U) {
    file_put_contents("$TMP/x.txt", 'text');
    throws(fn() => Obrazky::uprav("$TMP/x.txt", $U(['otoceni' => '90'])), 'není obrázek');
    $g = imagecreate(20, 10); imagecolorallocate($g, 255, 0, 0); imagegif($g, "$TMP/g.gif");
    eq(['png', 'image/png', 10, 20], array_values(array_intersect_key(Obrazky::uprav("$TMP/g.gif", $U(['otoceni' => '90'])), array_flip(['ext', 'mime', 'w', 'h']))));
    $snimek = "\x21\xF9\x04\x00\x0A\x00\x00\x00" . "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00";
    file_put_contents("$TMP/anim.gif", "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF" . $snimek . $snimek . "\x3B");
    throws(fn() => Obrazky::uprav("$TMP/anim.gif", $U(['otoceni' => '90'])), 'Animovaný GIF');
    if (function_exists('imagebmp')) {
        imagebmp(kvadranty(20, 10), "$TMP/b.bmp");
        eq('png', Obrazky::uprav("$TMP/b.bmp", $U(['otoceni' => '90']))['ext']);
    }
});
