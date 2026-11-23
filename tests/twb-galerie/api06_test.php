<?php
declare(strict_types=1);
// TWB Media galerie 0.6: API pro jiné aplikace – náhledy (podepsané i trvalé), licence jako kód, atribuce, rozpoznání odkazu,
// nové filtry, náhodné pořadí, ETag/304 a limit požadavků na klíč.
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use MediaGalerie\{App, Media, Schema};

echo "Media galerie: API 0.6\n";

function obrazek(int $w, int $h, string $typ = 'png'): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, (int)imagecolorallocate($im, 200, 80, 40));
    ob_start(); $typ === 'jpg' ? imagejpeg($im, null, 90) : imagepng($im); return (string)ob_get_clean();
}

/** @return array{code:int, json:mixed, body:string, type:string, hlavicky:string} */
function dotaz(string $url, array $hlavicky = [], string $metoda = 'GET', ?array $form = null, ?array $json = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30]);
    if ($form !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $form);
    if ($json !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json)); $hlavicky[] = 'Content-Type: application/json'; }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $hlavicky);
    $r = (string)curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $h = substr($r, 0, $hs);
    $body = substr($r, $hs);
    if (str_contains($body, 'Fatal error') || str_contains($body, 'Warning:')) throw new RuntimeException('Chyba PHP: ' . mb_substr(strip_tags($body), 0, 500));
    return ['code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'json' => json_decode($body, true), 'body' => $body,
        'type' => preg_match('/^Content-Type:\s*([^\r\n;]+)/mi', $h, $m) ? $m[1] : '', 'hlavicky' => $h];
}

function hlavicka(array $r, string $nazev): ?string { return preg_match('/^' . preg_quote($nazev, '/') . ':\s*([^\r\n]*)/mi', $r['hlavicky'], $m) ? $m[1] : null; }

function nahraj(TestServer $s, string $klic, string $obsah, string $nazev, array $pole = []): array
{
    $f = docasna('n');
    file_put_contents($f, $obsah);
    $r = dotaz($s->url . '/api.php/v1/media', ["Authorization: Bearer $klic"], 'POST', ['soubor' => new CURLFile($f, '', $nazev)] + $pole);
    if ($r['code'] !== 201) throw new RuntimeException('Nahrání selhalo: ' . $r['body']);
    return $r['json']['medium'];
}

// ---------- bez serveru ----------

test('orientace: na šířku, na výšku, čtverec (tolerance 2 %), bez rozměru null', function () {
    eq('na_sirku', Media::orientace(['width' => 1920, 'height' => 1080]));
    eq('na_vysku', Media::orientace(['width' => 600, 'height' => 900]));
    eq('ctverec', Media::orientace(['width' => 1000, 'height' => 1015]));
    eq(null, Media::orientace(['width' => null, 'height' => null]));
});

test('podpis náhledu: platný, vypršelý, příliš dlouhá platnost, cizí velikost, podvržený', function () {
    $app = novaApp();
    $m = $app->media();
    $ted = 1_800_000_000;
    $do = Media::platnostPodpisu($ted);
    ok($do > $ted + 3600 - 1 && $do <= $ted + 2 * 3600, 'platí 1–2 h');
    eq(0, $do % 3600, 'celá hodina');
    $p = $m->podpis(7, 320, $do);
    ok($m->overPodpis(7, 320, $do, $p, $ted));
    ok(!$m->overPodpis(7, 320, $do, $p, $do + 1), 'vypršel');
    ok(!$m->overPodpis(8, 320, $do, $p, $ted), 'jiné médium');
    ok(!$m->overPodpis(7, 640, $do, $p, $ted), 'jiná velikost');
    ok(!$m->overPodpis(7, 333, $do, $m->podpis(7, 333, $do), $ted), 'velikost mimo seznam');
    $dlouho = $do + 10 * 3600;
    ok(!$m->overPodpis(7, 320, $dlouho, $m->podpis(7, 320, $dlouho), $ted), 'delší platnost, než aplikace vydává');
    ok(!$m->overPodpis(7, 320, $do, str_repeat('0', 32), $ted));
    $app2 = novaApp();
    ok(!$app2->media()->overPodpis(7, 320, $do, $p, $ted), 'jiná instalace má jiné tajemství');
});

