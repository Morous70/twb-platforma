<?php
// Párování televize kódem (pair.php, device_pair) a balíček aplikace pro TV Samsung.
require dirname(__DIR__) . '/lib.php';
require_once APP_DIR . '/lib/devices.php';

$B = getenv('BASE_URL');
set_setting('password_hash', password_hash('test-heslo-123', PASSWORD_DEFAULT));
set_setting('folder_id', 'parovani-slozka');
q('DELETE FROM sources');

function pget(string $url, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_HTTPHEADER => $headers]);
    $body = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body, json_decode($body, true)];
}

test('pair.php start: šestimístný kód a tajný token; v databázi jen otisky', function () use ($B) {
    [$code, $body, $j] = pget("$B/pair.php?a=start");
    eq(200, $code, $body);
    ok(preg_match('/^\d{6}$/', $j['code']) === 1, $j['code']);
    ok(strlen($j['poll']) === 48, 'token');
    eq([600, 3], [$j['expires_in'], $j['interval']]);
    $row = q('SELECT * FROM device_pairing')->fetch();
    eq(hash('sha256', $j['code']), $row['code_hash']);
    eq(hash('sha256', $j['poll']), $row['poll_hash']);
    eq(null, $row['device_id']);
    [, , $p] = pget("$B/pair.php?a=poll&t=" . $j['poll']);
    eq('waiting', $p['status']);
    eq('expired', pget("$B/pair.php?a=poll&t=neexistuje")[2]['status']);
    eq(400, pget("$B/pair.php?a=poll")[0]);
});

test('spárování: špatný kód odmítne, správný vytvoří televizi a klíč se vydá jen jednou', function () use ($B) {
    [, , $j] = pget("$B/pair.php?a=start");
    throws(fn() => pair_confirm('000000' === $j['code'] ? '111111' : '000000', 'TV', null), 'Kód neplatí');
    $dev = pair_confirm(substr($j['code'], 0, 3) . ' ' . substr($j['code'], 3), 'Televize u pokladny', 15);
    eq(['Televize u pokladny', 'tizen', 15], [$dev['name'], $dev['kind'], $dev['duration']]);
    eq(null, setting('pair_fails'), 'počítadlo chyb po úspěchu vynulované');
    throws(fn() => pair_confirm($j['code'], 'Znovu', null), 'Kód neplatí');   // kód už je použitý
    [, , $p] = pget("$B/pair.php?a=poll&t=" . $j['poll']);
    eq('paired', $p['status']);
    ok(preg_match('/^dev_[0-9a-f]{32}$/', $p['key']) === 1, 'klíč');
    eq(['id' => $dev['id'], 'name' => 'Televize u pokladny'], $p['device']);
    eq('expired', pget("$B/pair.php?a=poll&t=" . $j['poll'])[2]['status'], 'podruhé už klíč nedostane');
    eq(0, (int)q('SELECT COUNT(*) FROM device_pairing WHERE device_id IS NOT NULL')->fetchColumn(), 'klíč v databázi nezůstal');
    [$code, , $n] = pget("$B/now.php", ['X-Device-Key: ' . $p['key']]);
    eq([200, 'Televize u pokladny', 15], [$code, $n['device']['name'], $n['device']['duration']]);
});

test('kód po 10 minutách propadne', function () use ($B) {
    [, , $j] = pget("$B/pair.php?a=start");
    q('UPDATE device_pairing SET expires = ? WHERE poll_hash = ?', [time() - 1, hash('sha256', $j['poll'])]);
    throws(fn() => pair_confirm($j['code'], 'TV', null), 'Kód neplatí');
    eq('expired', pget("$B/pair.php?a=poll&t=" . $j['poll'])[2]['status']);
    set_setting('pair_fails', null);
});

