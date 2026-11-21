<?php
// Zařízení pro přehrávání: klíče, now.php (seznam a soubory ze složky přehrávače), ochrana, API Nastavení → Zařízení.
require dirname(__DIR__) . '/lib.php';
require_once APP_DIR . '/lib/devices.php';

$B = getenv('BASE_URL');
$F = 'zarizeni-slozka';
set_setting('password_hash', password_hash('test-heslo-123', PASSWORD_DEFAULT));
set_setting('folder_id', $F);
q('DELETE FROM sources');
$dir = DATA_DIR . "/mock-drive/$F";
@mkdir($dir, 0775, true);
file_put_contents("$dir/plakat.jpg", 'JPEGDATA-' . str_repeat('x', 1000));
file_put_contents("$dir/reklama.mp4", 'MP4DATA');
file_put_contents("$dir/leták.pdf", '%PDF-1.4 test');
file_put_contents("$dir/otevírací doba.txt", "Po–Pá 6–18\n");
file_put_contents("$dir/web.url", "[InternetShortcut]\r\nURL=https://example.com\r\n");
file_put_contents("$dir/neznamy.xyz", 'nic');

function dget(string $url, array $headers = [], string $method = 'GET'): array {
    $ch = curl_init($url);
    $rh = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$rh) { if (str_contains($h, ':')) { [$k, $v] = explode(':', $h, 2); $rh[strtolower(trim($k))] = trim($v); } return strlen($h); }]);
    $body = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body, json_decode($body, true), $rh];
}

[$dev, $token] = device_create('Počítač v kanceláři', 'chrome', null);

test('vytvoření zařízení: klíč se ukládá jen jako SHA-256', function () use ($dev, $token) {
    ok(preg_match('/^dev_[0-9a-f]{32}$/', $token) === 1, $token);
    $row = q('SELECT * FROM devices WHERE id = ?', [$dev['id']])->fetch();
    eq(hash('sha256', $token), $row['token_hash']);
    ok(!str_contains(json_encode(q('SELECT * FROM devices')->fetchAll()), $token), 'klíč v databázi');
    eq($dev['id'], (int)device_auth($token)['id']);
    eq(null, device_auth('dev_spatny'));
    eq(null, device_auth(''));
    throws(fn() => device_create('  ', 'chrome', null), 'Zadejte název');
});

test('délka zobrazení: výchozí 20 s, rozsah 3–3600', function () {
    eq(20, device_default_duration());
    eq(null, device_valid_duration(''));
    eq(15, device_valid_duration('15'));
    throws(fn() => device_valid_duration(2), '3 až 3600');
    throws(fn() => device_valid_duration(3601), '3 až 3600');
    throws(fn() => device_valid_duration('1.5'), '3 až 3600');
});

test('now.php bez klíče a se špatným klíčem odmítne', function () use ($B) {
    [$code, , $j] = dget("$B/now.php");
    eq([403, false], [$code, $j['ok'] ?? null]);
    [$code] = dget("$B/now.php", ['X-Device-Key: dev_spatny']);
    eq(403, $code);
    set_setting('device_fails', null);
});

test('now.php: seznam souborů ve složce, druhy, délka, zařízení', function () use ($B, $token, $F) {
    [$code, $body, $j, $h] = dget("$B/now.php", ['X-Device-Key: ' . $token, 'X-Client: chrome/1.0.0']);
    eq(200, $code, $body);
    eq('no-store', $h['cache-control'] ?? null);
    eq('*', $h['access-control-allow-origin'] ?? null);
    eq(true, $j['ok']);
    eq(['id' => $j['device']['id'], 'name' => 'Počítač v kanceláři', 'kind' => 'chrome', 'duration' => 20], $j['device']);
    eq(5, $j['refresh_minutes']);
    $kinds = array_column($j['files'], 'kind', 'name');
    ksort($kinds);
    eq(['leták.pdf' => 'pdf', 'otevírací doba.txt' => 'text', 'plakat.jpg' => 'image', 'reklama.mp4' => 'video', 'web.url' => 'url'], $kinds);
    $jpg = array_values(array_filter($j['files'], fn($f) => $f['name'] === 'plakat.jpg'))[0];
    eq(['id' => "$F/plakat.jpg", 'mime' => 'image/jpeg', 'md5' => md5_file(DATA_DIR . "/mock-drive/$F/plakat.jpg"), 'size' => 1009],
       ['id' => $jpg['id'], 'mime' => $jpg['mime'], 'md5' => $jpg['md5'], 'size' => $jpg['size']]);
    ok(isset($j['playlist']['mode'], $j['playlist']['active'], $j['playlist']['fixed']), 'playlist');
    eq(false, $j['stale']);
    $row = q('SELECT last_seen, client FROM devices WHERE id = ?', [$j['device']['id']])->fetch();
    ok($row['last_seen'] !== null && abs(strtotime($row['last_seen']) - time()) < 30, 'last_seen');
    eq('chrome/1.0.0', $row['client']);
});