test('varianty náhledu: delší strana, menší obrázek = originál, úklid po nahrazení', function () {
    $app = novaApp();
    $f = docasna('v'); file_put_contents($f, obrazek(1600, 800));
    $id = $app->media()->nahraj($f, 'pas.png')['id'];
    $r = $app->media()->radek($id);
    $v = $app->media()->varianta($r, 640);
    ok($v !== null && str_ends_with($v, "/$id-{$r['md5']}-640.jpg"));
    eq([640, 320], array_slice((array)getimagesize($v), 0, 2));
    eq([320, 160], array_slice((array)getimagesize((string)$app->media()->varianta($r, 320)), 0, 2));
    eq(null, $app->media()->varianta($r, 1920), 'menší než 1920 → originál');
    $f2 = docasna('v2'); file_put_contents($f2, obrazek(1000, 1000));
    $app->media()->nahraj($f2, 'pas.png', [], null, $id);
    $r2 = $app->media()->radek($id);
    $v2 = (string)$app->media()->varianta($r2, 640);
    ok(!is_file($v), 'náhled starého obsahu smazán');
    eq([640, 640], array_slice((array)getimagesize($v2), 0, 2));
    file_put_contents($app->data . '/nahledy/999-' . str_repeat('a', 32) . '-640.jpg', 'x');
    file_put_contents($app->data . '/nahledy/' . $id . '-' . $r2['md5'] . '.jpg', 'starý formát 0.5');
    eq(2, $app->media()->uklidNahledy());
    ok(is_file($v2));
});

test('licence: kód z metadat při nahrání, nastavení s ověřením, atribuce', function () {
    $app = novaApp();
    $f = docasna('m');
    file_put_contents($f, jpegMeta([], '><dc:creator><rdf:Seq><rdf:li>Eva Malá</rdf:li></rdf:Seq></dc:creator>'
        . '<xmpRights:UsageTerms><rdf:Alt><rdf:li xml:lang="x-default">Creative Commons Attribution-ShareAlike 4.0</rdf:li></rdf:Alt></xmpRights:UsageTerms>'));
    $id = $app->media()->nahraj($f, 'hory.jpg')['id'];
    $r = $app->media()->radek($id);
    eq(['cc-by-sa', '4.0'], [$r['license_code'], $r['license_version']]);
    eq(['text' => 'Foto: Eva Malá, CC BY-SA 4.0', 'html' => 'Foto: Eva Malá, <a href="https://creativecommons.org/licenses/by-sa/4.0/" target="_blank" rel="noopener license">CC BY-SA 4.0</a>', 'povinne' => true],
        Media::uvedeni($r));
    $app->media()->nastavLicenci($id, 'vlastni', '4.0');
    eq(['vlastni', null], array_values(array_intersect_key($app->media()->radek($id), ['license_code' => 1, 'license_version' => 1])));
    throws(fn() => $app->media()->nastavLicenci($id, 'cc-by-nc', null), 'Neznámý kód');
    $app->media()->nastavLicenci($id, null, null);
    eq(null, $app->media()->radek($id)['license_code']);
});

