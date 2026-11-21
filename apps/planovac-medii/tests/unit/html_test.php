<?php
// HTML dokumenty (1.16.0): dosazení proměnných mimo skripty a styly, písma v dokumentu, odkazy na internet, limity,
// nahrání souboru, izolace (CSP sandbox), obnova po změně dat a nabídka zařízením podle verze přehrávače.
require dirname(__DIR__) . '/lib.php';
require_once APP_DIR . '/lib/slide.php';
require_once APP_DIR . '/lib/devices.php';

$B = getenv('BASE_URL');
$t = new DateTimeImmutable('2026-10-23 10:00:00'); // pátek
set_setting('password_hash', password_hash('test-heslo-123', PASSWORD_DEFAULT));
set_setting('login_fails', '0');

test('proměnné: dosadí se v textu i atributu, ne ve skriptu, stylu a komentáři; hodnoty se escapují', function () use ($t) {
    $src = '<style>body { color: red }</style><p title="{den}">Dnes je {den}, {datum}</p><!-- {den} --><script>var o = {den: 1}; var s = "{den}";</script>';
    [$html, $unknown] = html_resolve($src, $t);
    ok(str_contains($html, '<p title="pátek">Dnes je pátek, 23. 10. 2026</p>'), $html);
    ok(str_contains($html, 'var o = {den: 1}; var s = "{den}";') && str_contains($html, '<!-- {den} -->') && str_contains($html, 'body { color: red }'), $html);
    eq([], $unknown);
    q("INSERT INTO data_rows (grp, param, value, created_at, updated_at) VALUES ('Ceník', 'Káva', '<b>45</b> Kč', ?, ?)", [date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
    data_rows(true);
    [$h2] = html_resolve('<p>{Ceník:Káva}</p>', $t);
    eq('<p>&lt;b&gt;45&lt;/b&gt; Kč</p>', $h2, 'hodnota se nevloží jako HTML');
});

test('proměnné: &nbsp; a entity z editoru v zápisu nevadí, neznámé zůstanou beze změny', function () use ($t) {
    [$html, $unknown] = html_resolve('<p>{datum|datum:DN&nbsp;D.&nbsp;M.} {neznama}</p>', $t);
    eq('<p>pá 23. 10. {neznama}</p>', $html);
    eq(['{neznama}'], $unknown);
});

test('písma aplikace použitá v dokumentu se vloží jako data, nepoužitá ne; kódování se doplní', function () use ($t) {
    [$html] = html_build('<!DOCTYPE html><html><head><style>h1 { font-family: "Bebas Neue"; }</style></head><body><p style="font-family: Montserrat;">x</p></body></html>', $t);
    ok(str_contains($html, "font-family: 'Bebas Neue'; font-weight: 400;") && substr_count($html, "font-family: 'Montserrat'; font-weight:") === 3, 'Bebas 1× + Montserrat 3 tloušťky');
    ok(!str_contains($html, "font-family: 'Roboto'"), 'nepoužité písmo ne');
    ok(preg_match('~^<!DOCTYPE html>\n<meta charset="utf-8">~', $html) === 1, 'charset za doctype: ' . substr($html, 0, 60));
    ok(strpos($html, '<style data-mp="fonts">') > strpos($html, '<head>'), 'písma v hlavičce');
    [$frag] = html_build('<p>bez písem</p>', $t);
    eq("<meta charset=\"utf-8\">\n<p>bez písem</p>", $frag);
});

test('odkazy na internet: obrázky, skripty, styly, @import a url(); data: a relativní ne', function () {
    $src = '<img src="https://cdn.example.com/a.png"><script src="//js.example.org/x.js"></script><link href="https://fonts.googleapis.com/css2">'
        . '<style>@import "https://x.test/a.css"; div { background: url(https://img.test/b.jpg) }</style><img src="data:image/png;base64,AA"><img src="obr.png"><a href="https://odkaz.cz">x</a>';
    eq(['cdn.example.com', 'fonts.googleapis.com', 'img.test', 'js.example.org', 'odkaz.cz', 'x.test'], html_external_hosts($src));
});

test('kontrola: prázdný dokument, nad 5 MB, Windows-1250 se převede, BOM a konce řádků', function () {
    throws(fn() => html_check('<html><body> <p> </p> </body></html>'), 'prázdný');
    throws(fn() => html_check('<p>' . str_repeat('a', HTML_MAX_BYTES) . '</p>'), '5 MB');
    eq("<p>Příliš žluťoučký</p>\n", html_check(iconv('UTF-8', 'Windows-1250', "<p>Příliš žluťoučký</p>\r\n")));
    eq('<p>a</p>', html_check("\xEF\xBB\xBF<p>a</p>"));
    eq('<img src="x.png">', html_check('<img src="x.png">'), 'jen obrázek není prázdný');
});

// ---------- HTTP ----------
$jar = TMP_DIR . '/cookies-html.txt'; @unlink($jar);
$ch = curl_init("$B/"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POSTFIELDS => http_build_query(['password' => 'test-heslo-123'])]); curl_exec($ch);
$ch = curl_init("$B/"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
preg_match('/name="csrf" content="([^"]+)"/', (string)curl_exec($ch), $m); $csrf = $m[1] ?? '';
function hreq(string $url, $body = null, array $headers = [], bool $json = true): array {
    global $jar;
    $ch = curl_init($url);
    $rh = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$rh) { if (str_contains($h, ':')) { [$k, $v] = explode(':', $h, 2); $rh[strtolower(trim($k))] = trim($v); } return strlen($h); }]);
    if ($body !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]);
    $out = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $json ? json_decode($out, true) : $out, $rh];
}
$apiPost = fn(string $a, array $b) => hreq("$B/api.php?a=$a", json_encode($b), ["X-CSRF: $csrf", 'Content-Type: application/json']);

