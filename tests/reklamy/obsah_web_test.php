<?php
declare(strict_types=1);
// TWB Reklamy 0.3: editor slidů (api.php, slide.php), HTML5 balíčky (h5.php), slide v doručování, stránka Proměnné – na webu a v prohlížeči.
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use Reklamy\App;

echo "Reklamy: obsah banerů na webu\n";
$s = new TestServer();
register_shutdown_function(fn() => $s->zastav());
$app = new App(KOREN . '/apps/reklamy', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
$app->migruj();
$app->nastaveni()->uloz('click_param', '0'); // token twbr v cíli prokliku zkouší konverze_web_test
$app->heslo()->nastav('tajneheslo1');
$b = new Prohlizec($s);
$kid = kampan($app, ['name' => 'Obsah']);
$web = $app->weby()->uloz(['name' => 'Web']);
$zona = $app->zony()->uloz(['site_id' => $web, 'name' => 'Čtverec', 'width' => 300, 'height' => 250]);
$app->zony()->prirad($zona, [$kid], []);

/** ZIP z polí [cesta => obsah]. */
function zip5(array $soubory): string
{
    $f = docasna('zip') . '.zip';
    $z = new ZipArchive();
    $z->open($f, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($soubory as $n => $o) $z->addFromString($n, $o);
    $z->close();
    return $f;
}

test('API editoru jen po přihlášení a s CSRF', function () use ($b) {
    eq(401, $b->jdi('api.php?a=fonts')['code']);
    $b->jdi('index.php');
    eq(303, $b->jdi('index.php', ['akce' => 'prihlasit', 'heslo' => 'tajneheslo1'])['code']);
    $b->jdi('index.php');
    eq(200, $b->jdi('api.php?a=fonts')['code']);
    eq(403, $b->jdi('api.php?a=slide_preview', json: '{}')['code'], 'bez hlavičky X-CSRF');
    eq(404, $b->jdi('api.php?a=neexistuje')['code']);
    eq(405, $b->jdi('api.php?a=slide_save')['code'], 'uložení jen POST');
});

$api = function (string $akce, ?array $telo = null) use ($b): array {
    if ($telo !== null) $b->hlavicky = ['X-CSRF: ' . $b->csrf];
    $r = $b->jdi('api.php?a=' . $akce, json: $telo !== null ? json_encode($telo) : null);
    return ['code' => $r['code'], 'json' => json_decode($r['body'], true), 'raw' => $r];
};

test('formáty plátna, písma, styly, data, náhled a dosazení textů', function () use ($api, $b, $app) {
    $f = $api('slide_formats')['json'];
    eq(['key' => '300x250', 'w' => 300, 'h' => 250, 'name' => 'Medium rectangle'], $f[0]);
    ok(!in_array('vlastni', array_column($f, 'key'), true));
    eq(['key' => 'vlastni', 'w' => 333, 'h' => 111, 'name' => 'Vlastní rozměr'], array_values(array_slice($api('slide_formats&w=333&h=111')['json'], -1))[0]);
    $fonts = $api('fonts')['json'];
    eq('roboto', $fonts[0]['key']);
    $r = $b->jdi($fonts[0]['files'][700]);
    eq([200, 'font/ttf'], [$r['code'], $r['type']], 'vestavěné písmo pro editor');
    eq(404, $b->jdi('api.php?a=pismo&soubor=../../config&tloustka=700')['code']);
    ok(isset($api('fx_styles')['json']['styles']['zlato']));
    $app->tabulkaDat()->uloz(null, 'Akce', '2026-12-24', 'Vánoce');
    eq(['Akce', '2026-12-24', 'Vánoce', '2026-12-24'], array_values(array_intersect_key($api('data_list')['json']['rows'][0], array_flip(['grp', 'param', 'value', 'date']))));
    eq(['sources' => []], $api('source_tree')['json']);
    eq(404, $api('weather_settings')['code'], 'počasí aplikace nemá – editor ho skryje');
    // datum:<arg> bere jen posun ve dnech od dneška (pevné datum by se ignorovalo a test by platil jen v jeden den)
    eq([(new DateTimeImmutable('+1 day'))->format('j. n. Y'), null], $api('datefmt', ['expr' => 'datum:+1', 'formats' => ['', '|neznamyfiltr{']])['json']['values']);
    $d = ['format' => '300x250', 'elements' => [['id' => 't1', 'type' => 'text', 'text' => 'Svátek {svatek}', 'x' => 0, 'y' => 0, 'w' => 300, 'h' => 100, 'size' => 30]]];
    $r = $api('resolve', ['design' => $d])['json'];
    // svátek se bere k dnešku – očekávaný text dá stejný výpočet v testu, jen bez editoru a HTTP
    $svatek = $app->promenne()->vyres('{{svatek}}', new DateTimeImmutable())[0];
    ok($svatek !== '' && !str_contains($svatek, '{'), 'svátek dosazen');
    eq('Svátek ' . $svatek, $r['t1']['text']);
    ok($r['t1']['layout']['lines'][0]['w'] > 50 && !isset($r['t1']['layout']['font']), 'rozvržení bez cesty k písmu na serveru');
    $img = $api('slide_preview', ['design' => $d])['json']['image'];
    ok(str_starts_with($img, 'data:image/jpeg;base64,'));
    eq([300, 250], array_slice(getimagesizefromstring(base64_decode(substr($img, 23))), 0, 2));
});

test('uložení slidu z editoru: nový banner v kampani, úprava, banner a doručování', function () use ($api, $b, $app, $kid, $zona, $s) {
    $d = ['format' => '728x90', 'elements' => [['id' => 't1', 'type' => 'text', 'text' => 'Leaderboard', 'x' => 0, 'y' => 0, 'w' => 700, 'h' => 80, 'size' => 40]]];
    eq(422, $api('slide_save', ['id' => 0, 'kampan' => $kid, 'name' => ' ', 'design' => $d])['code']);
    $r = $api('slide_save', ['id' => 0, 'kampan' => $kid, 'name' => 'Leader', 'design' => $d])['json'];
    $id = $r['media']['id'];
    eq(['Leader', 728, 90], [$r['media']['name'], $r['media']['width'], $r['media']['height']]);
    $g = $api("slide_get&id=$id")['json'];
    eq(['Leader', '728x90', 'Leaderboard'], [$g['name'], $g['design']['format'], $g['design']['elements'][0]['text']]);
    eq(200, $b->jdi($g['url'])['code'], 'náhled slidu v administraci');
    $d['format'] = '300x250';
    $api('slide_save', ['id' => $id, 'name' => 'Čtverec', 'design' => $d]);
    $bn = $app->bannery()->radek($id);
    eq(['Čtverec', 300, 250, (int)$kid], [$bn['name'], (int)$bn['width'], (int)$bn['height'], (int)$bn['campaign_id']]);
    $r = $b->jdi("index.php?p=banner&id=$id");
    ok(str_contains($r['body'], 'slide.php?id=' . $id) && str_contains($r['body'], 'media.php?slide=' . $id));
    $r = $b->jdi("slide.php?id=$id");
    ok(str_contains($r['body'], 'asset.php?f=slide-editor.js') && str_contains($r['body'], '"kampan":' . $kid));
    eq('text/javascript', $b->jdi('asset.php?f=slide-editor.js')['type']);
    eq(404, $b->jdi('asset.php?f=../../config.php')['code']);
    // doručení: obrázek přes b.php s klíčem obsahu, i vložením odkazem
    $j = json_decode($b->jdi("d/data.php?zona=$zona")['body'], true)['zony'][0];
    eq([$id, 'slide'], [$j['banner']['id'], $j['banner']['typ']]);
    $r = $b->jdi(substr($j['banner']['obrazek'], strlen($s->url)));
    eq([200, 'image/jpeg'], [$r['code'], $r['type']]);
    ok(str_contains($b->posledniHlavicky, 'immutable'));
    eq('image/jpeg', $b->jdi("d/obr.php?zona=$zona&n=x1")['type'], 'slide jde vložit i odkazem (e-mail)');
});

test('HTML5 banner přes formulář: nahrání ZIP, vydání souborů, clickTag, izolace dokumentu', function () use ($b, $app, $kid, $zona, $s) {
    $zip = zip5(['banner/index.html' => '<!doctype html><html><head><meta name="ad.size" content="width=300,height=250"><link rel="stylesheet" href="s.css"></head>'
        . '<body><a id="cta" href="#" onclick="window.open(window.clickTag);return false">Koupit</a></body></html>', 'banner/s.css' => 'a{color:red}']);
    $r = $b->odesli("index.php?p=banner&kampan=$kid&typ=html5", ['akce' => 'ulozit', 'name' => 'H5', 'target_url' => 'https://kazbo.eu/h5', 'status' => 'active', 'target' => '_blank',
        'balicek' => new CURLFile($zip, 'application/zip', 'banner.zip')]);
    ok(str_contains($r['body'], 'Banner uložen'), mb_substr(strip_tags($r['body']), 0, 500));
    $id = (int)$app->db->q("SELECT id FROM banners WHERE type = 'html5'")->fetchColumn();
    $bn = $app->bannery()->radek($id);
    eq([300, 250], [(int)$bn['width'], (int)$bn['height']]);
    ok(str_contains($r['body'], 'enctype="multipart/form-data"') && str_contains($r['body'], 'banner/index.html'));
    $md5 = md5_file($zip);
    $r = $b->jdi("d/h5.php/$md5/banner/s.css");
    eq(['text/css', 'a{color:red}'], [$r['type'], $r['body']]);
    ok(str_contains($b->posledniHlavicky, 'Access-Control-Allow-Origin: *') && str_contains($b->posledniHlavicky, 'immutable'));
    eq(404, $b->jdi("d/h5.php/$md5/banner/../../reklamy.sqlite")['code']);
    eq(404, $b->jdi("d/h5.php/$md5/banner/neni.css")['code']);
    eq(404, $b->jdi('d/h5.php/' . str_repeat('a', 32) . '/banner/s.css')['code']);
    // bez podpisu (náhled): clickTag = cíl; s podpisem: přes klik.php
    $r = $b->jdi("d/h5.php/$md5/banner/index.html");
    ok(str_contains($r['body'], '("https://kazbo.eu/h5")'));
    ok(str_contains($b->posledniHlavicky, 'Content-Security-Policy: sandbox allow-scripts'), 'dokument je izolovaný od administrace');
    $app->bannery()->nastavStav((int)$app->db->q("SELECT id FROM banners WHERE type = 'slide'")->fetchColumn(), 'paused');
    $j = json_decode($b->jdi("d/data.php?zona=$zona")['body'], true)['zony'][0];
    eq($id, $j['banner']['id']);
    $r = $b->jdi(substr($j['banner']['dokument'], strlen($s->url)));
    ok(preg_match('~\("(' . preg_quote($s->url, '~') . '/d/klik\.php\?b=' . $id . '[^"]+)"\)~', $r['body'], $m) === 1, $r['body']);
    $klik = json_decode('"' . $m[1] . '"');
    $r = $b->jdi(substr($klik, strlen($s->url)));
    eq([302, 'https://kazbo.eu/h5'], [$r['code'], $r['location']], 'clickTag vede přes počítání na cíl');
});

test('stránka Proměnné: vlastní proměnná, řádek dat, import, zkouška textu', function () use ($b, $app) {
    $r = $b->odesli('index.php?p=promenne', ['akce' => 'promenna', 'name' => 'kod', 'type' => 'text', 'value' => 'SLEVA20']);
    ok(str_contains($r['body'], '{kod}') && str_contains($r['body'], 'SLEVA20'));
    $r = $b->odesli('index.php?p=promenne', ['akce' => 'promenna', 'name' => 'datum', 'type' => 'text', 'value' => 'x']);
    ok(str_contains($r['body'], 'vyhrazený') && str_contains($r['body'], 'value="datum"'), 'chyba a vyplněný formulář');
    $r = $b->odesli('index.php?p=promenne', ['akce' => 'import', 'text' => "Akce\tsleva\t20 %\nAkce\tdo\t30. 11. 2026"]);
    ok(str_contains($r['body'], 'Vloženo řádků: 2'));
    $r = $b->odesli('index.php?p=promenne', ['akce' => 'zkusit', 'text' => 'Kód {kod}, sleva {Akce:sleva} {nic}']);
    ok(str_contains($r['body'], 'Kód SLEVA20, sleva 20 % {nic}') && str_contains($r['body'], 'Neznámé proměnné: {nic}'));
    $ids = array_column($app->tabulkaDat()->radky(true), 'id');
    $r = $b->odesli('index.php?p=promenne', ['akce' => 'radky-smazat', 'ids' => $ids]);
    ok(str_contains($r['body'], 'Smazáno řádků: ' . count($ids)));
    $r = $b->odesli('index.php?p=promenne', ['akce' => 'promenna-smazat', 'name' => 'kod']);
    ok(!str_contains($r['body'], 'SLEVA20'));
});

// ---------- skutečný prohlížeč: editor slidů a HTML5 v rámu ----------
$node = trim((string)shell_exec('command -v node'));
$pw = $node !== '' && trim((string)shell_exec('node -e "require(\'playwright\'); console.log(1)" 2>/dev/null')) === '1';
test('ve skutečném prohlížeči: editor slidu nakreslí plátno a uloží nový banner; HTML5 dostane clickTag', function () use ($pw, $s, $b, $app, $kid, $zona) {
    if (!$pw) { echo "      (přeskočeno: chybí node nebo playwright)\n"; return; }
    $cookies = [];
    foreach (file($b->jar()) ?: [] as $l) {
        $c = explode("\t", trim(preg_replace('/^#HttpOnly_/', '', $l)));
        if (count($c) === 7 && !str_starts_with($l, '# ')) $cookies[] = ['name' => $c[5], 'value' => $c[6], 'domain' => '127.0.0.1', 'path' => $c[2]];
    }
    $skript = docasna('pw') . '.js';
    $cookiesJs = json_encode($cookies);
    $url = $s->url;
    file_put_contents($skript, <<<JS
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36' });
  await ctx.addCookies($cookiesJs);
  const p = await ctx.newPage();
  const chyby = [];
  p.on('pageerror', e => chyby.push(e.message));
  p.on('console', m => { if (m.type() === 'error' && !/favicon|404/.test(m.text())) chyby.push(m.text()); });
  const v = {};
  await p.goto('$url/slide.php?kampan=$kid&w=468&h=60');
  await p.waitForSelector('#seCanvas', { timeout: 15000 });
  v.platno = await p.\$eval('#seCanvas', c => [c.width, c.height]);
  v.format = await p.\$eval('#seFmt', s => s.value);
  v.tagy = await p.\$eval('#seTags', e => getComputedStyle(e).display);
  await p.fill('#seName', 'Z prohlížeče');
  await p.click('#seSave');
  await p.waitForSelector('.toast', { timeout: 15000 });
  v.toast = await p.\$eval('.toast', e => e.textContent);
  v.adresa = p.url();
  await p.goto('$url/d/ram.php?zona=$zona');
  const f = p.frames().find(x => x.url().includes('/d/h5.php/'));
  v.h5 = f ? await f.evaluate(() => { window.clickTag = 'prepsano'; let c = 'nic'; try { c = document.cookie; } catch (e) { c = 'zakazano'; } return [window.clickTag, c]; }) : null;
  v.chyby = chyby;
  console.log(JSON.stringify(v));
  await b.close();
})().catch(e => { console.log(JSON.stringify({ chyba: String(e) })); process.exit(1); });
JS);
    $out = (string)shell_exec('node ' . escapeshellarg($skript) . ' 2>&1');
    $v = json_decode(trim((string)strrchr("\n" . trim($out), "\n")), true);
    ok(is_array($v) && !isset($v['chyba']), $out);
    eq([468, 60], $v['platno']);
    eq('468x60', $v['format']);
    eq('none', $v['tagy'], 'tagy slidu bannery nemají');
    ok(str_contains($v['toast'], 'Z prohlížeče'), $out);
    $id = (int)$app->db->q("SELECT id FROM banners WHERE name = 'Z prohlížeče'")->fetchColumn();
    ok($id > 0 && str_ends_with($v['adresa'], "slide.php?id=$id"), 'nový banner a adresa editoru');
    eq([468, 60], [(int)$app->bannery()->radek($id)['width'], (int)$app->bannery()->radek($id)['height']]);
    ok(is_array($v['h5']) && str_contains((string)$v['h5'][0], '/d/klik.php?b='), 'clickTag nejde přepsat: ' . $out);
    eq('zakazano', $v['h5'][1], 'banner nemá přístup k cookies administrace (izolace sandbox)');
    eq([], $v['chyby']);
});
