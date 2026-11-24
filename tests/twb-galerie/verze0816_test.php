<?php
declare(strict_types=1);
// TWB Media galerie 0.8.16: úvodní fotky webu na stránce Přehled; řádek ikon (Upravit, Kopírovat odkaz, Do archivu, Smazat) u karty média v Galerii.
// Poslední test běží ve skutečném prohlížeči (Playwright); s proměnnou SNIMKY=<složka> uloží snímky obrazovky.
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use MediaGalerie\{App, Web, Ui};

echo "Media galerie: 0.8.16\n";

function fotka0816(int $seme, int $w = 1600, int $h = 900): string
{
    mt_srand($seme);
    $im = imagecreatetruecolor($w, $h);
    $z = mt_rand(0, 0xFFFFFF);
    imagefill($im, 0, 0, $z);
    for ($i = 0; $i < 25; $i++) imagefilledellipse($im, mt_rand(0, $w), mt_rand(0, $h), mt_rand(80, 500), mt_rand(80, 500), mt_rand(0, 0xFFFFFF));
    $f = docasna('foto') . '.jpg';
    imagejpeg($im, $f, 88);
    return $f;
}

/** Veřejná kategorie s pěti fotkami (čtyři úvodní, jedna z nich bez odkazu), PDF bez odkazu a jedna fotka v archivu. @return array{App, array<string, int>} */
function data0816(?App $app = null): array
{
    $app ??= novaApp();
    $k = $app->kategorie()->uloz(['name' => 'Krajina']);
    $app->web()->ulozKategorii($k, ['web_public' => '1']);
    $m = $app->media();
    $ids = [];
    foreach (['Hory při západu.jpg', 'Jezero ráno.jpg', 'Les v mlze.jpg', 'Pole s máky.jpg', 'Řeka v údolí.jpg'] as $i => $nazev) {
        $ids[$i + 1] = (int)$m->nahraj(fotka0816($i + 1), $nazev, ['krajina'], $k)['id'];
    }
    foreach ([3, 1, 4, 5] as $i) $app->web()->nastavUvodni($ids[$i]);
    $m->nastavOhnisko($ids[1], 30, 20, false);
    $m->zrusOdkaz($ids[5]);                                  // zvolená, ale na webu se neukáže
    $ids['archiv'] = (int)$m->nahraj(fotka0816(9), 'Staré foto.jpg', [], $k)['id'];
    $app->galerie()->archivuj([$ids['archiv']], true);
    return [$app, $ids];
}

test('Web::uvodniFotky: pořadí z nastavení, bez fotky s vypnutým odkazem', function () {
    [$app, $ids] = data0816();
    eq([$ids[3], $ids[1], $ids[4]], array_map(fn($m) => (int)$m['id'], $app->web()->uvodniFotky()));
    eq(4, count($app->web()->nastaveni()['uvod_ids']));
});

