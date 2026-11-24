<?php
declare(strict_types=1);
// TWB Media galerie 0.8.15: ohnisko fotky (bod, kolem kterého web fotku ořízne) a volba „zobrazit celou fotku“ s rozmazaným okolím.
// Poslední test běží ve skutečném prohlížeči (Playwright); s proměnnou SNIMKY=<složka> uloží snímky obrazovky.
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use MediaGalerie\{App, Web, Media, Schema, Chyba};

echo "Media galerie: 0.8.15\n";

/** Fotka na výšku (1200×1600): nebe, „hlava“ (žlutý kruh s očima) nahoře v 18 % výšky, tělo a tráva dole. */
function portret0815(): string
{
    $w = 1200; $h = 1600;
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        $t = $y / $h;
        imageline($im, 0, $y, $w, $y, (int)imagecolorallocate($im, (int)(90 + 120 * $t), (int)(150 + 60 * $t), (int)(230 - 90 * $t)));
    }
    imagefilledrectangle($im, 0, 1250, $w, $h, (int)imagecolorallocate($im, 70, 140, 60));
    imagefilledellipse($im, 840, 1000, 520, 900, (int)imagecolorallocate($im, 160, 60, 50));       // tělo
    imagefilledellipse($im, 840, 290, 300, 300, (int)imagecolorallocate($im, 250, 205, 80));       // hlava (ohnisko ~70 % × 18 %)
    imagefilledellipse($im, 790, 260, 36, 36, (int)imagecolorallocate($im, 30, 30, 30));
    imagefilledellipse($im, 890, 260, 36, 36, (int)imagecolorallocate($im, 30, 30, 30));
    imagearc($im, 840, 320, 140, 90, 20, 160, (int)imagecolorallocate($im, 30, 30, 30));
    ob_start(); imagejpeg($im, null, 90); return (string)ob_get_clean();
}

/** Panorama 3000×700: hory po celé šířce. */
function panorama0815(): string
{
    $w = 3000; $h = 700;
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) imageline($im, 0, $y, $w, $y, (int)imagecolorallocate($im, 250 - (int)(80 * $y / $h), 170 + (int)(30 * $y / $h), 110 + (int)(90 * $y / $h)));
    for ($i = 0; $i < 7; $i++) {
        $x = 200 + $i * 430;
        imagefilledpolygon($im, [$x - 320, $h, $x, 180 + ($i % 3) * 60, $x + 320, $h], (int)imagecolorallocate($im, 60 + $i * 12, 80 + $i * 8, 110));
    }
    ob_start(); imagejpeg($im, null, 90); return (string)ob_get_clean();
}

/** Aplikace s veřejnou kategorií, portrétem (úvodní fotka, doporučený) a panoramatem. @return array{App, int, int, int} */
function data0815(?App $app = null): array
{
    $app ??= novaApp();
    $k = $app->kategorie()->uloz(['name' => 'Lidé a krajina']);
    $app->web()->ulozKategorii($k, ['web_public' => '1']);
    $f = docasna('p') . '.jpg'; file_put_contents($f, portret0815());
    $p = (int)$app->media()->nahraj($f, 'Portrét na louce.jpg', [], $k)['id'];
    $f = docasna('q') . '.jpg'; file_put_contents($f, panorama0815());
    $q = (int)$app->media()->nahraj($f, 'Panorama hor.jpg', [], $k)['id'];
    $app->web()->nastavUvodni($p);
    $app->media()->nastavDoporucene([$p, $q], true);
    return [$app, $k, $p, $q];
}

