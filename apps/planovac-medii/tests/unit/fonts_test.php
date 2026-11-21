<?php
// Vlastní písma pro slidy: nahrání, kontrola, české znaky, použití ve slidu, přejmenování, odebrání, zálohy, HTTP.
require dirname(__DIR__) . '/lib.php';
require_once APP_DIR . '/lib/fonts.php';

$FIX = APP_DIR . '/tests/fixtures';
$tmpCopy = function (string $src) { $t = TMP_DIR . '/up-' . bin2hex(random_bytes(3)); copy($src, $t); return $t; };

test('nahrání TTF: v seznamu písem, soubor v data/fonts, vykreslí se', function () use ($tmpCopy) {
    [$f, $missing] = font_upload($tmpCopy(FONT_DIR . '/Anton-700.ttf'), 'Moje-Anton_Bold.ttf');
    eq('Moje Anton Bold', $f['name']);
    eq([], $missing);
    ok(str_starts_with($f['key'], 'u_') && $f['custom'], 'klíč');
    ok(is_file(CUSTOM_FONT_DIR . '/' . fonts_custom()[$f['key']]['file']), 'soubor');
    eq('api.php?a=font&key=' . $f['key'], $f['files'][700]);
    ok(font_exists($f['key']));
    eq(CUSTOM_FONT_DIR . '/' . fonts_custom()[$f['key']]['file'], font_file($f['key'], 400));
    $d = sanitize_design(['elements' => [['type' => 'text', 'id' => 'a', 'text' => 'Ahoj', 'font' => $f['key']]]]);
    eq($f['key'], $d['elements'][0]['font'], 'písmo projde očištěním šablony');
    ok(strlen(render_slide($d, new DateTimeImmutable())) > 5000, 'vykreslení');
});

test('písmo bez českých znaků se nahraje s upozorněním', function () use ($tmpCopy, $FIX) {
    [$f, $missing] = font_upload($tmpCopy("$FIX/ascii-jen.ttf"), 'ascii.ttf', 'Jen ASCII');
    eq('Jen ASCII', $f['name']);
    ok(count($missing) >= 20 && in_array('ř', $missing, true), 'chybí: ' . implode('', $missing));
    eq([], font_missing_chars(FONT_DIR . '/TitanOne-900.ttf'), 'Titan One má vše');
});

test('odmítne jiný soubor, prázdný, velký a poškozený', function () use ($tmpCopy) {
    throws(fn() => font_upload($tmpCopy(FONT_DIR . '/Anton-700.ttf'), 'pismo.woff'), 'TTF nebo OTF');
    $t = TMP_DIR . '/prazdne.ttf'; file_put_contents($t, '');
    throws(fn() => font_upload($t, 'a.ttf'), 'prázdný');
    $t = TMP_DIR . '/text.ttf'; file_put_contents($t, str_repeat('neni pismo ', 100));
    throws(fn() => font_upload($t, 'a.ttf'), 'není písmo');
    $t = TMP_DIR . '/velke.ttf'; file_put_contents($t, "\x00\x01\x00\x00" . str_repeat('x', FONT_MAX_BYTES));
    throws(fn() => font_upload($t, 'a.ttf'), '5 MB');
    $t = TMP_DIR . '/rozbite.ttf'; file_put_contents($t, "\x00\x01\x00\x00" . str_repeat("\x00", 3000));
    throws(fn() => font_upload($t, 'a.ttf'), 'nepodařilo načíst');
});

test('použité písmo nejde odebrat, přejmenování, odebrání nepoužitého', function () use ($tmpCopy) {
    [$f] = font_upload($tmpCopy(FONT_DIR . '/Anton-700.ttf'), 'Pouzite.ttf');
    $design = sanitize_design(['elements' => [['type' => 'text', 'id' => 'a', 'text' => 'X', 'font' => $f['key']]]]);
    q("INSERT INTO media (filename, original_name, mime, kind, size, md5, content, created_at) VALUES ('', 'Akce.jpg', 'image/jpeg', 'slide', 0, '', ?, ?)",
        [json_encode(['design' => $design]), date('Y-m-d H:i:s')]);
    eq(['Akce.jpg'], font_usage($f['key']));
    throws(fn() => font_delete($f['key']), 'použité ve slidu „Akce.jpg“');
    eq('Nové jméno', font_rename($f['key'], ' Nové jméno ')['name']);
    throws(fn() => font_rename($f['key'], ''), 'Zadejte název');
    q("DELETE FROM media WHERE original_name = 'Akce.jpg'");
    $file = CUSTOM_FONT_DIR . '/' . fonts_custom()[$f['key']]['file'];
    font_delete($f['key']);
    ok(!is_file($file) && !font_exists($f['key']), 'smazáno');
    throws(fn() => font_delete('u_neni'), 'nenalezeno');
});

