<?php
declare(strict_types=1);
// TWB Media galerie 0.3: API (klíče, oprávnění, média, nahrání i po částech a z adresy, úpravy, kampaně, tagy) a ochrana stahování z adresy.
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use MediaGalerie\{ZAdresy, ApiKlice, Chyba};

echo "Media galerie: API\n";

function png(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, (int)imagecolorallocate($im, 10, 160, 90));
    ob_start(); imagepng($im); return (string)ob_get_clean();
}

// ---------- bez serveru ----------

test('stahování z adresy: jen veřejné IP, http(s), porty 80/443, bez jména a hesla v adrese', function () {
    $dns = fn(array $ip) => fn(string $h) => $ip;
    eq(['example.com', 443, '93.184.216.34'], ZAdresy::over('https://example.com/a.jpg', $dns(['93.184.216.34'])));
    eq(['example.com', 80, '93.184.216.34'], ZAdresy::over('http://EXAMPLE.com/a.jpg', $dns(['93.184.216.34'])));
    foreach (['127.0.0.1', '10.1.2.3', '192.168.0.10', '172.16.5.5', '169.254.169.254', '0.0.0.0', '100.64.1.1', '::1', 'fd00::1', 'fe80::1', '::ffff:10.0.0.1'] as $ip) {
        throws(fn() => ZAdresy::over('https://zlo.example/', $dns([$ip])), 'vnitřní sít');
    }
    throws(fn() => ZAdresy::over('https://zlo.example/', $dns(['93.184.216.34', '10.0.0.1'])), 'vnitřní sít');
    throws(fn() => ZAdresy::over('http://127.0.0.1/x'), 'vnitřní sít');
    throws(fn() => ZAdresy::over('http://[::1]/x'), 'vnitřní sít');
    throws(fn() => ZAdresy::over('ftp://example.com/x'), 'http');
    throws(fn() => ZAdresy::over('file:///etc/passwd'), 'http');
    throws(fn() => ZAdresy::over('https://user:heslo@example.com/'), 'http');
    throws(fn() => ZAdresy::over('https://example.com:8080/', $dns(['93.184.216.34'])), 'porty');
    throws(fn() => ZAdresy::over('https://neexistuje.example/', $dns([])), 'přeložit');
    ok(ZAdresy::verejna('2a00:1450:4014:80d::200e'));
});

test('stahování z adresy: název souboru z adresy a typu', function () {
    eq('foto.jpg', ZAdresy::nazev('https://example.com/img/foto.jpg?x=1', 'image/jpeg'));
    eq('obrazek.png', ZAdresy::nazev('https://example.com/obrazek', 'image/png; charset=binary'));
    eq('Léto 2026.mp4', ZAdresy::nazev('https://example.com/L%C3%A9to%202026.mp4', ''));
    eq('soubor', ZAdresy::nazev('https://example.com/', 'text/html'));
    eq('a_b.pdf', ZAdresy::nazev('a/b.pdf', ''));
});

test('API klíč: formát, otisk, zámek po mnoha chybách', function () {
    $app = novaApp();
    $k = $app->apiKlice();
    $klic = $k->vytvor('Web', 'read');
    ok(preg_match('/^tgk_[0-9a-f]{40}$/', $klic) === 1);
    $r = $app->db->q('SELECT * FROM api_keys')->fetch();
    eq([hash('sha256', $klic), substr($klic, 0, 12)], [$r['key_hash'], $r['prefix']]);
    ok(!str_contains(json_encode($app->db->q('SELECT * FROM api_keys')->fetchAll()), substr($klic, 12)), 'celý klíč se neukládá');
    eq('Web', $k->over($klic)['name']);
    eq(null, $k->over($klic . '0'));
    eq(null, $k->over('tgk_' . str_repeat('0', 40)));
    throws(fn() => $k->vytvor('', 'read'), 'název');
    throws(fn() => $k->vytvor('X', 'admin'), 'oprávnění');
    for ($i = 0; $i < ApiKlice::MAX_CHYB - 1; $i++) $k->neuspech();
    eq(0, $k->zamceno());
    $k->neuspech();
    ok($k->zamceno() > 0);
    $app->nastaveni()->uloz('api_fail_since', (string)(time() - ApiKlice::OKNO_SEKUND - 1));
    eq(0, $k->zamceno(), 'po 5 minutách volno');
});

// ---------- přes web ----------

