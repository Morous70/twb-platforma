<?php
declare(strict_types=1);
// TWB Media galerie 0.8.13 ve skutečném prohlížeči (Playwright): editor úpravy obrázku v detailu – poměry stran s prohozením,
// kreslení a posun výřezu myší, otočení a překlopení, uložení. S proměnnou SNIMKY=<složka> uloží snímky obrazovky.
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use MediaGalerie\App;

echo "Media galerie: úprava obrázku v prohlížeči\n";
$node = trim((string)shell_exec('command -v node'));
$pw = $node !== '' && trim((string)shell_exec('node -e "require(\'playwright\'); console.log(1)" 2>/dev/null')) === '1';

/** Fotka „krajina“: nebe s přechodem, slunce, kopce (1600×1000). */
function krajina0813(): string
{
    $w = 1600; $h = 1000;
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        $t = $y / $h;
        imageline($im, 0, $y, $w, $y, (int)imagecolorallocate($im, (int)(70 + 150 * $t), (int)(130 + 80 * $t), (int)(220 - 60 * $t)));
    }
    imagefilledellipse($im, 1200, 260, 220, 220, (int)imagecolorallocate($im, 255, 215, 90));
    imagefilledellipse($im, 400, 1050, 1400, 700, (int)imagecolorallocate($im, 60, 130, 70));
    imagefilledellipse($im, 1300, 1100, 1400, 650, (int)imagecolorallocate($im, 40, 105, 55));
    imagefilledrectangle($im, 300, 600, 380, 760, (int)imagecolorallocate($im, 110, 70, 40));
    imagefilledellipse($im, 340, 560, 220, 200, (int)imagecolorallocate($im, 30, 90, 40));
    ob_start(); imagejpeg($im, null, 90); return (string)ob_get_clean();
}