test('schéma 6: převod dosavadního textu licence na kód, limit požadavků v tabulce klíčů', function () {
    $app = novaApp();
    ok(Schema::verze() >= 6);
    $app->db->q("INSERT INTO media (filename, original_name, kind, mime, size, md5, created_at, license, license_url) VALUES
        ('a', 'a.jpg', 'image', 'image/jpeg', 1, 'x', '2026-01-01 00:00:00', 'CC BY 4.0', NULL),
        ('b', 'b.jpg', 'image', 'image/jpeg', 1, 'y', '2026-01-01 00:00:00', NULL, 'https://creativecommons.org/publicdomain/zero/1.0/'),
        ('c', 'c.jpg', 'image', 'image/jpeg', 1, 'z', '2026-01-01 00:00:00', '© Petr', NULL)");
    $app->db->q('UPDATE media SET license_code = NULL, license_version = NULL');
    Schema::proved($app->db, 5);
    eq([['cc-by', '4.0'], ['cc0', null], [null, null]], array_map(fn($r) => [$r['license_code'], $r['license_version']], $app->db->q('SELECT * FROM media ORDER BY id')->fetchAll()));
    ok(in_array('rate_count', array_column($app->db->q('PRAGMA table_info(api_keys)')->fetchAll(), 'name'), true));
});

test('limit požadavků na klíč: okno minuty, zbývá, Retry-After, 0 = bez limitu', function () {
    $app = novaApp(['api_limit_za_minutu' => 3]);
    $k = $app->apiKlice();
    $k->vytvor('X', 'read');
    $klic = $app->db->q('SELECT * FROM api_keys')->fetch();
    $t = 1_800_000_010;
    eq([[2, 0], [1, 0], [0, 0], [0, 40]], [$k->pocitej($klic, $t), $k->pocitej($klic, $t), $k->pocitej($klic, $t + 1), $k->pocitej($klic, $t + 10)]);
    eq([2, 0], $k->pocitej($klic, $t + 60), 'nová minuta');
    eq([-1, 0], novaApp(['api_limit_za_minutu' => 0])->apiKlice()->pocitej($klic));
});

// ---------- web ----------

$s = new TestServer();
register_shutdown_function(fn() => $s->zastav());
$b = new Prohlizec($s);
$b->jdi('install.php');
$b->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'tajneheslo1']);
$app = new App(KOREN . '/apps/twb-galerie', require $s->config);
$zapis = $app->apiKlice()->vytvor('Zápis', 'write');
$cteni = $app->apiKlice()->vytvor('Social Planner', 'read');
$H = ["Authorization: Bearer $cteni"];
$A = $s->url . '/api.php/v1';
$id = [];

test('nahrání tří obrázků a údaje pro jiné aplikace (náhledy, orientace, atribuce, stránka)', function () use ($s, $zapis, $A, $H, &$id) {
    $kat = dotaz("$A/kategorie", ["Authorization: Bearer $zapis"], 'POST', null, ['nazev' => 'Léto 2026'])['json']['kategorie']['id'];
    $id['sirka'] = nahraj($s, $zapis, obrazek(2400, 1200, 'jpg'), 'pláž.jpg', ['tagy' => 'moře, léto', 'kategorie' => $kat])['id'];
    $id['vyska'] = nahraj($s, $zapis, obrazek(600, 900), 'věž.png', ['tagy' => 'město, léto'])['id'];
    $id['ctverec'] = nahraj($s, $zapis, obrazek(500, 500), 'logo.png', ['tagy' => 'logo'])['id'];
    $r = dotaz("$A/media/{$id['sirka']}", ["Authorization: Bearer $zapis"], 'PATCH', null, ['autor' => 'Eva Malá', 'licence_kod' => 'cc-by', 'licence_verze' => '4.0']);
    eq(200, $r['code'], $r['body']);
    $m = dotaz("$A/media/{$id['sirka']}", $H)['json']['medium'];
    eq(['cc-by', '4.0', 'CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/', 'na_sirku'], [$m['licence_kod'], $m['licence_verze'], $m['licence_nazev'], $m['licence_odkaz'], $m['orientace']]);
    eq(['text' => 'Foto: Eva Malá, CC BY 4.0', 'povinne' => true], array_intersect_key($m['atribuce'], ['text' => 1, 'povinne' => 1]));
    eq("{$s->url}/index.php?p=medium&id={$id['sirka']}", $m['stranka_url']);
    eq([150, 320, 640, 1280, 1920], array_keys($m['nahledy']));
    ok(str_contains($m['nahledy']['320'], '/soubor.php/') && str_contains($m['nahledy']['320'], '/w320/'), 'veřejné médium má trvalé adresy');
    eq(null, $m['nahledy_plati_do']);
    eq(['moře', 'léto'], $m['tagy']);
    eq(400, dotaz("$A/media/{$id['sirka']}", ["Authorization: Bearer $zapis"], 'PATCH', null, ['licence_kod' => 'cc-by-nd'])['code']);
});