test('now.php: klíč i v parametru key, vlastní délka zařízení, název scénáře', function () use ($B, $token, $dev) {
    device_update($dev['id'], 'Počítač v kanceláři', 'chrome', 12);
    $m = make_media('a.jpg');
    make_scenario('Loterie', [$m]);
    [$code, , $j] = dget("$B/now.php?key=" . urlencode($token));
    eq(200, $code);
    eq(12, $j['device']['duration']);
    eq(['Loterie'], $j['playlist']['active']);
    device_update($dev['id'], 'Počítač v kanceláři', 'chrome', null);
});

test('now.php?file: obsah souboru jen ze složky přehrávače', function () use ($B, $token, $F) {
    [$code, $body, , $h] = dget("$B/now.php?file=" . rawurlencode("$F/plakat.jpg"), ['X-Device-Key: ' . $token]);
    eq(200, $code);
    eq(file_get_contents(DATA_DIR . "/mock-drive/$F/plakat.jpg"), $body);
    eq('image/jpeg', $h['content-type'] ?? null);
    eq('1009', $h['content-length'] ?? null);
    [$code, $body, , $h] = dget("$B/now.php?file=" . rawurlencode("$F/otevírací doba.txt"), ['X-Device-Key: ' . $token]);
    eq([200, "Po–Pá 6–18\n"], [$code, $body]);
    eq('text/plain; charset=utf-8', $h['content-type'] ?? null);
    // jiný soubor, cesta mimo složku, neznámý typ
    eq(404, dget("$B/now.php?file=" . rawurlencode("$F/../jina/tajne.jpg"), ['X-Device-Key: ' . $token])[0]);
    eq(404, dget("$B/now.php?file=" . rawurlencode("jina-slozka/plakat.jpg"), ['X-Device-Key: ' . $token])[0]);
    eq(404, dget("$B/now.php?file=" . rawurlencode("$F/neznamy.xyz"), ['X-Device-Key: ' . $token])[0]);
    eq(403, dget("$B/now.php?file=" . rawurlencode("$F/plakat.jpg"))[0]);
});

test('seznam se drží minutu, nový soubor jde stáhnout hned', function () use ($B, $token, $F) {
    dget("$B/now.php", ['X-Device-Key: ' . $token]);
    file_put_contents(DATA_DIR . "/mock-drive/$F/novy.png", 'PNGDATA');
    [, , $j] = dget("$B/now.php", ['X-Device-Key: ' . $token]);
    ok(!in_array('novy.png', array_column($j['files'], 'name'), true), 'v seznamu z mezipaměti ještě není');
    [$code, $body] = dget("$B/now.php?file=" . rawurlencode("$F/novy.png"), ['X-Device-Key: ' . $token]);
    eq([200, 'PNGDATA'], [$code, $body]);
    [, , $j] = dget("$B/now.php", ['X-Device-Key: ' . $token]);
    ok(in_array('novy.png', array_column($j['files'], 'name'), true), 'po obnovení v seznamu je');
});

test('CORS: předběžný dotaz OPTIONS bez klíče', function () use ($B) {
    [$code, , , $h] = dget("$B/now.php", ['Origin: chrome-extension://abc', 'Access-Control-Request-Headers: x-device-key'], 'OPTIONS');
    eq(204, $code);
    contains('X-Device-Key', $h['access-control-allow-headers'] ?? '');
});

test('nový klíč: starý přestane fungovat', function () use ($B, $dev, &$token) {
    $new = device_new_token($dev['id']);
    eq(403, dget("$B/now.php", ['X-Device-Key: ' . $token])[0]);
    eq(200, dget("$B/now.php", ['X-Device-Key: ' . $new])[0]);
    $token = $new;
    set_setting('device_fails', null);
});

test('po 20 špatných klíčích z jedné adresy blokace na 15 minut (přihlášení ne)', function () use ($B, $token) {
    for ($i = 0; $i < DEVICE_FAIL_LIMIT; $i++) dget("$B/now.php", ['X-Device-Key: dev_spatny' . $i]);
    [$code, , $j] = dget("$B/now.php", ['X-Device-Key: ' . $token]);
    eq(429, $code);
    contains('Příliš mnoho pokusů', $j['error']);
    setting('__reload');
    eq('0', (string)setting('login_fails', '0'), 'počítadlo přihlášení');
    ok((bool)q("SELECT 1 FROM logs WHERE message LIKE 'Zařízení: 20 špatných klíčů%'")->fetchColumn(), 'záznam v Protokolu');
    set_setting('device_fails', null);
    eq(200, dget("$B/now.php", ['X-Device-Key: ' . $token])[0]);
});

test('zálohy: tabulka zařízení se nezálohuje ani nepřepisuje', function () {
    ok(!in_array('devices', BACKUP_TABLES, true), 'devices v BACKUP_TABLES');
    ok(in_array('device_fails', BACKUP_LOCAL_SETTINGS, true) && in_array('device_files_cache', BACKUP_LOCAL_SETTINGS, true), 'místní nastavení');
});