test('0.8.16 na serveru: ikony u karty, archiv, vrácení a smazání jednoho média, hromadný výběr se nemíchá', function () {
    $s = new TestServer();
    register_shutdown_function(fn() => $s->zastav());
    $b = new Prohlizec($s);
    $b->jdi('install.php');
    $b->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'tajneheslo1']);
    $app = new App(KOREN . '/apps/twb-galerie', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
    [, $ids] = data0816($app);

    $h = $b->jdi('index.php?p=galerie&zobrazeni=mrizka')['body'];
    eq(5, substr_count($h, '<div class="akce-medium">'), 'ikony u každé karty (archiv se neukazuje)');
    ok(str_contains($h, 'title="Upravit" aria-label="Upravit Jezero ráno.jpg">'), 'Upravit');
    ok(str_contains($h, 'class="akce-ikona" data-kopirovat-text="'), 'Kopírovat odkaz');
    ok(str_contains($h, 'disabled title="Veřejný odkaz je vypnutý'), 'bez odkazu neaktivní');
    ok(str_contains($h, 'name="medium" value="archivovat:' . $ids[2] . '"'), 'Do archivu');
    ok(str_contains($h, 'name="medium" value="smazat:' . $ids[2] . '"') && str_contains($h, 'data-potvrdit-tlacitko="Smazat „Jezero ráno.jpg“?'), 'Smazat s potvrzením');
    ok(str_contains($h, '<form method="post" id="akce-medium"'), 'samostatný formulář');
    ok(!str_contains($h, '>Kopírovat odkaz</button>'), 'textové tlačítko nahradila ikona');
    ok(!preg_match('~<div class="akce-medium">(?:(?!</div>).)*target=~s', $h), 'nic v nové záložce');

    // Do archivu jednoho média – zaškrtnuté ids[] z hromadného formuláře se neposílají (jiný formulář), i kdyby přišly, rozhoduje medium
    $r = $b->odesli('index.php?p=galerie', ['medium' => 'archivovat:' . $ids[2], 'ids' => [$ids[3]], 'akce' => 'smazat']);
    ok(str_contains($r['body'], '„Jezero ráno.jpg“ je v archivu.'), 'hláška');
    $db = $s->db();
    $stav = fn(int $id) => $db->query("SELECT archived_at IS NOT NULL FROM media WHERE id = {$id}")->fetchColumn();
    eq([1, 0], [(int)$stav($ids[2]), (int)$stav($ids[3])], 'jen to jedno, hromadná akce se neprovedla');

    $a = $b->jdi('index.php?p=galerie&archiv=1')['body'];
    ok(str_contains($a, 'name="medium" value="vratit:' . $ids[2] . '"') && str_contains($a, 'title="Vrátit z archivu"'), 'v archivu Vrátit');
    $r = $b->odesli('index.php?p=galerie&archiv=1', ['medium' => 'vratit:' . $ids[2]]);
    ok(str_contains($r['body'], '„Jezero ráno.jpg“ je zpět v galerii.'));
    eq(0, (int)$stav($ids[2]));

    $r = $b->odesli('index.php?p=galerie', ['medium' => 'smazat:' . $ids[2]]);
    ok(str_contains($r['body'], '„Jezero ráno.jpg“ smazáno.'));
    eq(false, $db->query("SELECT id FROM media WHERE id = {$ids[2]}")->fetchColumn(), 'smazáno');
    $r = $b->odesli('index.php?p=galerie', ['medium' => 'smazat:' . $ids[2]]);
    ok(str_contains($r['body'], 'Médium nenalezeno.'), 'podruhé chyba');
    $r = $b->odesli('index.php?p=galerie', ['medium' => 'neco:' . $ids[3]]);
    ok(str_contains($r['body'], 'Vyberte aspoň jedno médium.'), 'neznámá akce jednoho média se neprovede');
    eq(1, (int)$db->query("SELECT COUNT(*) FROM media WHERE id = {$ids[3]}")->fetchColumn());
    // bez CSRF nic
    $c = new Prohlizec($s);
    $c->jdi('index.php?p=galerie', ['medium' => 'smazat:' . $ids[3]], false);
    eq(1, (int)$db->query("SELECT COUNT(*) FROM media WHERE id = {$ids[3]}")->fetchColumn());

    // Přehled: úvodní fotky v pořadí výběru, ohnisko, varování u zvolené fotky, která se neukáže
    $h = $b->jdi('index.php')['body'];
    ok(str_contains($h, '<h2 id="h-uvodni">Úvodní fotky webu <span class="slabe mala">3 z ' . Web::MAX_UVODNI . '</span></h2>'), 'nadpis s počtem');
    ok(str_contains($h, 'class="uvodni-fotky pocet-3" data-okno-skupina'), 'mřížka');
    preg_match_all('~<img src="media\.php\?id=(\d+)&amp;nahled=640~', $h, $mm);
    eq([$ids[3], $ids[1], $ids[4]], array_map('intval', $mm[1]), 'pořadí');
    ok(str_contains($h, 'style="object-position:30% 20%"'), 'ohnisko jako na webu');
    ok(str_contains($h, '1 zvolená fotka se na webu neukáže'), 'varování');
    ok(strpos($h, 'h-uvodni') > strpos($h, 'Naposledy nahrané'), 'pod Naposledy nahranými');
    $sekce = substr($h, (int)strpos($h, 'class="karta prehled-uvodni"'), 4000);
    ok(!str_contains(substr($sekce, 0, (int)strpos($sekce, '</section>')), 'target='), 'nic v nové záložce');
    // bez úvodních fotek: náhradní text s obálkou první veřejné galerie
    foreach ([1, 3, 4, 5] as $i) $app->web()->odeberUvodni($ids[$i]);
    $h = $b->jdi('index.php')['body'];
    ok(str_contains($h, 'Žádná úvodní fotka není zvolená – web teď ukazuje obálku první veřejné galerie'), 'náhradní text');
    ok(!str_contains($h, 'class="uvodni-fotky') && !str_contains($h, 'neukáže'));
});

