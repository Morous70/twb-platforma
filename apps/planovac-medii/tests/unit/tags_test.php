<?php
// Tagy médií (1.17.0): čištění a limity, zápis podle existujícího tagu, hromadné přidání/odebrání, přejmenování se sloučením,
// smazání, API (jen POST s CSRF), media_out, zachování v archivu.
require dirname(__DIR__) . '/lib.php';
require_once APP_DIR . '/lib/tags.php';

$B = getenv('BASE_URL');
set_setting('password_hash', password_hash('test-heslo-123', PASSWORD_DEFAULT));
set_setting('login_fails', '0');
function tm(string $name, ?array $tags = null): int {
    q("INSERT INTO media (filename, original_name, mime, kind, size, md5, tags, created_at) VALUES ('', ?, 'image/jpeg', 'image', 1, 'aa', ?, ?)",
        [$name, $tags ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null, date('Y-m-d H:i:s')]);
    return (int)db()->lastInsertId();
}
$tg = fn(int $id) => media_tags(q('SELECT tags FROM media WHERE id = ?', [$id])->fetch());

test('čištění: mezery, čárky, opakování bez ohledu na velikost písmen, nejvýš 30 znaků a 10 tagů', function () {
    eq(['Vánoce', 'Akce 2026'], tags_clean(['  Vánoce ', 'vánoce', "Akce\t 2026", '', 'VÁNOCE'], []));
    eq(['Vánoce', 'Loterie'], tags_clean('Vánoce, Loterie; vánoce', []));
    throws(fn() => tags_clean([str_repeat('á', 31)], []), 'delší než 30 znaků');
    eq([str_repeat('á', 30)], tags_clean([str_repeat('á', 30)], []));
    throws(fn() => tags_clean(array_map(fn($i) => "t$i", range(1, 11)), []), 'nejvýš 10 tagů');
    eq(10, count(tags_clean(array_map(fn($i) => "t$i", range(1, 10)), [])));
});

$a = tm('a.jpg', ['Vánoce', 'Sportka']);
$b = tm('b.jpg', ['Sportka']);
$c = tm('c.jpg');
test('nový tag se zapíše jako už existující (vánoce → Vánoce); seznam tagů s počty', function () use ($c, $tg) {
    media_set_tags($c, tags_clean(['vánoce', 'Nový']));
    eq(['Vánoce', 'Nový'], $tg($c));
    $all = tags_all();
    eq(['Nový' => 1, 'Sportka' => 2, 'Vánoce' => 2], array_combine(array_column($all, 'name'), array_column($all, 'count')));
});

test('hromadně: přidat a odebrat u více médií, beze změny se nepočítá, limit 10 u konkrétního média', function () use ($a, $b, $c, $tg) {
    eq(3, tags_bulk([$a, $b, $c], ['Akce'], []));
    eq(['Vánoce', 'Sportka', 'Akce'], $tg($a));
    eq(0, tags_bulk([$a, $b, $c], ['akce'], []), 'už mají');
    eq(2, tags_bulk([$a, $c], [], ['VÁNOCE']));
    eq(['Sportka', 'Akce'], $tg($a));
    eq(['Nový', 'Akce'], $tg($c));
    $d = tm('d.jpg', array_map(fn($i) => "x$i", range(1, 10)));
    throws(fn() => tags_bulk([$d], ['jeden navíc'], []), '„d.jpg“: Médium může mít nejvýš 10 tagů');
    q('DELETE FROM media WHERE id = ?', [$d]);
});