$docId = 0;
test('HTTP: html_save jen POST s CSRF, dokument se uloží, file.php ho vydá izolovaně (CSP sandbox)', function () use ($B, $csrf, $apiPost, &$docId) {
    eq(403, hreq("$B/api.php?a=html_save", json_encode(['name' => 'a', 'source' => '<p>x</p>']), ['X-CSRF: spatny', 'Content-Type: application/json'])[0]);
    [$code, $j] = $apiPost('html_save', ['name' => 'Otevírací doba.html', 'source' => '<!DOCTYPE html><html><head></head><body><p>Dnes {den}</p><img src="https://cdn.test/a.png"></body></html>']);
    eq(200, $code, show($j));
    eq(['html', 'Otevírací doba.html', 'text/html', null], [$j['media']['kind'], $j['media']['original_name'], $j['media']['mime'], $j['media']['content']]);
    ok(str_contains((string)$j['warning'], 'cdn.test'), 'upozornění na internet');
    $docId = $j['media']['id'];
    [$code, $body, $h] = hreq("$B/" . $j['media']['url'], null, [], false);
    eq(200, $code);
    eq('sandbox allow-scripts', $h['content-security-policy'] ?? null);
    ok(str_starts_with($h['content-type'] ?? '', 'text/html') && preg_match('~<p>Dnes (pondělí|úterý|středa|čtvrtek|pátek|sobota|neděle)</p>~u', $body), $body);
    [, $g] = hreq("$B/api.php?a=html_get&id=$docId");
    ok(str_contains($g['source'], '<p>Dnes {den}</p>'), 'zdroj beze změny');
    eq(400, $apiPost('html_save', ['id' => $docId, 'name' => 'x', 'source' => ' '])[0]);
});