test('trvalý náhled přes veřejný odkaz: zmenšený JPEG, CORS, mezipaměť napořád jen se správným ?v=', function () use ($A, $H, &$id) {
    $m = dotaz("$A/media/{$id['sirka']}", $H)['json']['medium'];
    $r = dotaz($m['nahledy']['640']);
    eq([200, 'image/jpeg', '*'], [$r['code'], $r['type'], hlavicka($r, 'Access-Control-Allow-Origin')]);
    eq([640, 320], array_slice((array)getimagesizefromstring($r['body']), 0, 2));
    ok(str_contains((string)hlavicka($r, 'Cache-Control'), 'immutable'));
    $bezV = dotaz(preg_replace('/\?v=.*$/', '', $m['nahledy']['640']));
    eq('public, max-age=3600', hlavicka($bezV, 'Cache-Control'));
    $velky = dotaz($m['nahledy']['1920']);
    eq([1920, 960], array_slice((array)getimagesizefromstring($velky['body']), 0, 2));
    $mensi = dotaz(dotaz("$A/media/{$id['ctverec']}", $H)['json']['medium']['nahledy']['640']);
    eq(['image/png', 500], [$mensi['type'], getimagesizefromstring($mensi['body'])[0]], 'menší obrázek = originál');
    eq(200, dotaz(str_replace('/w640/', '/w333/', $m['nahledy']['640']))['code'], 'neznámá velikost = celý soubor');
});

test('médium bez veřejného odkazu: podepsané adresy náhledu, platnost, podvržený podpis 404', function () use ($A, $H, $zapis, &$id) {
    dotaz("$A/media/{$id['vyska']}/odkaz", ["Authorization: Bearer $zapis"], 'DELETE');
    $m = dotaz("$A/media/{$id['vyska']}", $H)['json']['medium'];
    eq(null, $m['verejny_odkaz']);
    ok(str_contains($m['nahledy']['320'], '/nahled.php/' . $id['vyska'] . '/320/'));
    $do = strtotime((string)$m['nahledy_plati_do']);
    ok($do > time() + 3000 && $do <= time() + 7200);
    $r = dotaz($m['nahledy']['320']);
    eq([200, 'image/jpeg', '*'], [$r['code'], $r['type'], hlavicka($r, 'Access-Control-Allow-Origin')]);
    eq([213, 320], array_slice((array)getimagesizefromstring($r['body']), 0, 2));
    ok(preg_match('/max-age=(\d+)/', (string)hlavicka($r, 'Cache-Control'), $mx) && (int)$mx[1] <= 7200);
    eq(404, dotaz(preg_replace('~/[0-9a-f]{32}/~', '/' . str_repeat('a', 32) . '/', $m['nahledy']['320']))['code']);
    eq(404, dotaz(str_replace('/320/', '/640/', $m['nahledy']['320']))['code'], 'podpis platí jen pro svou velikost');
    eq(404, dotaz(str_replace('/nahled.php/' . $id['vyska'] . '/', '/nahled.php/' . $id['sirka'] . '/', $m['nahledy']['320']))['code']);
});

test('stažení přes API ve zvolené velikosti (?sirka=), neplatná velikost 400', function () use ($A, $H, &$id) {
    $r = dotaz("$A/media/{$id['sirka']}/soubor?sirka=1280", $H);
    eq([200, 'image/jpeg', [1280, 640]], [$r['code'], $r['type'], array_slice((array)getimagesizefromstring($r['body']), 0, 2)]);
    eq(2400, getimagesizefromstring(dotaz("$A/media/{$id['sirka']}/soubor", $H)['body'])[0]);
    eq(400, dotaz("$A/media/{$id['sirka']}/soubor?sirka=500", $H)['code']);
});

