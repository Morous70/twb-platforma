<?php
// Ovládání zařízení z aplikace: hlášení stavu (now.php?ping), příkazy, propadnutí příkazu, změna složky, neozývá se, API.
require dirname(__DIR__) . '/lib.php';
require_once APP_DIR . '/lib/devices.php';

$B = getenv('BASE_URL');
$F = 'ovladani-slozka';
set_setting('password_hash', password_hash('test-heslo-123', PASSWORD_DEFAULT));
set_setting('login_fails', '0');
set_setting('folder_id', $F);
set_setting('device_fails', null);
@mkdir(DATA_DIR . "/mock-drive/$F", 0775, true);
file_put_contents(DATA_DIR . "/mock-drive/$F/a.jpg", 'JPEGDATA-a');

function cpost(string $url, array $headers, string $body, string $method = 'POST'): array {
    $ch = curl_init($url);
    $rh = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $method === 'POST' ? $body : null,
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$rh) { if (str_contains($h, ':')) { [$k, $v] = explode(':', $h, 2); $rh[strtolower(trim($k))] = trim($v); } return strlen($h); }]);
    $out = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode($out, true), $rh];
}
$row = fn(int $id) => q('SELECT * FROM devices WHERE id = ?', [$id])->fetch();

[$dev, $token] = device_create('Televize ve výloze', 'tizen', null);

test('stará verze přehrávače (bez hlášení stavu): ovládání nejde', function () use ($dev) {
    $o = device_out(device_get($dev['id']));
    eq([false, null, false], [$o['controllable'], $o['status'], $o['playing']]);
    throws(fn() => device_command($dev['id'], 'refresh'), 'starou verzi přehrávače');
});

test('očištění stavu: délky, čas „od“ z počtu sekund, nesmysly pryč', function () {
    $s = device_status_clean(['files' => '12', 'done' => -3, 'version' => '1.1.0<script>',
        'playing' => ['name' => "Zlatý\njackpot.jpg", 'kind' => 'image', 'age' => 120, 'paused' => 1],
        'error' => ['msg' => str_repeat('x', 500), 'age' => 30], 'navic' => 'nic']);
    eq([12, 0, '1.1.0<script>'], [$s['files'], $s['done'], $s['version']]);
    eq('Zlatý jackpot.jpg', $s['playing']['name']);
    ok(abs(strtotime($s['playing']['since']) - (time() - 120)) <= 2, 'od');
    ok($s['playing']['paused']);
    eq(300, mb_strlen($s['error']['msg']));
    ok(!isset($s['navic']));
    eq(['playing' => null, 'error' => null], array_intersect_key(device_status_clean('rozbité'), ['playing' => 1, 'error' => 1]));
    eq(null, device_status_clean(['playing' => ['name' => '  ']])['playing']);
});

test('now.php?ping: CORS pro POST, uloží stav, vrátí číslo změny a 15 s', function () use ($B, $token, $dev, $row) {
    [$code, , $h] = cpost("$B/now.php?ping=1", ['Origin: https://appassets.androidplatform.net', 'Access-Control-Request-Method: POST'], '', 'OPTIONS');
    eq(204, $code);
    ok(str_contains($h['access-control-allow-methods'] ?? '', 'POST') && str_contains($h['access-control-allow-headers'] ?? '', 'Content-Type'), json_encode($h));
    set_setting('last_change_at', '1700000000');
    [$code, $j] = cpost("$B/now.php?ping=1", ["X-Device-Key: $token", 'X-Client: tizen/1.1.0', 'Content-Type: application/json'],
        json_encode(['version' => '1.1.0', 'files' => 3, 'done' => 2, 'playing' => ['name' => 'a.jpg', 'kind' => 'image', 'age' => 5]]));
    eq(200, $code, json_encode($j));
    eq([true, null, 1700000000, 15], [$j['ok'], $j['cmd'], $j['change'], $j['ping_seconds']]);
    $o = device_out($row($dev['id']));
    eq([true, true, false, 'tizen/1.1.0'], [$o['controllable'], $o['playing'], $o['offline'], $o['client']]);
    eq(['a.jpg', 3, 2], [$o['status']['playing']['name'], $o['status']['files'], $o['status']['done']]);
    eq(403, cpost("$B/now.php?ping=1", ['X-Device-Key: dev_spatny'], '{}')[0]);
    set_setting('device_fails', null);
});