// ---------- API Nastavení → Zařízení (přihlášený uživatel) ----------
$jar = TMP_DIR . '/cookies-dev.txt'; @unlink($jar);
function capi(string $a, ?array $body = null, string $csrf = ''): array {
    global $B, $jar;
    $ch = curl_init("$B/api.php?a=$a");
    $h = $body !== null ? ['Content-Type: application/json', "X-CSRF: $csrf"] : [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => $h]);
    if ($body !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)]);
    $out = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $out, json_decode($out, true)];
}
set_setting('login_fails', '0');
$ch = curl_init("$B/");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POSTFIELDS => http_build_query(['password' => 'test-heslo-123'])]);
curl_exec($ch); curl_close($ch);
$ch = curl_init("$B/");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
preg_match('/name="csrf" content="([^"]+)"/', (string)curl_exec($ch), $m); curl_close($ch);
$csrf = $m[1] ?? '';

test('API: seznam, přidání, úprava, nový klíč, odebrání', function () use ($csrf) {
    [$code, $body, $j] = capi('devices');
    eq(200, $code, $body);
    eq(20, $j['duration']);
    eq('1.2.0', $j['chrome_version']);
    eq(['chrome', 'android', 'tizen'], array_keys($j['kinds']));
    eq(405, capi('device_save')[0]);
    eq(403, capi('device_save', ['name' => 'X'], 'spatny')[0]);
    [$code, , $j] = capi('device_save', ['name' => 'Notebook doma', 'kind' => 'chrome', 'duration' => '15'], $csrf);
    eq(200, $code);
    ok(str_starts_with($j['token'], 'dev_'), 'klíč');
    eq([15, 'Notebook doma'], [$j['device']['duration'], $j['device']['name']]);
    $id = $j['device']['id'];
    eq('Délka zobrazení musí být 3 až 3600 sekund.', capi('device_save', ['id' => $id, 'name' => 'N', 'duration' => 1], $csrf)[2]['error']);
    [, , $j2] = capi('device_save', ['id' => $id, 'name' => 'Notebook', 'kind' => 'android', 'duration' => null], $csrf);
    eq(['Notebook', 'android', null], [$j2['device']['name'], $j2['device']['kind'], $j2['device']['duration']]);
    ok(!isset($j2['token']), 'úprava klíč nevrací');
    [, , $k] = capi('device_token', ['id' => $id], $csrf);
    ok($k['token'] !== $j['token'] && device_auth($k['token'])['id'] == $id, 'nový klíč');
    eq(null, device_auth($j['token']));
    eq(200, capi('device_delete', ['id' => $id], $csrf)[0]);
    eq(404, capi('device_delete', ['id' => $id], $csrf)[0]);
});

test('API: výchozí délka zobrazení', function () use ($csrf, $B, &$token) {
    eq('Délka zobrazení musí být 3 až 3600 sekund.', capi('device_settings', ['duration' => 'abc'], $csrf)[2]['error']);
    eq('Zadejte délku zobrazení.', capi('device_settings', ['duration' => ''], $csrf)[2]['error']);
    eq(30, capi('device_settings', ['duration' => 30], $csrf)[2]['duration']);
    eq(30, dget("$B/now.php", ['X-Device-Key: ' . $token])[2]['device']['duration']);
    set_setting('device_duration', null);
});

test('API: plugin pro Chrome ke stažení jako zip (složka je jinak zablokovaná)', function () use ($B) {
    global $jar;
    $ch = curl_init("$B/api.php?a=extension_zip");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_COOKIEFILE => $jar]);
    $zipData = (string)curl_exec($ch);
    eq('application/zip', curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    $f = TMP_DIR . '/plugin.zip';
    file_put_contents($f, $zipData);
    $z = new ZipArchive();
    eq(true, $z->open($f));
    $names = [];
    for ($i = 0; $i < $z->numFiles; $i++) $names[] = $z->getNameIndex($i);
    foreach (['manifest.json', 'bg.js', 'common.js', 'popup.html', 'player.html', 'options.html', 'lib/pdf.min.js', 'icons/icon-128.png'] as $need) {
        ok(in_array("twb-planovac-medii-chrome/$need", $names, true), "v zipu chybí $need");
    }
    eq('1.2.0', json_decode($z->getFromName('twb-planovac-medii-chrome/manifest.json'), true)['version']);
    $z->close();
    ok(!glob(DATA_DIR . '/tmp/twb-planovac-medii-chrome-*.zip'), 'dočasný zip zůstal na serveru');
    // bez přihlášení nic
    eq(401, dget("$B/api.php?a=extension_zip")[0]);
});

test('složka extensions je zablokovaná pravidlem .htaccess', function () {
    contains('Require all denied', (string)file_get_contents(APP_DIR . '/extensions/.htaccess'));
});