$s = new TestServer();
register_shutdown_function(fn() => $s->zastav());
// stahování z adresy v testu nejde do sítě: odpovědi dodá funkce v config.php
file_put_contents($s->config, '<?php $c = ' . var_export((require $s->config), true) . ";\n"
    . '$c["stahovani_http"] = function (string $u): array {'
    . ' if (str_contains($u, "velky")) return ["data" => str_repeat("x", 1024 * 1048576 + 1), "typ" => "image/png", "nazev" => ""];'
    . ' if (str_contains($u, "chyba")) throw new MediaGalerie\Chyba("Adresa vrátila HTTP 404.");'
    . ' \MediaGalerie\ZAdresy::over($u, fn($h) => ["93.184.216.34"]);'
    . ' $im = imagecreatetruecolor(40, 30); ob_start(); imagepng($im); return ["data" => (string)ob_get_clean(), "typ" => "image/png", "nazev" => ""]; };'
    . "\nreturn \$c;\n");
$b = new Prohlizec($s);
$b->jdi('install.php');
$b->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'tajneheslo1']);

/** Požadavek na API. @param array<string, mixed>|null $form @return array{code:int, json:?array, body:string, type:string} */
function api(TestServer $s, string $metoda, string $cesta, ?string $klic, ?array $form = null, ?array $json = null, string $hlavicka = 'Authorization: Bearer '): array
{
    $ch = curl_init($s->url . '/api.php/v1' . $cesta);
    $h = $klic !== null ? [$hlavicka . $klic] : [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30]);
    if ($form !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $form);
    if ($json !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json)); $h[] = 'Content-Type: application/json'; }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    $r = (string)curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($r, $hs);
    $type = preg_match('/^Content-Type:\s*([^\r\n;]+)/mi', substr($r, 0, $hs), $m) ? $m[1] : '';
    if (str_contains($body, 'Fatal error') || str_contains($body, 'Warning:')) throw new RuntimeException('Chyba PHP: ' . mb_substr(strip_tags($body), 0, 500));
    return ['code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'json' => json_decode($body, true), 'body' => $body, 'type' => $type];
}

function soubor(string $obsah): CURLFile
{
    $f = docasna('api');
    file_put_contents($f, $obsah);
    return new CURLFile($f);
}

$cteni = $zapis = '';

test('stránka API: vytvoření klíče (ukáže se jednou), seznam, zrušení', function () use ($s, $b, &$cteni, &$zapis) {
    $r = $b->jdi('index.php?p=api');
    ok(str_contains($r['body'], 'Zatím žádný klíč') && str_contains($r['body'], '/api.php/v1'));
    $r = $b->jdi('index.php?p=api', ['akce' => 'vytvorit', 'nazev' => 'Web', 'opravneni' => 'read']);
    ok(preg_match('/id="novy-klic" readonly value="(tgk_[0-9a-f]{40})"/', $r['body'], $m) === 1, 'klíč na stránce');
    $cteni = $m[1];
    $r = $b->jdi('index.php?p=api', ['akce' => 'vytvorit', 'nazev' => 'Skript', 'opravneni' => 'write']);
    preg_match('/id="novy-klic" readonly value="(tgk_[0-9a-f]{40})"/', $r['body'], $m);
    $zapis = $m[1];
    $r = $b->jdi('index.php?p=api');
    ok(!str_contains($r['body'], $cteni) && str_contains($r['body'], substr($cteni, 0, 12) . '…'), 'podruhé jen začátek');
    $r = $b->jdi('index.php?p=api', ['akce' => 'vytvorit', 'nazev' => '', 'opravneni' => 'read']);
    ok(str_contains($r['body'], 'Zadejte název klíče'));
    $b->jdi('index.php?p=api', ['akce' => 'vytvorit', 'nazev' => 'Smazat', 'opravneni' => 'read']);
    $id = (int)$s->db()->query("SELECT id FROM api_keys WHERE name = 'Smazat'")->fetchColumn();
    $r = $b->odesli('index.php?p=api', ['akce' => 'smazat', 'id' => $id]);
    ok(str_contains($r['body'], 'Klíč zrušen'));
    eq(2, (int)$s->db()->query('SELECT COUNT(*) FROM api_keys')->fetchColumn());
});