test('schéma 11: sloupce focus_x, focus_y, show_whole; nastavOhnisko kontroluje hodnoty', function () {
    eq(11, Schema::verze());
    [$app, , $p] = data0815();
    $sl = array_column($app->db->q('PRAGMA table_info(media)')->fetchAll(), 'name');
    foreach (['focus_x', 'focus_y', 'show_whole'] as $s) ok(in_array($s, $sl, true), $s);
    $m = $app->media();
    eq(null, Media::ohnisko($m->radek($p)), 'nová fotka nemá ohnisko');
    eq(0, (int)$m->radek($p)['show_whole']);
    $m->nastavOhnisko($p, 70, 18, false);
    eq(['x' => 70, 'y' => 18], Media::ohnisko($m->radek($p)));
    $m->nastavOhnisko($p, 0, 100, true);
    eq([['x' => 0, 'y' => 100], 1], [Media::ohnisko($m->radek($p)), (int)$m->radek($p)['show_whole']]);
    throws(fn() => $m->nastavOhnisko($p, 101, 5, false), '0–100');
    throws(fn() => $m->nastavOhnisko($p, -1, 5, false), '0–100');
    throws(fn() => $m->nastavOhnisko($p, 10, null, false), 'obě souřadnice');
    $m->nastavOhnisko($p, null, null, false);
    eq([null, 0], [Media::ohnisko($m->radek($p)), (int)$m->radek($p)['show_whole']]);
    ok(!$m->zrusOhnisko($p), 'bez ohniska nic nezruší');
    eq(['x' => 100, 'y' => 0], Media::ohnisko(['focus_x' => 140, 'focus_y' => -3]), 'starší / ruční hodnoty se omezí');
});

test('Web::fotkaHtml: bez ohniska, s ohniskem (třídy, bez style=""), celá fotka s rozmazaným pozadím', function () {
    $m = ['focus_x' => null, 'focus_y' => null, 'show_whole' => 0];
    eq('<img src="a.jpg?x=1&amp;y=2" alt="">', Web::fotkaHtml($m, 'a.jpg?x=1&y=2'));
    eq('<img class="hero-fotka" src="a.jpg" alt="" fetchpriority="high">', Web::fotkaHtml($m, 'a.jpg', 'hero-fotka', ' fetchpriority="high"'));
    $m = ['focus_x' => 70, 'focus_y' => 18, 'show_whole' => 0];
    eq('<img class="hero-fotka ohnisko ox-70 oy-18" src="a.jpg" alt="">', Web::fotkaHtml($m, 'a.jpg', 'hero-fotka'));
    eq('<img class="ohnisko ox-70 oy-18" src="a.jpg" alt="Kůň" loading="lazy">', Web::fotkaHtml($m, 'a.jpg', '', ' alt="Kůň" loading="lazy"'), 'vlastní alt se nezdvojí');
    $m['show_whole'] = 1;
    $h = Web::fotkaHtml($m, 'a.jpg', 'hero-fotka', ' loading="lazy"');
    eq('<span class="hero-fotka cela-obal"><img class="cela-pozadi" src="a.jpg" alt="" aria-hidden="true" loading="lazy"><img class="cela-popredi" src="a.jpg" alt="" loading="lazy"></span>', $h);
    ok(!str_contains($h, 'style='));
    $css = (string)file_get_contents(KOREN . '/apps/twb-galerie/assets/verejne.css');
    ok(str_contains($css, 'img.ohnisko { object-position: var(--ox, 50%) var(--oy, 50%); }'));
    for ($i = 0; $i <= 100; $i++) ok(str_contains($css, ".ox-{$i}{--ox:{$i}%}") && str_contains($css, ".oy-{$i}{--oy:{$i}%}"), "třídy pro {$i} %");
    ok(strrpos($css, 'img.ohnisko') > strrpos($css, '.hero-fotka') && strrpos($css, 'img.ohnisko') > strrpos($css, '.foto img'), 'pravidlo ohniska je za výchozími polohami');
    foreach (['.cela-obal', '.cela-pozadi', '.cela-popredi', 'blur('] as $t) ok(str_contains($css, $t), $t);
});

test('nahrazení souboru ohnisko zruší, volba celé fotky zůstane; komprese ho nechá', function () {
    [$app, $k, $p] = data0815();
    $m = $app->media();
    $m->nastavOhnisko($p, 70, 18, true);
    $app->galerie()->komprimuj($p, true);
    eq(['x' => 70, 'y' => 18], Media::ohnisko($m->radek($p)), 'komprese mění jen velikost – ohnisko platí');
    $f = docasna('n') . '.jpg'; file_put_contents($f, panorama0815());
    $m->nahraj($f, 'Jiná.jpg', [], null, $p);
    eq([null, 1], [Media::ohnisko($m->radek($p)), (int)$m->radek($p)['show_whole']]);
});

