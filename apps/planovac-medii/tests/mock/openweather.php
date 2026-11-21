<?php
// Zkušební náhrada OpenWeather pro testy (běží jen pod `php -S` v tests/run.php; na serveru je složka tests/ zablokovaná).
//   /data/2.5/weather?q=…&appid=…   aktuální počasí      /data/2.5/forecast?q=…   předpověď po 3 hodinách
//   /img/wn/<kód>@4x.png             ikona
// Ovládání ze souborů v data/tmp: ow-mock-down (výpadek 503), ow-mock-temp (aktuální teplota), ow-mock-log (záznam dotazů).
if (PHP_SAPI !== 'cli-server') { http_response_code(403); exit; }
$tmp = dirname(__DIR__, 2) . '/data/tmp';
$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('~^.*/openweather\.php~', '', $path);
@file_put_contents("$tmp/ow-mock-log", date('H:i:s') . ' ' . $path . ' ' . ($_GET['q'] ?? '') . "\n", FILE_APPEND);

if (preg_match('~^/img/wn/([0-9a-z]{2,4})@4x\.png$~', $path, $m)) {
    $im = imagecreatetruecolor(200, 200);
    imagealphablending($im, false); imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    $h = crc32($m[1]);
    imagefilledellipse($im, 100, 100, 110, 110, imagecolorallocate($im, 80 + $h % 170, 80 + ($h >> 8) % 170, 80 + ($h >> 16) % 170));
    header('Content-Type: image/png');
    imagepng($im);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
$err = function (int $code, string $msg) { http_response_code($code); echo json_encode(['cod' => (string)$code, 'message' => $msg]); exit; };
if (is_file("$tmp/ow-mock-down")) $err(503, 'Service Unavailable');
if (($_GET['appid'] ?? '') === 'spatny-klic-123') $err(401, 'Invalid API key. Please see https://openweathermap.org/faq#error401 for more info.');
$q = (string)($_GET['q'] ?? '');
$city = trim(explode(',', $q)[0]);
if ($city === '' || mb_strtolower($city) === 'neznámo') $err(404, 'city not found');
$tz = (int)date('Z');
$base = mb_strtolower($city) === 'brno' ? 2.0 : 0.0;
$temp = is_file("$tmp/ow-mock-temp") ? (float)file_get_contents("$tmp/ow-mock-temp") : 12.4 + $base;

if ($path === '/data/2.5/weather') {
    echo json_encode(['name' => $city, 'dt' => time(), 'timezone' => $tz,
        'weather' => [['id' => 804, 'main' => 'Clouds', 'description' => 'zataženo', 'icon' => '04d']],
        'main' => ['temp' => $temp, 'feels_like' => $temp - 1.8, 'humidity' => 71], 'wind' => ['speed' => 3.6]], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($path === '/data/2.5/forecast') {
    $list = [];
    $start = intdiv(time(), 10800) * 10800 + 10800;
    $icons = ['10d', '01d', '13d', '02d', '03d', '09d'];
    $descs = ['slabý déšť', 'jasno', 'sněžení', 'skoro jasno', 'polojasno', 'přeháňky'];
    for ($i = 0; $i < 40; $i++) {
        $dt = $start + $i * 10800;
        $day = (int)floor((($dt + $tz) - (intdiv(time() + $tz, 86400) * 86400)) / 86400);
        $hour = (int)gmdate('G', $dt + $tz);
        $t = (15 - $day + $base) - abs($hour - 14) * 0.6;
        $list[] = ['dt' => $dt, 'main' => ['temp' => $t, 'temp_min' => $t - 0.5, 'temp_max' => $t + 0.5],
            'weather' => [['description' => $hour >= 9 && $hour <= 18 ? $descs[$day % 6] : 'jasná noc', 'icon' => $hour >= 9 && $hour <= 18 ? $icons[$day % 6] : '01n']]];
    }
    echo json_encode(['city' => ['name' => $city, 'timezone' => $tz], 'list' => $list], JSON_UNESCAPED_UNICODE);
    exit;
}
$err(404, 'Internal error');