test('API: bez klíče 401, špatný klíč 401, klíč pro čtení nesmí zapisovat, X-Api-Key', function () use ($s, &$cteni, &$zapis) {
    eq(401, api($s, 'GET', '/media', null)['code']);
    eq(401, api($s, 'GET', '/media', 'tgk_' . str_repeat('a', 40))['code']);
    $r = api($s, 'GET', '/', $cteni);
    eq([200, 'application/json', 'Web', 'read'], [$r['code'], $r['type'], $r['json']['klic'], $r['json']['opravneni']]);
    eq('Skript', api($s, 'GET', '/', $zapis, hlavicka: 'X-Api-Key: ')['json']['klic']);
    $r = api($s, 'POST', '/kampane', $cteni, json: ['nazev' => 'X']);
    eq([403, false], [$r['code'], $r['json']['ok']]);
    eq(404, api($s, 'GET', '/neni', $cteni)['code']);
    eq(405, api($s, 'PUT', '/kampane', $zapis)['code']);
    ok((int)$s->db()->query("SELECT last_used_at FROM api_keys WHERE name = 'Web'")->fetchColumn() > 0, 'čas použití');
});

test('API: kampaně – založení, výpis, úprava, chyby', function () use ($s, &$zapis, &$cteni) {
    $r = api($s, 'POST', '/kampane', $zapis, json: ['nazev' => 'Podzim', 'poznamka' => 'web']);
    eq([201, 'Podzim', 'web', 0], [$r['code'], $r['json']['kampan']['nazev'], $r['json']['kampan']['poznamka'], $r['json']['kampan']['pocet_medii']]);
    $id = $r['json']['kampan']['id'];
    eq(400, api($s, 'POST', '/kampane', $zapis, json: ['nazev' => 'podzim'])['code'], 'duplicitní');
    $r = api($s, 'PATCH', "/kampane/$id", $zapis, json: ['nazev' => 'Podzim 2026']);
    eq(['Podzim 2026', 'web'], [$r['json']['kampan']['nazev'], $r['json']['kampan']['poznamka']]);
    eq('Podzim 2026', api($s, 'GET', '/kampane', $cteni)['json']['kampane'][0]['nazev']);
    eq(404, api($s, 'GET', '/kampane/999', $cteni)['code']);
    eq(400, api($s, 'POST', '/kampane', $zapis, json: ['nazev' => ''])['code']);
    $r = (function () use ($s, $zapis) {
        $ch = curl_init($s->url . '/api.php/v1/kampane');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{neplatny', CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $zapis, 'Content-Type: application/json']]);
        return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0, curl_exec($ch), curl_getinfo($ch, CURLINFO_HTTP_CODE)];
    })();
    eq(400, $r[2], 'neplatný JSON');
});

test('API: nahrání souboru s kampaní, tagy a poznámkou; nepovolený typ', function () use ($s, &$zapis) {
    $k = (int)$s->db()->query('SELECT id FROM campaigns')->fetchColumn();
    $png = png(300, 200);
    $r = api($s, 'POST', '/media', $zapis, ['soubor' => soubor($png), 'nazev' => 'banner.png', 'kampan' => (string)$k, 'tagy' => 'web, Léto', 'poznamka' => 'pro web']);
    eq(201, $r['code'], $r['body']);
    $m = $r['json']['medium'];
    eq(['banner.png', 'image', 300, 200, md5($png), $k, ['web', 'Léto'], 'pro web'], [$m['nazev'], $m['druh'], $m['sirka'], $m['vyska'], $m['md5'], $m['kampan']['id'], $m['tagy'], $m['poznamka']]);
    ok(str_contains($m['verejny_odkaz'], '/soubor.php/') && str_ends_with($m['soubor'], '/api.php/v1/media/' . $m['id'] . '/soubor'));
    $r = api($s, 'POST', '/media', $zapis, ['soubor' => soubor('<?php phpinfo();'), 'nazev' => 'x.php']);
    eq(400, $r['code']);
    ok(str_contains($r['json']['chyba'], 'Nepodporovaný typ'));
    eq(400, api($s, 'POST', '/media', $zapis, ['nazev' => 'nic'])['code'], 'bez souboru i adresy');
    eq(404, api($s, 'POST', '/media', $zapis, ['soubor' => soubor($png), 'kampan' => '999'])['code']);
    eq(1, (int)$s->db()->query('SELECT COUNT(*) FROM media')->fetchColumn());
});