test('filtry: orientace, min. rozměry, více tagů (všechny / kterýkoli), kategorie podle názvu, výběr podle id', function () use ($A, $H, &$id) {
    $ids = fn(string $q) => array_column(dotaz("$A/media?$q", $H)['json']['media'] ?? [], 'id');
    eq([$id['sirka']], $ids('orientace=na_sirku'));
    eq([$id['vyska']], $ids('orientace=na_vysku'));
    eq([$id['ctverec']], $ids('orientace=ctverec'));
    eq([$id['vyska'], $id['sirka']], $ids('min_vyska=800'));
    eq([$id['sirka']], $ids('min_sirka=1000&min_vyska=800'));
    eq([$id['vyska'], $id['sirka']], $ids('tagy=' . rawurlencode('léto')));
    eq([$id['vyska']], $ids('tagy=' . rawurlencode('Léto,město')));
    eq([$id['ctverec'], $id['sirka']], $ids('tagy=' . rawurlencode('logo,moře') . '&tagy_rezim=kterykoli'));
    eq([$id['sirka']], $ids('kategorie=' . rawurlencode('léto 2026')));
    eq([$id['ctverec'], $id['sirka']], $ids("id={$id['ctverec']},{$id['sirka']},9999"), 'pořadí podle seznamu');
    eq([$id['sirka'], $id['ctverec']], $ids("id={$id['ctverec']},{$id['sirka']}&razeni=stare"));
    eq(400, dotaz("$A/media?orientace=sikmo", $H)['code']);
    eq(400, dotaz("$A/media?min_sirka=abc", $H)['code']);
    eq(400, dotaz("$A/media?tagy_rezim=nekdy", $H)['code']);
    eq(404, dotaz("$A/media?kategorie=Neexistuje", $H)['code']);
    eq(400, dotaz("$A/media?id=" . implode(',', range(1, 201)), $H)['code']);
});

test('náhodné pořadí: vrací semeno, stejné semeno = stejné pořadí i přes stránky', function () use ($A, $H) {
    $r = dotaz("$A/media?razeni=nahodne", $H)['json'];
    ok(preg_match('/^[0-9a-f]{8}$/', (string)$r['seme']) === 1);
    $poradi = fn(string $seme) => array_column(dotaz("$A/media?razeni=nahodne&seme=$seme", $H)['json']['media'], 'id');
    eq($poradi('abc'), $poradi('abc'));
    $vse = $poradi('xyz');
    $po1 = array_merge(...array_map(fn($st) => array_column(dotaz("$A/media?razeni=nahodne&seme=xyz&na_stranku=1&strana=$st", $H)['json']['media'], 'id'), [1, 2, 3]));
    eq($vse, $po1);
    $ruzna = []; foreach (range(1, 12) as $i) $ruzna[implode(',', $poradi("s$i"))] = true;
    ok(count($ruzna) > 1, 'různá semena dávají různá pořadí');
});

test('rozpoznání odkazu: detail, veřejný odkaz, náhled, API; cizí server a neznámý odkaz se odmítnou', function () use ($s, $A, $H, &$id) {
    $m = dotaz("$A/media/{$id['sirka']}", $H)['json']['medium'];
    $v = dotaz("$A/media/{$id['vyska']}", $H)['json']['medium'];
    $najdi = fn(string $url) => dotaz("$A/media/odkaz?url=" . rawurlencode($url), $H);
    foreach ([$m['stranka_url'], $m['verejny_odkaz'], $m['nahledy']['640'], $m['soubor'], "{$s->url}/api.php/v1/media/{$id['sirka']}", "{$s->url}/soubor.php?k=" . substr((string)parse_url($m['verejny_odkaz'], PHP_URL_PATH), 12, 24)] as $u) {
        $r = $najdi($u);
        eq([200, $id['sirka']], [$r['code'], $r['json']['medium']['id'] ?? null], $u);
    }
    eq($id['vyska'], $najdi($v['nahledy']['150'])['json']['medium']['id']);
    $r = $najdi(str_replace('127.0.0.1', 'galerie.example', $m['stranka_url']));
    eq(400, $r['code']);
    ok(str_contains($r['json']['chyba'], 'nevede do této galerie'));
    eq(400, $najdi("{$s->url}/index.php?p=galerie")['code']);
    eq(404, $najdi("{$s->url}/index.php?p=medium&id=9999")['code']);
    eq(404, $najdi("{$s->url}/soubor.php/" . str_repeat('ab', 12) . '/x.jpg')['code']);
    eq(400, $najdi('')['code']);
});