test('po 5 špatných kódech je párování 15 minut zablokované', function () use ($B) {
    [, , $j] = pget("$B/pair.php?a=start");
    $bad = $j['code'] === '999999' ? '999998' : '999999';
    for ($i = 0; $i < PAIR_CODE_FAILS; $i++) { try { pair_confirm($bad, 'TV', null); } catch (InvalidArgumentException $e) {} }
    throws(fn() => pair_confirm($j['code'], 'TV', null), 'Příliš mnoho špatných kódů');
    ok((bool)q("SELECT 1 FROM logs WHERE message LIKE 'Párování televize: 5 špatných kódů%'")->fetchColumn(), 'Protokol');
    set_setting('pair_fails', json_encode([PAIR_CODE_FAILS, time() - PAIR_CODE_LOCK - 1]));
    eq('tizen', pair_confirm($j['code'], 'TV po blokaci', null)['kind'], 'po 15 minutách jde spárovat');
});

test('nejvýš 10 žádostí o kód za hodinu z jedné adresy', function () use ($B) {
    set_setting('pair_starts', null);
    for ($i = 0; $i < PAIR_START_LIMIT; $i++) eq(200, pget("$B/pair.php?a=start")[0]);
    [$code, , $j] = pget("$B/pair.php?a=start");
    eq(429, $code);
    contains('Zkuste to za hodinu', $j['error']);
    set_setting('pair_starts', null);
});

test('odebrání zařízení smaže i nevyzvednuté párování', function () use ($B) {
    [, , $j] = pget("$B/pair.php?a=start");
    $dev = pair_confirm($j['code'], 'Smazat', null);
    device_delete($dev['id']);
    eq('expired', pget("$B/pair.php?a=poll&t=" . $j['poll'])[2]['status']);
});

// ---------- přihlášený uživatel ----------
$jar = TMP_DIR . '/cookies-pair.txt'; @unlink($jar);
function papi(string $a, ?array $body = null, string $csrf = '', bool $raw = false) {
    global $B, $jar;
    $ch = curl_init("$B/api.php?a=$a");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => $body !== null ? ['Content-Type: application/json', "X-CSRF: $csrf"] : []]);
    if ($body !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)]);
    $out = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $raw ? $out : json_decode($out, true)];
}
set_setting('login_fails', '0');
$ch = curl_init("$B/");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POSTFIELDS => http_build_query(['password' => 'test-heslo-123'])]);
curl_exec($ch); curl_close($ch);
$ch = curl_init("$B/");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
preg_match('/name="csrf" content="([^"]+)"/', (string)curl_exec($ch), $m); curl_close($ch);
$csrf = $m[1] ?? '';

test('API device_pair: jen POST s CSRF, chyby česky', function () use ($B, $csrf) {
    eq(405, papi('device_pair')[0]);
    eq(403, papi('device_pair', ['code' => '1'], 'spatny')[0]);
    [, , $j] = pget("$B/pair.php?a=start");
    [$code, $r] = papi('device_pair', ['code' => $j['code'], 'name' => '', 'duration' => null], $csrf);
    eq([400, 'Zadejte název zařízení.'], [$code, $r['error']]);
    [$code, $r] = papi('device_pair', ['code' => $j['code'], 'name' => 'TV kancelář', 'duration' => '25'], $csrf);
    eq(200, $code);
    eq(['TV kancelář', 'tizen', 'TV Samsung', 25], [$r['device']['name'], $r['device']['kind'], $r['device']['kind_label'], $r['device']['duration']]);
    eq('paired', pget("$B/pair.php?a=poll&t=" . $j['poll'])[2]['status']);
});

test('API: aplikace pro TV Samsung ke stažení s předvyplněnou adresou', function () use ($B) {
    [, $j] = papi('devices');
    eq('1.2.0', $j['tizen_version']);
    [$code, $zipData] = papi('extension_zip&kind=tizen', null, '', true);
    eq(200, $code);
    $f = TMP_DIR . '/tizen.zip';
    file_put_contents($f, $zipData);
    $z = new ZipArchive();
    eq(true, $z->open($f));
    $root = 'twb-planovac-medii-tizen/';
    foreach (['config.xml', 'index.html', 'app.js', 'style.css', 'icon.png', '.project', '.tproject', 'lib/pdf.min.js'] as $need) {
        ok($z->locateName($root . $need) !== false, "v zipu chybí $need");
    }
    contains('"base":"' . $B . '"', (string)$z->getFromName($root . 'config.js'));
    contains('version="1.2.0"', (string)$z->getFromName($root . 'config.xml'));
    $z->close();
    // původní soubor ve složce extensions zůstal s prázdnou adresou
    contains("base: ''", (string)file_get_contents(APP_DIR . '/extensions/tizen/config.js'));
});