test('verze 0.8.16, ikony a CSS', function () {
    ok(version_compare(trim((string)file_get_contents(KOREN . '/apps/twb-galerie/VERSION')), '0.8.16', '>='));
    foreach (['upravit', 'odkaz', 'archiv', 'vratit', 'smazat'] as $i) ok(str_contains(Ui::ikona($i), '<svg class="ikona"'), $i);
    $css = (string)file_get_contents(KOREN . '/apps/twb-galerie/assets/style.css');
    foreach (['.akce-medium', '.akce-ikona', '.uvodni-fotky', '.uvodni-nahled'] as $t) ok(str_contains($css, $t), $t);
    ok(str_contains((string)file_get_contents(KOREN . '/apps/twb-galerie/assets/app.js'), 'data-potvrdit-tlacitko'));
});

test('prohlížeč: ikony u spodního okraje karet, kopírování, potvrzení smazání; Přehled na počítači a mobilu', function () {
    $node = trim((string)shell_exec('command -v node'));
    if ($node === '' || trim((string)shell_exec('node -e "require(\'playwright\'); console.log(1)" 2>/dev/null')) !== '1') { echo "      (přeskočeno: chybí node nebo playwright)\n"; return; }
    $s = new TestServer();
    register_shutdown_function(fn() => $s->zastav());
    $app = new App(KOREN . '/apps/twb-galerie', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
    $app->migruj();
    $app->heslo()->nastav('tajneheslo1');
    $app->nastaveni()->uloz('img_auto', '0');
    [, $ids] = data0816($app);
    $app->media()->nastavDoporucene([$ids[1]], true);
    $snimky = (string)getenv('SNIMKY');
    $skript = docasna('pw') . '.js';
    $cfg = json_encode(['u' => $s->url . '/', 'smazat' => $ids[4], 'snimky' => $snimky]);
    file_put_contents($skript, <<<JS
const { chromium } = require('playwright');
const cfg = $cfg;
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1360, height: 1000 }, deviceScaleFactor: cfg.snimky ? 2 : 1 });
  await ctx.grantPermissions(['clipboard-read', 'clipboard-write']);
  const p = await ctx.newPage();
  const v = { chyby: [] };
  p.on('pageerror', e => v.chyby.push(e.message));
  p.on('console', m => { if (m.type() === 'error' && !/favicon/.test(m.text())) v.chyby.push(m.text()); });
  const foto = async (n, el) => { if (cfg.snimky) await p.mouse.move(0, 0), await p.waitForTimeout(400), await (el ? p.locator(el).first() : p).screenshot({ path: cfg.snimky + '/' + n + '.png' }); };
  const nacteno = () => p.waitForFunction(() => [...document.images].filter(i => i.getBoundingClientRect().top < innerHeight).every(i => i.complete));
  await p.goto(cfg.u);
  await p.fill('input[name=heslo]', 'tajneheslo1');
  await Promise.all([p.waitForNavigation(), p.press('input[name=heslo]', 'Enter')]);
  // Přehled
  await p.goto(cfg.u + 'index.php'); await p.locator('.prehled-uvodni').scrollIntoViewIfNeeded(); await nacteno();
  v.uvodni = await p.\$\$eval('.uvodni-fotky figure', f => f.map(e => { const r = e.getBoundingClientRect(); return [Math.round(r.top), Math.round(r.width)]; }));
  await foto('admin-prehled-uvodni', '.prehled-uvodni');
  await p.click('.uvodni-fotky figure:first-child .uvodni-nahled');
  v.okno = await p.\$eval('.okno-nahled', d => !d.hidden).catch(() => false);
  await p.keyboard.press('Escape');
  // Galerie – mřížka
  await p.goto(cfg.u + 'index.php?p=galerie&zobrazeni=mrizka'); await nacteno();
  v.spodek = await p.\$\$eval('.galerie figure', f => f.map(e => Math.round(e.getBoundingClientRect().bottom - e.querySelector('.akce-medium').getBoundingClientRect().bottom)));
  v.ikonyRadek = await p.\$\$eval('.galerie figure', f => f.map(e => Math.round(e.querySelector('.akce-medium').getBoundingClientRect().top)));
  v.radky = await p.\$\$eval('.galerie figure', f => f.map(e => Math.round(e.getBoundingClientRect().top)));
  await p.locator('.galerie [data-kopirovat-text]').first().hover();
  await foto('admin-mrizka-ikony', '.galerie-obal');
  await p.locator('.galerie [data-kopirovat-text]').first().click();
  v.zkopirovano = await p.locator('.galerie [data-kopirovat-text]').first().evaluate(e => e.classList.contains('zkopirovano') && e.title);
  v.schranka = await p.evaluate(() => navigator.clipboard.readText());
  v.ocekavano = await p.locator('.galerie [data-kopirovat-text]').first().getAttribute('data-kopirovat-text');
  await foto('admin-karta-zkopirovano', '.galerie figure:has(.zkopirovano)');
  // Smazat: Zrušit nic neudělá, OK smaže
  let dotaz = '';
  p.once('dialog', d => { dotaz = d.message(); d.dismiss(); });
  await p.click('button[value="smazat:' + cfg.smazat + '"]');
  await p.waitForTimeout(300);
  v.zruseno = [dotaz, await p.locator('button[value="smazat:' + cfg.smazat + '"]').count()];
  p.once('dialog', d => d.accept());
  await Promise.all([p.waitForNavigation(), p.click('button[value="smazat:' + cfg.smazat + '"]')]);
  v.smazano = [await p.textContent('.hlaska-ok'), await p.locator('button[value="smazat:' + cfg.smazat + '"]').count()];
  // Do archivu
  await Promise.all([p.waitForNavigation(), p.locator('.galerie figure').first().locator('button[value^="archivovat:"]').click()]);
  v.archiv = await p.textContent('.hlaska-ok');
  // Upravit vede do detailu ve stejné záložce
  await Promise.all([p.waitForNavigation(), p.locator('.galerie figure').first().locator('a.akce-ikona').click()]);
  v.detail = p.url().includes('p=medium');
  v.stranek = ctx.pages().length;
  // seznam
  await p.goto(cfg.u + 'index.php?p=galerie&zobrazeni=seznam'); await nacteno();
  await foto('admin-seznam-ikony', '.galerie-obal');
  // mobil
  await p.setViewportSize({ width: 390, height: 844 });
  await p.goto(cfg.u + 'index.php?p=galerie&zobrazeni=mrizka'); await nacteno();
  await p.locator('.galerie-obal').scrollIntoViewIfNeeded();
  await foto('admin-mrizka-mobil');
  v.mobilGalerie = await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
  await p.goto(cfg.u + 'index.php'); await p.locator('.prehled-uvodni').scrollIntoViewIfNeeded(); await nacteno();
  await foto('admin-prehled-uvodni-mobil', '.prehled-uvodni');
  v.mobilPrehled = await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
  console.log(JSON.stringify(v));
  await b.close();
})().catch(e => { console.log(JSON.stringify({ chyba: String(e).slice(0, 300) })); process.exit(1); });
JS);
    $out = (string)shell_exec('node ' . escapeshellarg($skript) . ' 2>&1');
    $v = json_decode(trim((string)strrchr("\n" . trim($out), "\n")), true);
    ok(is_array($v) && !isset($v['chyba']), $out);
    eq([], $v['chyby'], 'chyby JavaScriptu');
    eq(3, count($v['uvodni']));
    eq(1, count(array_unique(array_column($v['uvodni'], 0))), 'úvodní fotky v jednom řádku');
    ok($v['okno'], 'kliknutí otevře větší náhled');
    foreach ($v['spodek'] as $d) ok($d >= 0 && $d <= 12, "ikony u spodního okraje ($d px)");
    // v každém řádku mřížky jsou ikony ve stejné výšce
    $podleRadku = [];
    foreach ($v['radky'] as $i => $top) $podleRadku[$top][] = $v['ikonyRadek'][$i];
    foreach ($podleRadku as $r) eq(1, count(array_unique($r)), 'ikony zarovnané v řádku');
    eq('Zkopírováno', $v['zkopirovano']);
    eq($v['ocekavano'], $v['schranka'], 've schránce veřejný odkaz');
    ok(str_starts_with($v['zruseno'][0], 'Smazat „') && $v['zruseno'][1] === 1, 'Zrušit nic nesmaže');
    ok(str_contains((string)$v['smazano'][0], 'smazáno') && $v['smazano'][1] === 0, (string)$v['smazano'][0]);
    ok(str_contains((string)$v['archiv'], 'je v archivu.'), (string)$v['archiv']);
    ok($v['detail'] && $v['stranek'] === 1, 'Upravit ve stejné záložce');
    ok($v['mobilGalerie'] && $v['mobilPrehled'], 'na mobilu bez vodorovného posuvníku');
});
