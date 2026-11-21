<?php
// Archiv médií: do archivu / zpět, médium zůstává ve scénářích, výběrech i slidech, nenabízí se k novému použití.
require dirname(__DIR__) . '/lib.php';

$B = getenv('BASE_URL');
set_setting('password_hash', password_hash('test-heslo-123', PASSWORD_DEFAULT));
set_setting('login_fails', '0');

function am(string $name, string $kind = 'image', ?int $folder = null, string $content = ''): int {
    q("INSERT INTO media (filename, original_name, mime, kind, size, md5, content, folder_id, created_at) VALUES ('', ?, 'image/jpeg', ?, 1000, 'abcdef12', ?, ?, ?)",
        [$name, $kind, $content, $folder, date('Y-m-d H:i:s')]);
    return (int)db()->lastInsertId();
}
$fid = null;
$a = am('vanoce-2025.jpg', 'image');
$b = am('stale.jpg');
q("INSERT INTO scenarios (name, note, is_default, priority, exclusive, paused, weekdays, hours, exceptions, embed_only, is_fixed, updated_at, created_at) VALUES ('Večer', '', 0, 1, 0, 0, '1234567', ?, '[]', 0, 0, ?, ?)",
    [str_repeat('1', 24), date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
$sid = (int)db()->lastInsertId();
q('INSERT INTO scenario_media (scenario_id, media_id) VALUES (?, ?)', [$sid, $a]);
$pool = am('Náhodné', 'random');
q('INSERT INTO pool_members (pool_id, media_id) VALUES (?, ?)', [$pool, $a]);
$design = ['v' => 1, 'bg' => ['type' => 'color', 'color' => '#000000', 'color2' => '#111111', 'dir' => 'v', 'mediaId' => null, 'fit' => 'cover', 'dim' => 0],
    'elements' => [['id' => 'e1', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'mediaId' => $a, 'fit' => 'contain']]];
$slide = am('Slide s logem', 'slide', null, json_encode(['design' => $design]));

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


test('do archivu: jen POST s CSRF, médium zůstane ve scénáři, výběru i slidu a ukáže, kde se používá', function () use ($csrf, $a, $sid, $pool, $slide, $fid) {
    eq(405, capi('media_archive')[0]);
    eq(403, capi('media_archive', ['ids' => [$a], 'archive' => true], 'spatny')[0]);
    [$code, $j] = capi('media_archive', ['ids' => [$a], 'archive' => true], $csrf);
    eq([200, 1], [$code, $j['changed']], json_encode($j));
    eq(0, capi('media_archive', ['ids' => [$a], 'archive' => true], $csrf)[1]['changed'], 'podruhé nic');
    $m = array_values(array_filter(capi('media')[1], fn($x) => $x['id'] === $a))[0];
    ok($m['archived'], json_encode($m));
    eq(['Večer'], $m['used_in']['scenarios']);
    eq(['Náhodné'], $m['used_in']['pools']);
    eq(['Slide s logem'], $m['used_in']['slides']);
    ok(in_array($a, array_column(capi('scenarios')[1], 'media_ids')[0] ?? [], true), 'dál ve scénáři');
    eq(1, (int)q('SELECT COUNT(*) FROM pool_members WHERE media_id = ?', [$a])->fetchColumn());
});

test('archivované médium nejde přidat do scénáře, běžné ano', function () use ($csrf, $a, $b, $sid) {
    [$code, $j] = capi('scenario_add_media', ['scenario_id' => $sid, 'media_id' => $a], $csrf);
    eq(400, $code);
    ok(str_contains($j['error'] ?? '', 'archivu'), json_encode($j));
    eq(200, capi('scenario_add_media', ['scenario_id' => $sid, 'media_id' => $b], $csrf)[0]);
});

test('zpět z archivu: médium se zase nabízí a tagy mu zůstanou', function () use ($csrf, $a, $b) {
    capi('media_update', ['id' => $a, 'tags' => ['Vánoce']], $csrf);
    capi('media_archive', ['ids' => [$a, $b], 'archive' => true], $csrf);
    eq(1, capi('media_archive', ['ids' => [$a], 'archive' => false], $csrf)[1]['changed']);
    $m = array_values(array_filter(capi('media')[1], fn($x) => $x['id'] === $a))[0];
    eq([false, ['Vánoce']], [$m['archived'], $m['tags']]);
    capi('media_archive', ['ids' => [$b], 'archive' => false], $csrf);
});

test('trvalé smazání z archivu odebere médium ze scénářů a výběrů', function () use ($csrf, $a, $sid, $pool) {
    capi('media_archive', ['ids' => [$a], 'archive' => true], $csrf);
    $rows = q('SELECT id, archived_at FROM media WHERE id = ?', [$a])->fetchAll();
    ok($rows[0]['archived_at'] !== null);
    [$code] = capi('media_delete', ['ids' => [$a]], $csrf);
    eq(200, $code);
    eq(0, (int)q('SELECT COUNT(*) FROM scenario_media WHERE media_id = ?', [$a])->fetchColumn());
    eq(0, (int)q('SELECT COUNT(*) FROM pool_members WHERE media_id = ?', [$a])->fetchColumn());
});