test('API: nahrání po částech a z adresy', function () use ($s, &$zapis) {
    $data = mp4(9000);
    $uid = bin2hex(random_bytes(16));
    $casti = str_split($data, 4000);
    foreach ($casti as $i => $c) {
        $r = api($s, 'POST', '/media', $zapis, ['soubor' => soubor($c), 'nazev' => 'spot.mp4', 'uid' => $uid, 'index' => (string)$i, 'celkem' => (string)count($casti)]);
        eq($i === count($casti) - 1, $r['json']['hotovo'] ?? null, $r['body']);
    }
    eq(['video', 9000, md5($data)], [$r['json']['medium']['druh'], $r['json']['medium']['velikost'], $r['json']['medium']['md5']]);
    $r = api($s, 'POST', '/media', $zapis, ['url' => 'https://example.com/obrazky/foto']);
    eq([201, 'foto.png', 40], [$r['code'], $r['json']['medium']['nazev'] ?? null, $r['json']['medium']['sirka'] ?? null], $r['body']);
    $r = api($s, 'POST', '/media', $zapis, ['url' => 'https://example.com/x.png', 'nazev' => 'Vlastní']);
    eq('Vlastní.png', $r['json']['medium']['nazev']);
    $r = api($s, 'POST', '/media', $zapis, ['url' => 'http://127.0.0.1/config.php']);
    ok($r['code'] === 400 && str_contains($r['json']['chyba'], 'vnitřní sít'), $r['body']);
    ok(str_contains(api($s, 'POST', '/media', $zapis, ['url' => 'https://example.com/velky.png'])['json']['chyba'], 'příliš velký'));
    ok(str_contains(api($s, 'POST', '/media', $zapis, ['url' => 'https://example.com/chyba.png'])['json']['chyba'], 'HTTP 404'));
    eq([], glob($s->data . '/data/tmp/*') ?: [], 'dočasné soubory uklizené');
});

test('API: seznam s filtry a stránkováním, detail, stažení souboru s Range', function () use ($s, &$cteni) {
    $r = api($s, 'GET', '/media', $cteni);
    eq([200, 4, 1], [$r['code'], $r['json']['celkem'], $r['json']['stran']]);
    eq(['Vlastní.png', 'foto.png', 'spot.mp4', 'banner.png'], array_column($r['json']['media'], 'nazev'));
    eq(['spot.mp4'], array_column(api($s, 'GET', '/media?druh=video', $cteni)['json']['media'], 'nazev'));
    eq(['banner.png'], array_column(api($s, 'GET', '/media?tag=l%C3%A9to', $cteni)['json']['media'], 'nazev'));
    eq(['banner.png'], array_column(api($s, 'GET', '/media?q=pro%20web', $cteni)['json']['media'], 'nazev'));
    eq(3, api($s, 'GET', '/media?kampan=0', $cteni)['json']['celkem']);
    $r = api($s, 'GET', '/media?na_stranku=3&strana=2&razeni=stare', $cteni);
    eq([2, 2, ['Vlastní.png']], [$r['json']['strana'], $r['json']['stran'], array_column($r['json']['media'], 'nazev')]);
    eq(400, api($s, 'GET', '/media?na_stranku=500', $cteni)['code']);
    eq(400, api($s, 'GET', '/media?druh=zvuk', $cteni)['code']);
    eq(400, api($s, 'GET', '/media?razeni=x', $cteni)['code']);
    $id = (int)$s->db()->query("SELECT id FROM media WHERE original_name = 'spot.mp4'")->fetchColumn();
    eq('spot.mp4', api($s, 'GET', "/media/$id", $cteni)['json']['medium']['nazev']);
    eq(404, api($s, 'GET', '/media/999', $cteni)['code']);
    $r = api($s, 'GET', "/media/$id/soubor", $cteni);
    eq(['video/mp4', 9000], [$r['type'], strlen($r['body'])]);
    $ch = curl_init($s->url . "/api.php/v1/media/$id/soubor");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cteni, 'Range: bytes=0-9']]);
    eq(10, strlen((string)curl_exec($ch)));
    eq(206, curl_getinfo($ch, CURLINFO_HTTP_CODE));
    eq(401, api($s, 'GET', "/media/$id/soubor", null)['code']);
    $t = api($s, 'GET', '/tagy', $cteni)['json']['tagy'];
    eq(['Léto', 'web'], array_column($t, 'nazev'));
});

