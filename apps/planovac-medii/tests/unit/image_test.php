<?php
// Komprese obrázků (verze 1.0.0): zmenšení, PNG → JPG, průhlednost, uchovaný originál, obnovení.
require dirname(__DIR__) . '/lib.php';

/** Obrázek s šumem (aby se špatně komprimoval). */
function noise_image(int $w, int $h, bool $alpha = false) {
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false); imagesavealpha($im, true);
    mt_srand(42);
    for ($y = 0; $y < $h; $y += 2) for ($x = 0; $x < $w; $x += 2) {
        $col = imagecolorallocatealpha($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), $alpha && $x < $w / 4 ? 127 : 0);
        imagefilledrectangle($im, $x, $y, $x + 1, $y + 1, $col);
    }
    return $im;
}
function save_png($im, string $path): void { imagepng($im, $path, 0); imagedestroy($im); }
$S = ['max_w' => 1920, 'max_h' => 1080, 'quality' => 85, 'min_kb' => 1024, 'auto' => true];

test('velké neprůhledné PNG → menší JPG v limitu 1920×1080', function () use ($S) {
    $p = TMP_DIR . '/big.png'; save_png(noise_image(3000, 2000), $p);
    $r = img_compress($p, $S);
    ok($r !== null, 'má se zkomprimovat');
    eq(['jpg', 'image/jpeg', 1620, 1080, 3000, 2000], [$r['ext'], $r['mime'], $r['w'], $r['h'], $r['ow'], $r['oh']]);
    ok(strlen($r['data']) < filesize($p), 'výsledek je menší');
});

test('průhledné PNG zůstane PNG (logo)', function () use ($S) {
    $p = TMP_DIR . '/logo.png'; save_png(noise_image(2400, 1200, true), $p);
    $r = img_compress($p, $S);
    eq('png', $r['ext'] ?? null);
    eq([1920, 960], [$r['w'], $r['h']]);
});

test('malý obrázek v limitu se nemění, s „force“ ano', function () use ($S) {
    $p = TMP_DIR . '/small.png'; save_png(noise_image(400, 300), $p);
    ok(filesize($p) < 1024 * 1024, 'testovací soubor je pod prahem');
    eq(null, img_compress($p, $S));
    $r = img_compress($p, $S, true);
    eq([400, 300, 'jpg'], [$r['w'] ?? null, $r['h'] ?? null, $r['ext'] ?? null]);
});

test('nepodporovaný soubor se ignoruje', function () use ($S) {
    file_put_contents(TMP_DIR . '/x.txt', 'není obrázek');
    eq(null, img_compress(TMP_DIR . '/x.txt', $S));
});

test('médium: komprese uchová originál, obnovení ho vrátí', function () {
    $file = 'orig' . bin2hex(random_bytes(4)) . '.png';
    save_png(noise_image(2600, 1600), MEDIA_DIR . "/$file");
    $data = file_get_contents(MEDIA_DIR . "/$file");
    $id = make_media('plakat.png', 'image', ['filename' => $file, 'mime' => 'image/png', 'data' => $data]);
    q('UPDATE media SET size = ? WHERE id = ?', [strlen($data), $id]);
    $m = fn() => q('SELECT * FROM media WHERE id = ?', [$id])->fetch();
    contains('2600×1600 → 1755×1080', (string)media_compress($m(), false));
    $c = $m();
    eq(['plakat.jpg', 'image/jpeg', $file, 2600], [$c['original_name'], $c['mime'], $c['orig_filename'], (int)$c['orig_w']]);
    ok(is_file(MEDIA_DIR . '/' . $c['filename']) && is_file(MEDIA_DIR . "/$file"), 'oba soubory existují');
    ok(media_restore($c), 'obnovení');
    $r = $m();
    eq(['plakat.png', $file, null], [$r['original_name'], $r['filename'], $r['orig_filename']]);
    ok(!is_file(MEDIA_DIR . '/' . $c['filename']), 'zmenšená verze smazána');
});

test('název s jinou příponou', function () {
    eq('plakát.jpg', name_with_ext('plakát.PNG', 'jpg'));
    eq('bez-pripony.jpg', name_with_ext('bez-pripony', 'jpg'));
});