test('číselník licencí přes API', function () use ($A, $H) {
    $l = dotaz("$A/licence", $H)['json']['licence'];
    eq(array_keys(\TWB\Licence\Licence::KODY), array_column($l, 'kod'));
    eq(['kod' => 'cc-by', 'nazev' => 'CC BY', 's_verzi' => true, 'povinne_uvedeni' => true], array_intersect_key($l[1], ['kod' => 1, 'nazev' => 1, 's_verzi' => 1, 'povinne_uvedeni' => 1]));
});

test('ETag a 304 u čtení, žádný ETag u zápisu; hlavičky limitu', function () use ($A, $H, $zapis, &$id) {
    $r = dotaz("$A/media?orientace=na_sirku", $H);
    $e = (string)hlavicka($r, 'ETag');
    ok(preg_match('/^"[0-9a-f]{24}"$/', $e) === 1);
    eq('private, no-cache', hlavicka($r, 'Cache-Control'));
    $r2 = dotaz("$A/media?orientace=na_sirku", array_merge($H, ["If-None-Match: $e"]));
    eq([304, ''], [$r2['code'], $r2['body']]);
    eq(200, dotaz("$A/media?orientace=na_vysku", array_merge($H, ["If-None-Match: $e"]))['code']);
    eq('300', hlavicka($r, 'X-RateLimit-Limit'));
    ok((int)hlavicka($r, 'X-RateLimit-Remaining') < 300);
    $w = dotaz("$A/media/{$id['ctverec']}", ["Authorization: Bearer $zapis"], 'PATCH', null, ['poznamka' => 'x']);
    eq([200, null], [$w['code'], hlavicka($w, 'ETag')]);
});

test('limit požadavků: 429 s Retry-After', function () use ($b) {
    $s2 = new TestServer(['api_limit_za_minutu' => 2]);
    $b2 = new Prohlizec($s2);
    $b2->jdi('install.php');
    $b2->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'tajneheslo1']);
    $k = (new App(KOREN . '/apps/twb-galerie', require $s2->config))->apiKlice()->vytvor('X', 'read');
    $kody = [];
    // přelom minuty by počítadlo vynuloval – pokus se v takovém případě opakuje
    for ($pokus = 0; $pokus < 2; $pokus++) {
        $kody = [];
        foreach (range(1, 3) as $i) $r = dotaz("{$s2->url}/api.php/v1/", ["Authorization: Bearer $k"]) and $kody[] = $r['code'];
        if ($kody === [200, 200, 429]) break;
    }
    eq([200, 200, 429], $kody);
    ok((int)hlavicka($r, 'Retry-After') >= 1 && (int)hlavicka($r, 'Retry-After') <= 60);
    ok(str_contains($r['json']['chyba'], 'nejvýš 2 za minutu'));
    $s2->zastav();
});

test('detail média: výběr licence s verzí, zobrazení uvedení autora, chyba u verze CC', function () use ($b, &$id) {
    $r = $b->jdi("index.php?p=medium&id={$id['vyska']}");
    ok(str_contains($r['body'], 'name="license_code"') && str_contains($r['body'], 'Pexels licence'));
    $b->jdi("index.php?p=medium&id={$id['vyska']}", ['akce' => 'ulozit', 'nazev' => 'věž.png', 'tagy' => 'město', 'author' => 'Jan', 'license_code' => 'cc-by-sa', 'license_version' => '3.0']);
    $r = $b->jdi("index.php?p=medium&id={$id['vyska']}");
    ok(str_contains($r['body'], 'Foto: Jan, CC BY-SA 3.0') && str_contains($r['body'], 'licence ho vyžaduje'), 'uvedení autora');
    ok(str_contains($r['body'], 'https://creativecommons.org/licenses/by-sa/3.0/'));
    $r = $b->jdi("index.php?p=medium&id={$id['vyska']}", ['akce' => 'ulozit', 'nazev' => 'věž.png', 'license_code' => 'cc-by', 'license_version' => 'nová']);
    ok(str_contains($r['body'], 'Verze licence CC je číslo'));
});
