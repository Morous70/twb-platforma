<?php
// Webové vstupy přes HTTP: přihlášení, ochrana API, klíče pro cron a Synology, chráněné složky.
require dirname(__DIR__) . '/lib.php';

$B = getenv('BASE_URL');
set_setting('password_hash', password_hash('test-heslo-123', PASSWORD_DEFAULT));
set_setting('player_key', 'klic-synology');
set_setting('folder_id', 'http-slozka');
q('DELETE FROM sources'); // cron by jinak stahoval z internetu

$jar = TMP_DIR . '/cookies.txt'; @unlink($jar);
function http(string $method, string $url, array $o = []): array {
    global $jar;
    $ch = curl_init($url);
    $h = $o['headers'] ?? [];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '',
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => $h]);
    if (isset($o['form'])) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($o['form']));
    if (isset($o['json'])) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($o['json'])); }
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$code, $body, json_decode($body, true)];
}

test('API bez přihlášení odmítne', function () use ($B) {
    [$code, , $j] = http('GET', "$B/api.php?a=status");
    eq([401, 'Nejste přihlášeni.'], [$code, $j['error'] ?? null]);
});

test('přihlášení špatným a správným heslem', function () use ($B) {
    contains('Nesprávné heslo', http('POST', "$B/", ['form' => ['password' => 'spatne']])[1]);
    [$code] = http('POST', "$B/", ['form' => ['password' => 'test-heslo-123']]);
    eq(302, $code);
    contains('name="csrf"', http('GET', "$B/")[1]);
});

$csrf = function () use ($B) { preg_match('/name="csrf" content="([^"]+)"/', http('GET', "$B/")[1], $m); return $m[1] ?? ''; };

test('čtecí akce API odpovídají JSONem bez chyby', function () use ($B) {
    foreach (['status', 'media', 'tags', 'scenarios', 'settings', 'sources', 'backups', 'logs', 'data_list', 'fonts', 'diag', 'preview'] as $a) {
        [$code, $body, $j] = http('GET', "$B/api.php?a=$a");
        eq(200, $code, "$a: " . mb_substr($body, 0, 200));
        ok(is_array($j) && !isset($j['error']), "$a vrací chybu: " . mb_substr($body, 0, 200));
    }
    eq(404, http('GET', "$B/api.php?a=neexistuje")[0]);
});

test('zápis bez CSRF tokenu odmítne, s tokenem projde', function () use ($B, $csrf) {
    q("INSERT INTO media (filename, original_name, mime, kind, size, md5, created_at) VALUES ('', 'csrf.jpg', 'image/jpeg', 'image', 1, 'aa', ?)", [date('Y-m-d H:i:s')]);
    $id = (int)db()->lastInsertId();
    eq(403, http('POST', "$B/api.php?a=media_tags", ['json' => ['ids' => [$id], 'add' => ['X']]])[0]);
    [$code, $body] = http('POST', "$B/api.php?a=media_tags", ['json' => ['ids' => [$id], 'add' => ['Testovací']], 'headers' => ['X-CSRF: ' . $csrf()]]);
    eq(200, $code, $body);
    eq('["Testovací"]', q('SELECT tags FROM media WHERE id = ?', [$id])->fetchColumn());
    eq(404, http('POST', "$B/api.php?a=folder_save", ['json' => ['name' => 'X'], 'headers' => ['X-CSRF: ' . $csrf()]])[0], 'složky už nejsou');
});

test('signal.php: klíč, zápis času a potvrzení od Synology', function () use ($B) {
    eq(403, http('GET', "$B/signal.php?key=spatny")[0]);
    set_setting('last_change_at', '1700000000');
    [$code, $body] = http('GET', "$B/signal.php?key=klic-synology&done=1600000000");
    eq([200, '1700000000'], [$code, $body]);
    setting('__reload');
    eq('1600000000', setting('player_done'));
    ok(time() - (int)setting('player_poll_at') < 30, 'player_poll_at');
});