test('0.8.15 na serveru: detail – uložení ohniska, celá fotka, zrušení po úpravě; web s třídami a přísným CSP; API', function () {
    $s = new TestServer();
    register_shutdown_function(fn() => $s->zastav());
    $b = new Prohlizec($s);
    $b->jdi('install.php');
    $b->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'tajneheslo1']);
    $app = new App(KOREN . '/apps/twb-galerie', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
    [, , $p, $q] = data0815($app);
    $anon = new Prohlizec($s);

    $r = $b->jdi("index.php?p=medium&id={$p}");
    ok(str_contains($r['body'], 'id="ohnisko" data-ohnisko') && str_contains($r['body'], 'Ohnisko na webu</a>'), 'karta a tlačítko');
    ok(str_contains($r['body'], '<span data-ohnisko-bez> (zatím bez ohniska – ořez od středu)</span>'));
    ok(str_contains($r['body'], 'data-vychozi-y="60" style="object-position:50% 60%"'), 'náhled úvodní fotky ve výchozí poloze webu');
    ok(!str_contains($r['body'], 'Zrušit ohnisko'), 'bez ohniska není co rušit');
    $hero = $anon->jdi('verejne.php')['body'];
    ok(str_contains($hero, '<img class="hero-fotka" src="'), 'bez ohniska beze změny');

    $r = $b->odesli("index.php?p=medium&id={$p}", ['akce' => 'ohnisko', 'focus_x' => '70', 'focus_y' => '18,4']);
    ok(str_contains($r['body'], 'Ohnisko uloženo (70 % zleva, 18 % shora)'), 'hláška');
    ok(str_contains((string)$r['presmerovano'], '#ohnisko'), 'zpět na kartu');
    ok(str_contains($r['body'], '<dt>Na webu</dt><dd>ořez kolem ohniska 70 % zleva, 18 % shora</dd>'));
    ok(str_contains($r['body'], 'style="left:70%;top:18%"') && str_contains($r['body'], 'style="object-position:70% 18%"'));
    $r = $b->odesli("index.php?p=medium&id={$p}", ['akce' => 'ohnisko', 'focus_x' => 'abc', 'focus_y' => '1']);
    ok(str_contains($r['body'], 'Ohnisko vodorovně: zadejte číslo 0–100.'));
    $r = $b->odesli("index.php?p=medium&id={$p}", ['akce' => 'ohnisko', 'focus_x' => '150', 'focus_y' => '1']);
    ok(str_contains($r['body'], 'Souřadnice ohniska musí být 0–100 %.'));
    eq(['x' => 70, 'y' => 18], Media::ohnisko($app->media()->radek($p)), 'chybný vstup nic nezměnil');

    $web = $anon->jdi('verejne.php');
    ok(str_contains($web['body'], '<img class="hero-fotka ohnisko ox-70 oy-18" src="'), 'úvodní fotka s ohniskem');
    ok(preg_match('~<a class="foto[^"]*" href="[^"]+" data-foto data-titulek="Portrét na louce"[^>]*><img class="ohnisko ox-70 oy-18" src=~', $web['body']) === 1, 'doporučené v mřížce');
    ok(!preg_match('/\sstyle="/', $web['body']), 'veřejný web bez style=""');
    ok(str_contains($anon->posledniHlavicky, "style-src 'self'; script-src 'self';"), 'přísné CSP zůstává');
    ok(str_contains($anon->jdi('verejne.php?s=galerie')['body'], 'ohnisko ox-70 oy-18'), 'stránka Galerie (obálka / nejnovější)');
    ok(str_contains($anon->jdi('index.php')['body'], '<img class="prihlaseni-fotka ohnisko ox-70 oy-18"'), 'pozadí přihlášení');

    // celá fotka u panoramatu
    $r = $b->odesli("index.php?p=medium&id={$q}", ['akce' => 'ohnisko', 'focus_x' => '', 'focus_y' => '', 'cela' => '1']);
    ok(str_contains($r['body'], 'na webu se ukáže celá fotka s rozmazaným okolím'));
    ok(str_contains($r['body'], 'class="ohnisko-nahledy cela"'));
    $app->web()->nastavUvodni($q);
    $app->web()->odeberUvodni($p);
    $web = $anon->jdi('verejne.php')['body'];
    ok(str_contains($web, '<span class="hero-fotka cela-obal"><img class="cela-pozadi" src="'), 'úvod: celá fotka');
    ok(str_contains($web, '<span class="cela-obal"><img class="cela-pozadi"'), 'mřížka: celá fotka');
    $r = $b->odesli("index.php?p=medium&id={$q}", ['akce' => 'ohnisko-zrusit']);
    ok(str_contains($r['body'], 'Ohnisko zrušeno') && (int)$app->media()->radek($q)['show_whole'] === 0);

    // úprava obrázku (ořez) ohnisko zruší a řekne to
    $r = $b->odesli("index.php?p=medium&id={$p}", ['akce' => 'upravit', 'otoceni' => '0', 'orez_x' => '0', 'orez_y' => '0', 'orez_w' => '1', 'orez_h' => '0.5', 'pomer' => '']);
    ok(str_contains($r['body'], 'Ohnisko pro web bylo zrušeno'), 'hláška po úpravě');
    eq(null, Media::ohnisko($app->media()->radek($p)));

    // API: výstup i úprava
    $klic = $app->apiKlice()->vytvor('Test', 'write');
    $api = function (string $metoda, string $cesta, ?array $json = null) use ($s, $klic): array {
        $ch = curl_init($s->url . '/api.php/v1' . $cesta);
        $h = ['Authorization: Bearer ' . $klic];
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_TIMEOUT => 30]);
        if ($json !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json)); $h[] = 'Content-Type: application/json'; }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        $body = (string)curl_exec($ch);
        return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($body, true)];
    };
    [$c, $j] = $api('GET', "/media/{$p}");
    eq([200, null, false], [$c, $j['medium']['ohnisko'], $j['medium']['cela_fotka']]);
    [$c, $j] = $api('PATCH', "/media/{$p}", ['ohnisko' => ['x' => 33.4, 'y' => 12], 'cela_fotka' => false]);
    eq([200, ['x' => 33, 'y' => 12]], [$c, $j['medium']['ohnisko']]);
    [$c, $j] = $api('PATCH', "/media/{$p}", ['cela_fotka' => true]);
    eq([200, ['x' => 33, 'y' => 12], true], [$c, $j['medium']['ohnisko'], $j['medium']['cela_fotka']], 'samotná cela_fotka ohnisko nechá');
    eq(400, $api('PATCH', "/media/{$p}", ['ohnisko' => ['x' => 120, 'y' => 0]])[0]);
    eq(400, $api('PATCH', "/media/{$p}", ['ohnisko' => 'střed'])[0]);
    [$c, $j] = $api('PATCH', "/media/{$p}", ['ohnisko' => null]);
    eq([200, null, true], [$c, $j['medium']['ohnisko'], $j['medium']['cela_fotka']]);
});