test('přejmenování: ve všech médiích; na existující tag se sloučí bez zdvojení; smazání ze všech', function () use ($a, $b, $c, $tg) {
    eq(1, tag_rename('nový', 'Novinka'));
    eq(['Novinka', 'Akce'], $tg($c));
    eq(3, tag_rename('Akce', 'sportka'), 'sloučení');
    eq(['Sportka'], $tg($a), 'bez zdvojení, zápis existujícího tagu');
    eq(['Sportka'], $tg($b));
    eq(['Novinka', 'Sportka'], $tg($c));
    throws(fn() => tag_rename('neexistuje', 'x'), 'nenalezen');
    throws(fn() => tag_rename('Sportka', '  '), 'Zadejte nový název');
    eq(3, tag_delete('SPORTKA'));
    eq([[], [], ['Novinka']], [$tg($a), $tg($b), $tg($c)]);
    eq(null, q('SELECT tags FROM media WHERE id = ?', [$a])->fetchColumn(), 'prázdné = NULL');
});

// ---------- HTTP ----------
$jar = TMP_DIR . '/cookies-tags.txt'; @unlink($jar);
$ch = curl_init("$B/"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POSTFIELDS => http_build_query(['password' => 'test-heslo-123'])]); curl_exec($ch);
$ch = curl_init("$B/"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
preg_match('/name="csrf" content="([^"]+)"/', (string)curl_exec($ch), $m); $csrf = $m[1] ?? '';
function tapi(string $a, ?array $body = null, string $csrf = ''): array {
    global $B, $jar;
    $ch = curl_init("$B/api.php?a=$a");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => $body !== null ? ["X-CSRF: $csrf", 'Content-Type: application/json'] : []]);
    if ($body !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)]);
    $out = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode($out, true)];
}

test('HTTP: media_update a text_save uloží tagy, media je vrací; chybný tag 400; hromadně, přejmenovat, smazat jen POST s CSRF', function () use ($csrf, $a, $b) {
    [$code, $j] = tapi('media_update', ['id' => $a, 'tags' => ['Loterie', 'loterie', 'Akce']], $csrf);
    eq([200, ['Loterie', 'Akce']], [$code, $j['tags']], json_encode($j));
    eq(400, tapi('media_update', ['id' => $a, 'tags' => [str_repeat('x', 31)]], $csrf)[0]);
    [$code, $j] = tapi('text_save', ['name' => 'Jackpot', 'content' => 'Dnes jackpot', 'tags' => ['Sportka']], $csrf);
    eq([200, ['Sportka']], [$code, $j['media']['tags']], json_encode($j));
    [, $list] = tapi('media');
    eq(['Loterie', 'Akce'], array_values(array_filter($list, fn($x) => $x['id'] === $a))[0]['tags']);
    eq(405, tapi('media_tags')[0]);
    eq(403, tapi('media_tags', ['ids' => [$a, $b], 'add' => ['X']], 'spatny')[0]);
    [$code, $j] = tapi('media_tags', ['ids' => [$a, $b], 'add' => ['Vánoce'], 'remove' => ['akce']], $csrf);
    eq([200, 2], [$code, $j['changed']]);
    [, $t] = tapi('tags');
    eq(['Loterie' => 1, 'Novinka' => 1, 'Sportka' => 1, 'Vánoce' => 2], array_combine(array_column($t, 'name'), array_column($t, 'count')));
    eq([200, 2], [tapi('tag_rename', ['from' => 'Vánoce', 'to' => 'Svátky'], $csrf)[0], tapi('tags')[1][array_search('Svátky', array_column(tapi('tags')[1], 'name'))]['count']]);
    eq(200, tapi('tag_delete', ['name' => 'svátky'], $csrf)[0]);
    ok(!in_array('Svátky', array_column(tapi('tags')[1], 'name'), true));
});

test('archiv: tagy zůstanou a vrátí se i u archivovaných', function () use ($csrf, $a) {
    tapi('media_archive', ['ids' => [$a], 'archive' => true], $csrf);
    [, $list] = tapi('media');
    $m = array_values(array_filter($list, fn($x) => $x['id'] === $a))[0];
    eq([true, ['Loterie']], [$m['archived'], $m['tags']]);
    tapi('media_archive', ['ids' => [$a], 'archive' => false], $csrf);
});