test('editor: poměry stran s prohozením, kreslení výřezu, otočení, uložení', function () use ($pw) {
    if (!$pw) { echo "      (přeskočeno: chybí node nebo playwright)\n"; return; }
    $s = new TestServer();
    register_shutdown_function(fn() => $s->zastav());
    $app = new App(KOREN . '/apps/twb-galerie', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
    $app->migruj();
    $app->heslo()->nastav('tajneheslo1');
    $app->nastaveni()->uloz('img_auto', '0');
    $f = docasna('img'); file_put_contents($f, krajina0813());
    $id = (int)$app->media()->nahraj($f, 'Krajina u lesa.jpg', ['příroda'])['id'];
    $snimky = (string)getenv('SNIMKY');
    $skript = docasna('pw') . '.js';
    $cfg = json_encode(['u' => $s->url . '/', 'id' => $id, 'snimky' => $snimky]);
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
  const foto = async (n, el) => { if (cfg.snimky) await p.mouse.move(0, 0), await p.waitForTimeout(400), await (el ? p.locator(el) : p).screenshot({ path: cfg.snimky + '/' + n + '.png' }); };
  await p.goto(cfg.u);
  await p.fill('input[name=heslo]', 'tajneheslo1');
  await Promise.all([p.waitForNavigation(), p.press('input[name=heslo]', 'Enter')]);
  await p.goto(cfg.u + 'index.php?p=medium&id=' + cfg.id);
  v.zavreno = await p.\$eval('[data-uprava]', e => e.open);
  await p.click('[data-otevrit-upravu]');
  await p.waitForFunction(() => document.querySelector('[data-platno]').width > 1);
  v.otevreno = await p.\$eval('[data-uprava]', e => e.open);
  v.info0 = await p.textContent('[data-uprava-info]');
  v.ulozitVypnuto = await p.\$eval('[data-uprava-ulozit]', e => e.disabled);
  await p.click('[data-pomer="16:9"]');
  v.info169 = await p.textContent('[data-uprava-info]');
  await p.check('[data-prohodit]');
  v.info916 = await p.textContent('[data-uprava-info]');
  await p.uncheck('[data-prohodit]');
  await p.click('[data-pomer="4:3"]');
  await p.locator('#uprava').scrollIntoViewIfNeeded();
  await foto('admin-uprava-pomer', '#uprava');
  // volný výřez tažením myši
  await p.click('[data-pomer=""]');
  const r = await p.\$eval('[data-platno]', e => { const b = e.getBoundingClientRect(); return { x: b.x, y: b.y, w: b.width, h: b.height }; });
  await p.click('[data-uprava-zrusit-vyrez]');
  await p.mouse.move(r.x + r.w * 0.1, r.y + r.h * 0.2);
  await p.mouse.down();
  await p.mouse.move(r.x + r.w * 0.4, r.y + r.h * 0.5, { steps: 5 });
  await p.mouse.move(r.x + r.w * 0.6, r.y + r.h * 0.8, { steps: 5 });
  await p.mouse.up();
  v.infoVolny = await p.textContent('[data-uprava-info]');
  // posun výřezu
  const vr = await p.\$eval('[data-vyrez]', e => { const b = e.getBoundingClientRect(); return { x: b.x + b.width / 2, y: b.y + b.height / 2 }; });
  await p.mouse.move(vr.x, vr.y); await p.mouse.down(); await p.mouse.move(vr.x + 2000, vr.y, { steps: 5 }); await p.mouse.up();
  v.vpravo = await p.\$eval('[data-vyrez]', e => e.style.left + ' ' + e.style.width);
  // otočení doprava: plátno na výšku
  v.predOtocenim = await p.\$eval('[data-platno]', e => e.width > e.height);
  await p.click('[data-uprava-akce="vpravo"]');
  v.poOtoceni = await p.\$eval('[data-platno]', e => e.width > e.height);
  v.infoOtoceno = await p.textContent('[data-uprava-info]');
  await p.click('[data-uprava-akce="vlevo"]');
  await p.click('[data-pomer="16:9"]');
  await foto('admin-uprava-ores', '#uprava');
  await p.setViewportSize({ width: 390, height: 844 });
  await p.waitForTimeout(300);
  await p.locator('#uprava').scrollIntoViewIfNeeded();
  await foto('admin-uprava-mobil');
  v.mobilSirka = await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
  await p.setViewportSize({ width: 1360, height: 1000 });
  await p.waitForTimeout(300);
  await Promise.all([p.waitForNavigation(), p.click('[data-uprava-ulozit]')]);
  v.zprava = await p.textContent('.hlaska-ok');
  v.upraveno = await p.textContent('.udaje');
  await foto('admin-detail-upraveno');
  console.log(JSON.stringify(v));
  await b.close();
})().catch(e => { console.log(JSON.stringify({ chyba: String(e).slice(0, 300) })); process.exit(1); });
JS);
    $out = (string)shell_exec('node ' . escapeshellarg($skript) . ' 2>&1');
    $v = json_decode(trim((string)strrchr("\n" . trim($out), "\n")), true);
    ok(is_array($v) && !isset($v['chyba']), $out);
    eq([], $v['chyby'], 'chyby JavaScriptu');
    eq([false, true, true], [$v['zavreno'], $v['otevreno'], $v['ulozitVypnuto']]);
    ok(str_contains($v['info0'], '1600 × 1000 px') && str_contains($v['info0'], 'beze změny'), $v['info0']);
    ok(str_contains($v['info169'], '1600 × 900 px (16:9)'), $v['info169']);
    ok(str_contains($v['info916'], '558 × 992 px (9:16)'), 'prohozený poměr na výšku, přesně 9:16: ' . $v['info916']);
    ok(preg_match('/Výsledek: (\d+) × (\d+) px$/', trim($v['infoVolny']), $m) && abs((int)$m[1] - 800) < 30 && abs((int)$m[2] - 600) < 30, $v['infoVolny']);
    ok(preg_match('/^([\d.]+)% ([\d.]+)%$/', $v['vpravo'], $m) && abs((float)$m[1] + (float)$m[2] - 100) < 0.01, 'výřez se zastaví u okraje: ' . $v['vpravo']);
    eq([true, false], [$v['predOtocenim'], $v['poOtoceni']]);
    ok(preg_match('/Výsledek: (\d+) × (\d+) px/', $v['infoOtoceno'], $m) && (int)$m[1] < (int)$m[2], 'po otočení výřez na výšku: ' . $v['infoOtoceno']);
    ok($v['mobilSirka'], 'na mobilu bez vodorovného posuvníku');
    ok(str_contains($v['zprava'], 'Obrázek upraven: oříznuto 16:9'), $v['zprava']);
    ok(str_contains($v['upraveno'], 'Upraveno'));
    $m = $app->media()->radek($id);
    eq(0, ((int)$m['width'] * 9) - ((int)$m['height'] * 16), 'přesně 16:9: ' . $m['width'] . '×' . $m['height']);
    ok($m['orig_filename'] !== null);
});