test('párování Android TV: druh zařízení podle žádosti o kód', function () use ($B) {
    set_setting('pair_starts', null);
    [, , $j] = pget("$B/pair.php?a=start&kind=android");
    eq('android', q('SELECT kind FROM device_pairing WHERE poll_hash = ?', [hash('sha256', $j['poll'])])->fetchColumn());
    eq('android', pair_confirm($j['code'], 'Android TV', null)['kind']);
    [, , $j2] = pget("$B/pair.php?a=start&kind=neco");
    eq('tizen', pair_confirm($j2['code'], 'TV', null)['kind'], 'neznámý druh = TV Samsung');
});

test('API: aplikace pro Android TV ke stažení (projekt pro GitHub s přehrávačem z TV Samsung)', function () use ($B) {
    [, $j] = papi('devices');
    eq('1.2.0', $j['android_version']);
    [$code, $zipData] = papi('extension_zip&kind=android', null, '', true);
    eq(200, $code);
    $f = TMP_DIR . '/android.zip';
    file_put_contents($f, $zipData);
    $z = new ZipArchive();
    eq(true, $z->open($f));
    $root = 'twb-prehravac-android/';
    foreach (['settings.gradle', 'build.gradle', 'gradlew', 'gradle/wrapper/gradle-wrapper.jar', 'gradle/wrapper/gradle-wrapper.properties', '.github/workflows/build.yml', '.gitignore',
              'app/build.gradle', 'app/twb-prehravac.jks', 'app/src/main/AndroidManifest.xml', 'app/src/main/java/cz/trafikabr/prehravac/MainActivity.java',
              'app/src/main/res/drawable-xhdpi/banner.png', 'app/src/main/res/mipmap-xxxhdpi/ic_launcher.png',
              'app/src/main/assets/www/index.html', 'app/src/main/assets/www/app.js', 'app/src/main/assets/www/style.css', 'app/src/main/assets/www/lib/pdf.min.js'] as $need) {
        ok($z->locateName($root . $need) !== false, "v zipu chybí $need");
    }
    foreach (['app/src/main/assets/www/README.txt', 'app/src/main/assets/www/config.xml', 'app/src/main/assets/www/.tproject'] as $not) ok($z->locateName($root . $not) === false, "v zipu nemá být $not");
    contains('"base":"' . $B . '"', (string)$z->getFromName($root . 'app/src/main/assets/www/config.js'));
    eq(file_get_contents(APP_DIR . '/extensions/tizen/app.js'), $z->getFromName($root . 'app/src/main/assets/www/app.js'), 'přehrávač je stejný jako pro TV Samsung');
    $man = (string)$z->getFromName($root . 'app/src/main/AndroidManifest.xml');
    ok(simplexml_load_string($man) !== false, 'AndroidManifest.xml je platné XML');
    contains('android.intent.category.LEANBACK_LAUNCHER', $man);
    contains('@drawable/banner', $man);
    foreach (['app/src/main/res/values/strings.xml', 'app/src/main/res/values/styles.xml'] as $x) ok(simplexml_load_string((string)$z->getFromName($root . $x)) !== false, "$x je platné XML");
    contains("versionName '1.2.0'", (string)$z->getFromName($root . 'app/build.gradle'));
    $z->close();
});

test('zálohy párování neobsahují', function () {
    ok(!in_array('device_pairing', BACKUP_TABLES, true), 'device_pairing v zálohách');
    ok(in_array('pair_starts', BACKUP_LOCAL_SETTINGS, true) && in_array('pair_fails', BACKUP_LOCAL_SETTINGS, true), 'místní nastavení');
});
