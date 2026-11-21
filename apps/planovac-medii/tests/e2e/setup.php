<?php
// Příprava pro testy v prohlížeči: čistá databáze, heslo, cílová složka a testovací obrázky v TEST_TMP/fixtures.
require dirname(__DIR__) . '/lib.php';
set_setting('password_hash', password_hash('test-heslo-123', PASSWORD_DEFAULT));
set_setting('folder_id', 'test-slozka');
set_setting('folder_name', 'Testovací složka');
q('DELETE FROM sources'); // bez stahování z internetu

$dir = dirname(getenv('MP_CONFIG')) . '/fixtures';
@mkdir($dir, 0775, true);
$noise = function (int $w, int $h, bool $alpha) {
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false); imagesavealpha($im, true);
    mt_srand(7);
    for ($y = 0; $y < $h; $y += 4) for ($x = 0; $x < $w; $x += 4) {
        imagefilledrectangle($im, $x, $y, $x + 3, $y + 3, imagecolorallocatealpha($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), $alpha && $y < $h / 2 ? 127 : 0));
    }
    return $im;
};
// plakát: barevný přechod s jemným šumem (jako fotka) – JPG vyjde výrazně menší než PNG
$im = imagecreatetruecolor(2600, 1600);
for ($y = 0; $y < 1600; $y += 2) for ($x = 0; $x < 2600; $x += 2) {
    $n = mt_rand(-12, 12);
    imagefilledrectangle($im, $x, $y, $x + 1, $y + 1, imagecolorallocate($im, max(0, min(255, (int)($x / 11) + $n)), max(0, min(255, (int)($y / 7) + $n)), max(0, min(255, 180 + $n))));
}
imagepng($im, "$dir/plakat.png", 6);
imagepng($noise(600, 300, true), "$dir/logo.png", 9);
imagejpeg($noise(800, 450, false), "$dir/er_261024_01.jpg", 80);
imagejpeg($noise(800, 450, false), "$dir/er_261031_02.jpg", 80);
echo "OK\n";