test('cron.php přes URL jen s klíčem', function () use ($B) {
    eq(403, http('GET', "$B/cron.php?key=spatny")[0]);
    [$code, $body] = http('GET', "$B/cron.php?key=test-cron-klic");
    eq(200, $code, $body);
    ok(preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d (ok|unchanged): /', $body) === 1, $body);
});

test('backup.php: klíč a seznam záloh', function () use ($B) {
    eq(403, http('GET', "$B/backup.php?key=spatny&list=1")[0]);
    [$code] = http('GET', "$B/backup.php?key=klic-synology&list=1");
    eq(200, $code);
});

test('ochrana proti hádání hesla', function () use ($B) {
    @unlink($GLOBALS['jar']);
    for ($i = 0; $i < 5; $i++) http('POST', "$B/", ['form' => ['password' => 'spatne']]);
    contains('Příliš mnoho pokusů', http('POST', "$B/", ['form' => ['password' => 'test-heslo-123']])[1]);
    set_setting('login_fails', '0');
});

test('slide: skupina se uloží při vytvoření i úpravě a bez parametru zůstane (regrese 1.12.1)', function () use ($B, $csrf) {
    http('POST', "$B/", ['form' => ['password' => 'test-heslo-123']]);
    $h = ['X-CSRF: ' . $csrf(), 'Content-Type: application/json'];
    $design = ['background' => '#123456', 'elements' => [['type' => 'text', 'text' => 'Ahoj', 'x' => 100, 'y' => 100, 'w' => 800, 'h' => 200, 'size' => 80]]];
    [$code, $body, $j] = http('POST', "$B/api.php?a=slide_save", ['json' => ['name' => 'Skupinový slide', 'design' => $design, 'grp' => ' ER '], 'headers' => $h]);
    eq(200, $code, $body);
    $id = $j['media']['id'];
    eq('ER', $j['media']['grp']);
    [, , $j] = http('POST', "$B/api.php?a=slide_save", ['json' => ['id' => $id, 'name' => 'Skupinový slide', 'design' => $design], 'headers' => $h]);
    eq('ER', $j['media']['grp'], 'bez parametru grp zůstane');
    [, , $j] = http('POST', "$B/api.php?a=slide_save", ['json' => ['id' => $id, 'name' => 'Skupinový slide', 'design' => $design, 'grp' => 'Sportka'], 'headers' => $h]);
    eq('Sportka', $j['media']['grp']);
    [, , $j] = http('POST', "$B/api.php?a=slide_save", ['json' => ['id' => $id, 'name' => 'Skupinový slide', 'design' => $design, 'grp' => ''], 'headers' => $h]);
    eq(null, $j['media']['grp'], 'prázdná skupina = žádná');
});

test('přidání média do scénáře z Galerie (scenario_add_media)', function () use ($B, $csrf) {
    http('POST', "$B/", ['form' => ['password' => 'test-heslo-123']]);
    $h = ['X-CSRF: ' . $csrf(), 'Content-Type: application/json'];
    $mid = make_media('plakat-add.jpg');
    $sid = make_scenario('Přidávací scénář');
    eq(405, http('GET', "$B/api.php?a=scenario_add_media")[0]);
    eq(404, http('POST', "$B/api.php?a=scenario_add_media", ['json' => ['scenario_id' => 999999, 'media_id' => $mid], 'headers' => $h])[0]);
    eq(404, http('POST', "$B/api.php?a=scenario_add_media", ['json' => ['scenario_id' => $sid, 'media_id' => 999999], 'headers' => $h])[0]);
    [$code, , $j] = http('POST', "$B/api.php?a=scenario_add_media", ['json' => ['scenario_id' => $sid, 'media_id' => $mid], 'headers' => $h]);
    eq([200, true], [$code, $j['added'] ?? null]);
    [, , $j] = http('POST', "$B/api.php?a=scenario_add_media", ['json' => ['scenario_id' => $sid, 'media_id' => $mid], 'headers' => $h]);
    eq(false, $j['added'], 'podruhé se nepřidá');
    eq(1, (int)q('SELECT COUNT(*) FROM scenario_media WHERE scenario_id = ? AND media_id = ?', [$sid, $mid])->fetchColumn());
    ok((bool)q("SELECT 1 FROM logs WHERE message LIKE '%plakat-add.jpg“ přidáno do scénáře „Přidávací scénář“.'")->fetchColumn(), 'Protokol');
});