test('zálohy obsahují vlastní písma a obnovení je vrátí', function () use ($tmpCopy) {
    set_setting('backup_password', 'heslo-zaloh-123');
    [$f] = font_upload($tmpCopy(FONT_DIR . '/Anton-700.ttf'), 'Zalohovane.ttf');
    $file = fonts_custom()[$f['key']]['file'];
    $b = backup_create('test-pisma');
    [$zip, $db] = backup_open(backup_dir() . '/' . $b['name'], 'heslo-zaloh-123');
    ok(isset($db['font_files'][$file]), 'v database.json');
    ok($zip->locateName("fonts/$file") !== false, 'soubor v zipu');
    $zip->close();
    unlink(CUSTOM_FONT_DIR . "/$file");
    backup_restore(backup_dir() . '/' . $b['name'], 'heslo-zaloh-123');
    ok(is_file(CUSTOM_FONT_DIR . "/$file"), 'písmo obnoveno');
    setting('__reload');
    ok(font_exists($f['key']) || isset(fonts_custom()[$f['key']]), 'v seznamu po obnovení');
});

// ---------- HTTP ----------
$B = getenv('BASE_URL');
set_setting('password_hash', password_hash('test-heslo-123', PASSWORD_DEFAULT));
set_setting('login_fails', '0');
$jar = TMP_DIR . '/cookies-fonts.txt'; @unlink($jar);
$ch = curl_init("$B/"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POSTFIELDS => http_build_query(['password' => 'test-heslo-123'])]); curl_exec($ch);
$ch = curl_init("$B/"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
preg_match('/name="csrf" content="([^"]+)"/', (string)curl_exec($ch), $m); $csrf = $m[1] ?? '';
function fapi(string $a, $body = null, string $csrf = '', bool $raw = false) {
    global $B, $jar;
    $ch = curl_init("$B/api.php?a=$a");
    $h = $body !== null ? ["X-CSRF: $csrf"] : [];
    if (is_array($body) && !isset($body['file'])) { $h[] = 'Content-Type: application/json'; $body = json_encode($body); }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => $h]);
    if ($body !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]);
    $out = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $raw ? $out : json_decode($out, true), curl_getinfo($ch, CURLINFO_CONTENT_TYPE)];
}

test('HTTP: seznam, nahrání, soubor písma jen přihlášeným, grafické styly', function () use ($csrf, $B) {
    [$code, $j] = fapi('fonts_custom');
    eq(200, $code);
    ok(count(array_filter($j['fonts'], fn($f) => !$f['custom'])) === count(FONTS), 'vestavěná');
    eq(403, fapi('font_upload', ['file' => new CURLFile(FONT_DIR . '/Anton-700.ttf', 'font/ttf', 'x.ttf')], 'spatny')[0]);
    [$code, $j] = fapi('font_upload', ['file' => new CURLFile(FONT_DIR . '/Anton-700.ttf', 'font/ttf', 'Muj font.ttf')], $csrf);
    eq(200, $code, json_encode($j));
    eq('Muj font', $j['font']['name']);
    $key = $j['font']['key'];
    [$code, $data, $ct] = fapi("font&key=$key", null, '', true);
    eq([200, 'font/ttf', filesize(FONT_DIR . '/Anton-700.ttf')], [$code, $ct, strlen($data)]);
    eq(404, fapi('font&key=roboto')[0], 'vestavěná přes api ne');
    $ch = curl_init("$B/api.php?a=font&key=$key"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '']); curl_exec($ch);
    eq(401, curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'bez přihlášení');
    ok(in_array($key, array_column(fapi('fonts')[1], 'key'), true), 'v nabídce editoru');
    [, $fx] = fapi('fx_styles');
    eq(11, count($fx['styles']));
    eq([0, 0.38, 0.62, 1], $fx['stops']);
    eq(200, fapi('font_delete', ['key' => $key], $csrf)[0]);
});