test('API: úprava (vše nebo nic), archiv, odkaz, nahrazení, smazání', function () use ($s, &$zapis, &$cteni) {
    $id = (int)$s->db()->query("SELECT id FROM media WHERE original_name = 'banner.png'")->fetchColumn();
    $r = api($s, 'PATCH', "/media/$id", $zapis, json: ['nazev' => 'Hlavní banner', 'tagy' => ['a', 'b'], 'kampan' => null, 'poznamka' => null]);
    $m = $r['json']['medium'];
    eq(['Hlavní banner.png', ['a', 'b'], null, null], [$m['nazev'], $m['tagy'], $m['kampan'], $m['poznamka']]);
    $r = api($s, 'PATCH', "/media/$id", $zapis, json: ['nazev' => 'Jiný', 'tagy' => range(1, 11)]);
    eq(400, $r['code']);
    eq('Hlavní banner.png', api($s, 'GET', "/media/$id", $cteni)['json']['medium']['nazev'], 'nic se nezměnilo');
    eq(400, api($s, 'PATCH', "/media/$id", $zapis, json: ['velikost' => 1])['code'], 'neznámé pole');
    eq(404, api($s, 'PATCH', "/media/$id", $zapis, json: ['kampan' => 999])['code']);
    $r = api($s, 'PATCH', "/media/$id", $zapis, json: ['archiv' => true]);
    ok($r['json']['medium']['archivovano'] !== null);
    eq(1, api($s, 'GET', '/media?archiv=1', $cteni)['json']['celkem']);
    api($s, 'PATCH', "/media/$id", $zapis, json: ['archiv' => false]);
    $stary = api($s, 'GET', "/media/$id", $cteni)['json']['medium']['verejny_odkaz'];
    $novy = api($s, 'POST', "/media/$id/odkaz", $zapis)['json']['medium']['verejny_odkaz'];
    ok($novy !== $stary && $novy !== null);
    eq(null, api($s, 'DELETE', "/media/$id/odkaz", $zapis)['json']['medium']['verejny_odkaz']);
    $png = png(64, 64);
    $r = api($s, 'POST', "/media/$id/soubor", $zapis, ['soubor' => soubor($png), 'nazev' => 'novy.png']);
    eq([200, 64, md5($png), ['a', 'b']], [$r['code'], $r['json']['medium']['sirka'], $r['json']['medium']['md5'], $r['json']['medium']['tagy']], $r['body']);
    $r = api($s, 'POST', "/media/$id/soubor", $zapis, ['soubor' => soubor(mp4(100)), 'nazev' => 'v.mp4']);
    ok(str_contains($r['json']['chyba'], 'stejného druhu'));
    eq(200, api($s, 'DELETE', "/media/$id", $zapis)['code']);
    eq(404, api($s, 'GET', "/media/$id", $cteni)['code']);
    $k = (int)$s->db()->query('SELECT id FROM campaigns')->fetchColumn();
    $spot = (int)$s->db()->query("SELECT id FROM media WHERE original_name = 'spot.mp4'")->fetchColumn();
    eq($k, api($s, 'PATCH', "/media/$spot", $zapis, json: ['kampan' => $k])['json']['medium']['kampan']['id']);
    eq(1, api($s, 'GET', "/kampane/$k", $cteni)['json']['kampan']['pocet_medii']);
    $r = api($s, 'DELETE', "/kampane/$k", $zapis);
    eq([200, 1], [$r['code'], $r['json']['odpojeno_medii']]);
    eq(null, api($s, 'GET', "/media/$spot", $cteni)['json']['medium']['kampan']);
});

test('API: zámek po mnoha neplatných klíčích, protokol', function () use ($s, $b, &$cteni) {
    for ($i = 0; $i < ApiKlice::MAX_CHYB; $i++) api($s, 'GET', '/', 'tgk_' . str_repeat('b', 40));
    eq(429, api($s, 'GET', '/', $cteni)['code'], 'zamčeno i pro platný klíč');
    $s->db()->exec("DELETE FROM settings WHERE k IN ('api_fails', 'api_fail_since')");
    eq(200, api($s, 'GET', '/', $cteni)['code']);
    $r = $b->jdi('index.php?p=protokol');
    foreach (['Vytvořen API klíč „Web“', 'API (nahrání): banner.png', 'API: příliš mnoho neplatných klíčů'] as $t) ok(str_contains($r['body'], $t), $t);
});

test('nápověda k API popisuje všechny cesty', function () use ($b) {
    $md = (string)file_get_contents(KOREN . '/apps/twb-galerie/help/sprava/api.md');
    $zdroj = (string)file_get_contents(KOREN . '/apps/twb-galerie/lib/Api.php');
    preg_match_all("/case '([a-z{}\/]*)':/", $zdroj, $m);
    foreach ($m[1] as $c) ok(str_contains($md, '`/' . $c . '`'), "cesta /$c v nápovědě");
    eq(200, $b->jdi('index.php?p=napoveda&s=api')['code']);
});