test('HTTP: nahrání souboru .html do galerie a nahrazení jiným souborem (zůstane stejné médium)', function () use ($B, $csrf) {
    $up = function (string $name, string $content, int $replace = 0) use ($B, $csrf) {
        $tmp = TMP_DIR . '/up-' . bin2hex(random_bytes(4)); file_put_contents($tmp, $content);
        $post = ['upload_id' => bin2hex(random_bytes(10)), 'index' => 0, 'total' => 1, 'name' => $name, 'chunk' => new CURLFile($tmp, 'text/html', $name)];
        if ($replace) $post['replace_id'] = $replace;
        return hreq("$B/api.php?a=upload_chunk", $post, ["X-CSRF: $csrf"]);
    };
    [$code, $j] = $up('akce.htm', '<html><body><h2>Akce</h2></body></html>');
    eq(200, $code, show($j));
    eq(['html', 'akce.html'], [$j['kind'], $j['original_name']]);
    [$code, $j2] = $up('jine.html', '<html><body><h2>Nová akce</h2></body></html>', $j['id']);
    eq([200, $j['id'], 'akce.html'], [$code, $j2['id'], $j2['original_name']], show($j2));
    ok(str_contains(file_get_contents(MEDIA_DIR . '/' . q('SELECT filename FROM media WHERE id = ?', [$j['id']])->fetchColumn()), 'Nová akce'));
    q("INSERT INTO media (filename, original_name, mime, kind, size, md5, created_at) VALUES ('x.jpg', 'obr.jpg', 'image/jpeg', 'image', 1, 'aa', ?)", [date('Y-m-d H:i:s')]);
    [$code, $e] = $up('obr.html', '<html><body><p>x</p></body></html>', (int)db()->lastInsertId());
    ok($code >= 400, 'jiný druh nejde nahradit HTML: ' . show($e));
    [$code, $e] = $up('prazdny.html', '<html><body></body></html>');
    eq(400, $code);
    ok(str_contains($e['error'], 'prázdný'), show($e));
});

test('po změně dat se dokument sám obnoví (spolu se slidy)', function () {
    q("INSERT INTO media (filename, original_name, mime, kind, size, md5, content, created_at) VALUES ('', 'cena.html', 'text/html', 'html', 0, '', '{}', ?)", [date('Y-m-d H:i:s')]);
    $id = (int)db()->lastInsertId();
    html_store($id, '<p>Káva {Ceník:Káva}</p>', new DateTimeImmutable());
    $file = fn() => file_get_contents(MEDIA_DIR . '/' . q('SELECT filename FROM media WHERE id = ?', [$id])->fetchColumn());
    ok(str_contains($file(), '&lt;b&gt;45&lt;/b&gt; Kč'), $file());
    q("UPDATE data_rows SET value = '49 Kč' WHERE grp = 'Ceník'");
    data_rows(true);
    ok(in_array('cena.html', regenerate_slides(new DateTimeImmutable()), true), 'obnoveno');
    ok(str_contains($file(), 'Káva 49 Kč'), $file());
    eq([], array_values(array_filter(regenerate_slides(new DateTimeImmutable()), fn($n) => $n === 'cena.html')), 'beze změny se znovu negeneruje');
});

test('zařízení: HTML jen přehrávačům od 1.2.0, starší ho v seznamu nedostanou', function () {
    set_setting('folder_id', 'html-slozka');
    @mkdir(DATA_DIR . '/mock-drive/html-slozka', 0775, true);
    file_put_contents(DATA_DIR . '/mock-drive/html-slozka/a.jpg', 'JPEGDATA');
    file_put_contents(DATA_DIR . '/mock-drive/html-slozka/doba.html', '<p>x</p>');
    set_setting('device_files_cache', null);
    [$dev] = device_create('HTML TV', 'tizen', null);
    $names = fn(?string $c) => array_column(device_now(device_get($dev['id']), $c)['files'], 'name');
    eq(['a.jpg'], $names('tizen/1.1.0'));
    eq(['a.jpg'], $names(null));
    eq(['a.jpg', 'doba.html'], $names('tizen/1.2.0'));
    eq(['a.jpg', 'doba.html'], $names('chrome/1.3.1'));
    eq(['html', 'text/html'], device_file_kind('Doba.HTML'));
    device_delete($dev['id']);
});
