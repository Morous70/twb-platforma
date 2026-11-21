<?php
declare(strict_types=1);
// TWB Reklamy 0.6: vzhled administrace (postranní menu, lišta, dlaždice) – HTML ze serveru, styly bez souborů z internetu, menu ve skutečném prohlížeči.
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use Reklamy\{App, Ui};

echo "Reklamy: vzhled administrace\n";
$s = new TestServer();
register_shutdown_function(fn() => $s->zastav());
$app = new App(KOREN . '/apps/reklamy', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
$app->migruj();
$app->heslo()->nastav('tajneheslo1');
$b = new Prohlizec($s);

test('postranní menu: všechny stránky ve skupinách, s ikonou, aktivní položka', function () use ($b, $app) {
    $b->jdi('index.php');
    eq(303, $b->jdi('index.php', ['akce' => 'prihlasit', 'heslo' => 'tajneheslo1'])['code']);
    $r = $b->jdi('index.php?p=kampan');
    eq(200, $r['code']);
    ok(str_contains($r['body'], '<nav id="menu" class="postranni" aria-label="Hlavní menu">'));
    // každá stránka menu je ve skupině právě jednou a má ikonu
    $vSkupinach = array_merge(...array_values(Ui::SKUPINY));
    eq(count($vSkupinach), count(array_unique($vSkupinach)));
    foreach (array_keys(Ui::MENU) as $p) {
        ok(in_array($p, $vSkupinach, true), "stránka $p chybí v menu");
        ok(Ui::ikona($p) !== '', "stránka $p nemá ikonu");
        ok(str_contains($r['body'], 'href="index.php?p=' . $p . '"'), "odkaz na $p");
    }
    foreach (array_keys(Ui::SKUPINY) as $sk) ok(str_contains($r['body'], '<li class="menu-skupina">' . $sk . '</li>'), $sk);
    // kampaň (podstránka) zvýrazní Kampaně, a jen ty
    eq(1, substr_count($r['body'], 'aria-current="page"'));
    ok(str_contains($r['body'], 'href="index.php?p=kampane" class="aktivni" aria-current="page"'));
    ok(str_contains($r['body'], 'data-prepni="menu"') && str_contains($r['body'], 'aria-controls="menu"'), 'přepínač menu');
    ok(str_contains($r['body'], 'class="odhlasit"') && str_contains($r['body'], 'name="csrf"'), 'odhlášení zůstává formulářem s CSRF');
    ok(str_contains($r['body'], 'Verze ' . $app->verze));
});

test('dlaždice přehledu mají popis, číslo a ikonu; neznámá ikona nic nevypíše', function () use ($b) {
    $r = $b->jdi('index.php');
    eq(7, substr_count($r['body'], 'class="dlazdice"'));
    eq(7, substr_count($r['body'], 'class="dlazdice-ikona"'));
    ok(str_contains($r['body'], '<div class="popis">Běžící kampaně</div>'));
    eq('', Ui::ikona('neexistuje'));
    ok(str_contains(Ui::dlazdice('A &amp; B', '5'), '<div class="popis">A &amp; B</div>') && !str_contains(Ui::dlazdice('x', '1'), 'dlazdice-ikona'));
});

test('styly a skripty administrace nenačítají nic z internetu; licence šablony je přiložená', function () {
    foreach (['assets/style.css', 'assets/app.js'] as $f) {
        $t = (string)file_get_contents(KOREN . '/apps/reklamy/' . $f);
        $t = str_replace(['http://www.w3.org/2000/svg', 'https://adminkit.io'], '', $t);   // jmenný prostor SVG a zmínka v komentáři
        ok(!preg_match('~(https?:)?//[a-z0-9.-]+\.[a-z]{2,}/~i', $t) && !str_contains($t, '@import'), "$f odkazuje ven");
    }
    $lic = (string)file_get_contents(KOREN . '/apps/reklamy/assets/LICENCE.txt');
    ok(str_contains($lic, 'AdminKit') && str_contains($lic, 'Feather') && substr_count($lic, 'MIT License') >= 2);
});

// ---------- skutečný prohlížeč: menu na počítači a na mobilu ----------
$node = trim((string)shell_exec('command -v node'));
$pw = $node !== '' && trim((string)shell_exec('node -e "require(\'playwright\'); console.log(1)" 2>/dev/null')) === '1';
test('ve skutečném prohlížeči: menu se na počítači skryje a zapamatuje, na mobilu vysune a závoj ho zavře', function () use ($pw, $s, $b) {
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
  const v = { chyby: [] };
  const vlevo = p => p.\$eval('#menu', e => Math.round(e.getBoundingClientRect().right));
  // počítač
  let ctx = await b.newContext({ viewport: { width: 1300, height: 800 } });
  await ctx.addCookies($cookiesJs);
  let p = await ctx.newPage();
  p.on('pageerror', e => v.chyby.push(e.message));
  p.on('console', m => { if (m.type() === 'error') v.chyby.push(m.text()); });
  await p.goto('$url/index.php?p=statistiky');
  v.pc = [await vlevo(p)];
  v.pismo = await p.\$eval('body', e => getComputedStyle(e).fontSize);
  await p.click('.menu-prepinac');
  await p.waitForTimeout(500);
  v.pc.push(await vlevo(p));
  await p.reload();
  v.pc.push(await vlevo(p));
  await p.click('.menu-prepinac');
  await p.waitForTimeout(500);
  v.pc.push(await vlevo(p));
  await ctx.close();
  // mobil
  ctx = await b.newContext({ viewport: { width: 390, height: 800 } });
  await ctx.addCookies($cookiesJs);
  p = await ctx.newPage();
  p.on('pageerror', e => v.chyby.push(e.message));
  await p.goto('$url/index.php?p=kampane');
  v.mobil = [await vlevo(p)];
  await p.click('.menu-prepinac');
  await p.waitForTimeout(500);
  v.mobil.push(await vlevo(p));
  await p.mouse.click(370, 400);   // závoj vpravo od menu
  await p.waitForTimeout(500);
  v.mobil.push(await vlevo(p));
  v.sirka = await p.evaluate(() => document.documentElement.scrollWidth);
  console.log(JSON.stringify(v));
  await b.close();
})().catch(e => { console.log(JSON.stringify({ chyba: String(e) })); process.exit(1); });
JS);
    $out = (string)shell_exec('node ' . escapeshellarg($skript) . ' 2>&1');
    $v = json_decode(trim((string)strrchr("\n" . trim($out), "\n")), true);
    ok(is_array($v) && !isset($v['chyba']), $out);
    eq([260, 0, 0, 260], $v['pc'], 'viditelné → skryté → po načtení stále skryté → znovu viditelné');
    eq('14px', $v['pismo']);
    eq([0, 260, 0], $v['mobil'], 'na mobilu skryté → vysunuté → zavřené závojem');
    eq(390, $v['sirka'], 'stránka na mobilu nejde do šířky');
    eq([], $v['chyby']);
});