test('prohlížeč: kliknutí nastaví ohnisko, náhledy se posunou, uložení; web na počítači a mobilu', function () {
    $node = trim((string)shell_exec('command -v node'));
    if ($node === '' || trim((string)shell_exec('node -e "require(\'playwright\'); console.log(1)" 2>/dev/null')) !== '1') { echo "      (přeskočeno: chybí node nebo playwright)\n"; return; }
    $s = new TestServer();
    register_shutdown_function(fn() => $s->zastav());
    $app = new App(KOREN . '/apps/twb-galerie', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
    $app->migruj();
    $app->heslo()->nastav('tajneheslo1');
    $app->nastaveni()->uloz('img_auto', '0');
    [, , $p, $q] = data0815($app);
    $snimky = (string)getenv('SNIMKY');
    $skript = docasna('pw') . '.js';
    $cfg = json_encode(['u' => $s->url . '/', 'p' => $p, 'q' => $q, 'snimky' => $snimky]);
    file_put_contents($skript, <<<JS
const { chromium } = require('playwright');
const cfg = $cfg;
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1360, height: 1000 }, deviceScaleFactor: cfg.snimky ? 2 : 1 });
  const p = await ctx.newPage();
  const v = { chyby: [] };
  p.on('pageerror', e => v.chyby.push(e.message));
  p.on('console', m => { if (m.type() === 'error' && !/favicon/.test(m.text())) v.chyby.push(m.text()); });
  const foto = async (n, el) => { if (cfg.snimky) await p.mouse.move(0, 0), await p.waitForTimeout(500), await (el ? p.locator(el) : p).screenshot({ path: cfg.snimky + '/' + n + '.png' }); };
  const nacteno = () => p.waitForFunction(() => [...document.images].filter(i => i.loading !== 'lazy' || i.getBoundingClientRect().top < innerHeight).every(i => i.complete));
  // web před nastavením ohniska (hlava useknutá)
  await p.setViewportSize({ width: 1440, height: 900 });
  await p.goto(cfg.u + 'verejne.php'); await nacteno();
  v.hero = await p.\$eval('.hero', e => { const r = e.getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; });
  await foto('web-uvod-bez-ohniska', '.hero');
  await p.goto(cfg.u);
  await p.fill('input[name=heslo]', 'tajneheslo1');
  await Promise.all([p.waitForNavigation(), p.press('input[name=heslo]', 'Enter')]);
  await p.setViewportSize({ width: 1360, height: 1000 });
  await p.goto(cfg.u + 'index.php?p=medium&id=' + cfg.p);
  v.zavreno = await p.\$eval('[data-ohnisko]', e => e.open);
  await p.click('[data-otevrit-ohnisko]');
  v.otevreno = await p.\$eval('[data-ohnisko]', e => e.open);
  await p.waitForFunction(() => document.querySelector('[data-ohnisko-plocha] img').complete);
  await p.waitForTimeout(900);   // plynulé posunutí ke kartě
  let r = await p.\$eval('[data-ohnisko-plocha] img', e => { const b = e.getBoundingClientRect(); return { x: b.x, y: b.y, w: b.width, h: b.height }; });
  await p.mouse.click(r.x + r.w * 0.70, r.y + r.h * 0.18);
  v.xy = [await p.inputValue('[data-ohnisko-x]'), await p.inputValue('[data-ohnisko-y]')];
  v.bod = await p.\$eval('[data-ohnisko-bod]', e => [e.hidden, e.style.left, e.style.top]);
  v.nahled = await p.\$eval('.ohnisko-nahled-pc img.popredi', e => e.style.objectPosition);
  // tažení posune bod
  r = await p.\$eval('[data-ohnisko-plocha] img', e => { const b = e.getBoundingClientRect(); return { x: b.x, y: b.y, w: b.width, h: b.height }; });
  await p.mouse.move(r.x + r.w * 0.2, r.y + r.h * 0.5); await p.mouse.down();
  await p.mouse.move(r.x + r.w * 0.5, r.y + r.h * 0.3, { steps: 4 }); await p.mouse.up();
  v.tazeni = [await p.inputValue('[data-ohnisko-x]'), await p.inputValue('[data-ohnisko-y]')];
  await p.fill('[data-ohnisko-x]', '70'); await p.fill('[data-ohnisko-y]', '18');
  v.rucne = await p.\$eval('[data-ohnisko-bod]', e => e.style.left + ' ' + e.style.top);
  await p.locator('#ohnisko').scrollIntoViewIfNeeded();
  await foto('admin-ohnisko', '#ohnisko');
  await Promise.all([p.waitForNavigation(), p.click('[data-ohnisko-formular] button[type=submit]')]);
  v.zprava = await p.textContent('.hlaska-ok');
  v.poUlozeni = await p.\$eval('[data-ohnisko]', e => e.open);
  await p.setViewportSize({ width: 390, height: 844 });
  await p.waitForTimeout(300);
  await p.locator('#ohnisko').scrollIntoViewIfNeeded();
  await foto('admin-ohnisko-mobil');
  v.mobilSirka = await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
  // panorama: celá fotka
  await p.setViewportSize({ width: 1360, height: 1000 });
  await p.goto(cfg.u + 'index.php?p=medium&id=' + cfg.q + '#ohnisko');
  await p.check('[data-ohnisko-cela]');
  v.cela = await p.\$eval('[data-ohnisko-nahledy]', e => e.classList.contains('cela'));
  await p.waitForTimeout(300);
  await p.locator('#ohnisko').scrollIntoViewIfNeeded();
  await foto('admin-cela-fotka', '#ohnisko');
  await Promise.all([p.waitForNavigation(), p.click('[data-ohnisko-formular] button[type=submit]')]);
  // veřejný web s ohniskem
  await p.setViewportSize({ width: 1440, height: 900 });
  await p.goto(cfg.u + 'verejne.php'); await nacteno();
  v.heroPoloha = await p.\$eval('.hero-fotka', e => getComputedStyle(e).objectPosition);
  await foto('web-uvod-s-ohniskem', '.hero');
  await p.locator('.sekce-doporucene').scrollIntoViewIfNeeded(); await nacteno();
  v.mrizka = await p.\$\$eval('.sekce-doporucene .foto', a => a.map(e => e.querySelector('.cela-obal') ? 'cela' : getComputedStyle(e.querySelector('img')).objectPosition));
  await foto('web-doporucene', '.sekce-doporucene');
  await p.setViewportSize({ width: 390, height: 844 });
  await p.goto(cfg.u + 'verejne.php'); await nacteno();
  await foto('web-uvod-mobil');
  v.webMobil = await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
  console.log(JSON.stringify(v));
  await b.close();
})().catch(e => { console.log(JSON.stringify({ chyba: String(e).slice(0, 300) })); process.exit(1); });
JS);
    $out = (string)shell_exec('node ' . escapeshellarg($skript) . ' 2>&1');
    $v = json_decode(trim((string)strrchr("\n" . trim($out), "\n")), true);
    ok(is_array($v) && !isset($v['chyba']), $out);
    eq([], $v['chyby'], 'chyby JavaScriptu');
    echo '      (úvodní fotka na 1440 px: ' . implode('×', $v['hero']) . ")\n";
    eq([false, true], [$v['zavreno'], $v['otevreno']]);
    ok(abs((int)$v['xy'][0] - 70) <= 1 && abs((int)$v['xy'][1] - 18) <= 1, 'kliknutí: ' . implode(',', $v['xy']));
    ok($v['bod'][0] === false && str_ends_with($v['bod'][1], '%'), 'bod se ukázal');
    ok((bool)preg_match('/^\d+% \d+%$/', $v['nahled']) && $v['nahled'] === $v['xy'][0] . '% ' . $v['xy'][1] . '%', 'náhled: ' . $v['nahled']);
    ok(abs((int)$v['tazeni'][0] - 50) <= 1 && abs((int)$v['tazeni'][1] - 30) <= 1, 'tažení: ' . implode(',', $v['tazeni']));
    eq('70% 18%', $v['rucne'], 'ruční zadání posune bod');
    ok(str_contains((string)$v['zprava'], 'Ohnisko uloženo (70 % zleva, 18 % shora)'), (string)$v['zprava']);
    ok($v['poUlozeni'], 'karta po uložení zůstane otevřená');
    ok($v['mobilSirka'] && $v['webMobil'], 'na mobilu bez vodorovného posuvníku');
    ok($v['cela']);
    eq('70% 18%', $v['heroPoloha'], 'úvodní fotka na webu');
    ok(in_array('70% 18%', $v['mrizka'], true) && in_array('cela', $v['mrizka'], true), 'mřížka doporučených: ' . json_encode($v['mrizka']));
    eq(['x' => 70, 'y' => 18], Media::ohnisko($app->media()->radek($p)));
    eq(1, (int)$app->media()->radek($q)['show_whole']);
});