test('příkaz se vydá jen jednou, další příkaz má nové číslo, neznámý odmítne', function () use ($B, $token, $dev, $row) {
    $o = device_command($dev['id'], 'restart');
    eq('restart', $o['cmd']);
    $h = ["X-Device-Key: $token", 'X-Client: tizen/1.1.0', 'Content-Type: application/json'];
    [, $j] = cpost("$B/now.php?ping=1", $h, '{}');
    eq('restart', $j['cmd']['name']);
    $id1 = $j['cmd']['id'];
    eq(null, cpost("$B/now.php?ping=1", $h, '{}')[1]['cmd'], 'podruhé už ne');
    eq(null, device_out($row($dev['id']))['cmd']);
    device_command($dev['id'], 'next');
    [, $j] = cpost("$B/now.php?ping=1", $h, '{}');
    eq('next', $j['cmd']['name']);
    ok($j['cmd']['id'] > $id1, 'nové číslo');
    throws(fn() => device_command($dev['id'], 'smazat'), 'Neznámý příkaz');
    throws(fn() => device_command(99999, 'next'), 'nenalezeno');
});

test('příkaz, který si zařízení do 2 minut nevyzvedne, propadne', function () use ($B, $token, $dev, $row) {
    device_command($dev['id'], 'refresh');
    q('UPDATE devices SET cmd_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - DEVICE_CMD_TTL - 5), $dev['id']]);
    eq(null, device_out($row($dev['id']))['cmd']);
    eq(null, cpost("$B/now.php?ping=1", ["X-Device-Key: $token"], '{}')[1]['cmd']);
    eq(null, $row($dev['id'])['cmd'], 'smazán');
});

test('změna složky: seznam v paměti serveru se načte znovu a now.php vrátí číslo změny', function () use ($B, $token, $F) {
    set_setting('last_change_at', null);
    set_setting('device_files_cache', null);
    eq(['a.jpg'], array_column(device_folder_files()['files'], 'name'));
    file_put_contents(DATA_DIR . "/mock-drive/$F/b.jpg", 'JPEGDATA-b');
    eq(['a.jpg'], array_column(device_folder_files()['files'], 'name'), 'bez změny drží 60 s');
    $t = time() + 1;
    set_setting('last_change_at', (string)$t);
    eq(['a.jpg', 'b.jpg'], array_column(device_folder_files()['files'], 'name'), 'po změně hned');
    [$code, $j] = cpost("$B/now.php", ["X-Device-Key: $token"], '', 'GET');
    eq([200, $t, 15], [$code, $j['change'], $j['ping_seconds']]);
});

test('neozývá se: po 15 minutách v seznamu upozornění, po ozvání zmizí', function () use ($B, $token, $dev, $row) {
    [$d2] = device_create('Nikdy nepřipojené', 'chrome', null);
    q('UPDATE devices SET last_seen = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 16 * 60), $dev['id']]);
    eq(['Televize ve výloze'], array_column(devices_offline(), 'name'), 'nepřipojené se nehlásí');
    ok(device_out($row($dev['id']))['offline']);
    cpost("$B/now.php?ping=1", ["X-Device-Key: $token"], '{}');
    eq([], devices_offline());
    device_delete($d2['id']);
});

// ---------- HTTP: Nastavení → Zařízení ----------
$jar = TMP_DIR . '/cookies-control.txt'; @unlink($jar);
$ch = curl_init("$B/"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POSTFIELDS => http_build_query(['password' => 'test-heslo-123'])]); curl_exec($ch);
$ch = curl_init("$B/"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
preg_match('/name="csrf" content="([^"]+)"/', (string)curl_exec($ch), $m); $csrf = $m[1] ?? '';
function capi(string $a, ?array $body = null, string $csrf = ''): array {
    global $B, $jar;
    $ch = curl_init("$B/api.php?a=$a");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => $body !== null ? ["X-CSRF: $csrf", 'Content-Type: application/json'] : []]);
    if ($body !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)]);
    $out = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode($out, true)];
}

test('HTTP: device_cmd jen POST s CSRF, devices vrací stav a příkazy, devices_alert', function () use ($csrf, $dev) {
    [$code, $j] = capi('devices');
    eq(200, $code);
    eq(['refresh', 'restart', 'next'], array_keys($j['commands']));
    eq([15, 15], [$j['ping_seconds'], $j['offline_minutes']]);
    $d = array_values(array_filter($j['devices'], fn($x) => $x['id'] === $dev['id']))[0];
    ok($d['controllable'] && $d['status'] !== null, json_encode($d));
    eq(405, capi('device_cmd')[0]);
    eq(403, capi('device_cmd', ['id' => $dev['id'], 'cmd' => 'next'], 'spatny')[0]);
    [$code, $j] = capi('device_cmd', ['id' => $dev['id'], 'cmd' => 'next'], $csrf);
    eq([200, 'next'], [$code, $j['device']['cmd']], json_encode($j));
    eq(400, capi('device_cmd', ['id' => $dev['id'], 'cmd' => 'x'], $csrf)[0]);
    [$code, $j] = capi('devices_alert');
    eq([200, [], 15], [$code, $j['offline'], $j['minutes']]);
    device_delete($dev['id']);
});
