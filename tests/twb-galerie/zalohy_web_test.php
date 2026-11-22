<?php
declare(strict_types=1);
// TWB Media galerie 0.4: záloha a obnova v rozhraní (média, soubory, kategorie, veřejný odkaz), aktualizace, cron.
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use MediaGalerie\App;

echo "Media galerie: zálohy, aktualizace a cron\n";
$falesny = new TestServer();   // „Update Server“ bez API: latest.php vrátí 404
$s = new TestServer(['update_server' => $falesny->url, 'update_token' => 'upd_testovacitoken1234']);
register_shutdown_function(function () use ($s, $falesny) { $s->zastav(); $falesny->zastav(); });
$app = new App(KOREN . '/apps/twb-galerie', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
$app->migruj();
$app->heslo()->nastav('tajneheslo1');
$b = new Prohlizec($s);
$media = fn() => $s->db()->query('SELECT original_name FROM media ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

/** Nahraje soubor do galerie jako po skončení nahrávání; vrací id. */
function pridej(App $app, string $nazev, string $obsah, ?int $kategorie = null): int
{
    $f = docasna('soubor');
    file_put_contents($f, $obsah);
    $v = $app->galerie()->uloz($f, $nazev);
    $app->media()->poNahrani($v['id'], $kategorie);
    return $v['id'];
}

test('cron: bez klíče 403, s klíčem projde a zapíše čas', function () use ($s, $app) {
    $anon = new Prohlizec($s);
    eq(403, $anon->jdi('cron.php')['code']);
    eq(403, $anon->jdi('cron.php?key=spatny')['code']);
    $r = $anon->jdi('cron.php?key=tajny-cron-klic-123');
    eq(200, $r['code']);
    ok(str_starts_with($r['body'], 'OK'), $r['body']);
    ok((int)$s->db()->query("SELECT v FROM settings WHERE k = 'cron_last_run'")->fetchColumn() > time() - 60);
});

test('záloha s médii a kategorií; obnova vrátí databázi, soubory i veřejný odkaz', function () use ($b, $s, $app, $media) {
    $b->jdi('index.php');
    $b->jdi('index.php', ['akce' => 'prihlasit', 'heslo' => 'tajneheslo1']);
    $r = $b->jdi('index.php?p=prehled');
    ok(str_contains($r['body'], 'Zálohy nejsou nastavené') && !str_contains($r['body'], 'Cron zatím nikdy'), 'přehled upozorní na zálohy, cron už běžel');
    $b->jdi('index.php?p=nastaveni');
    $b->odesli('index.php?p=nastaveni', ['akce' => 'zalohy-heslo', 'backup_password' => 'heslo-zaloh-123', 'backup_auto' => '1']);
    $k = $app->kategorie()->uloz(['name' => 'Léto']);
    $id = pridej($app, 'spot.mp4', mp4(5000), $k);
    $klic = (string)$s->db()->query("SELECT public_key FROM media WHERE id = $id")->fetchColumn();
    $soubor = $s->data . '/data/media/' . $s->db()->query("SELECT filename FROM media WHERE id = $id")->fetchColumn();
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'zaloha']);
    ok(str_contains($r['body'], 'Záloha vytvořena'), mb_substr(strip_tags($r['body']), 0, 400));
    preg_match('/<option value="(twb-galerie-[a-z0-9-]+\.zip)"/', $r['body'], $m);
    ok(isset($m[1]), 'záloha v nabídce obnovy');

    // po záloze: další médium, původní smazané i se souborem
    pridej($app, 'navic.mp4', mp4(3000));
    $app->galerie()->smaz([$id]);
    ok(!is_file($soubor), 'soubor smazán');
    eq(404, (new Prohlizec($s))->jdi("soubor.php/$klic/spot.mp4")['code']);

    $r = $b->jdi('index.php?p=nastaveni', ['akce' => 'obnova-nahled', 'zaloha' => $m[1], 'heslo' => 'spatne-heslo']);
    ok(str_contains($r['body'], 'nesprávné heslo'), 'špatné heslo zálohy');
    $r = $b->jdi('index.php?p=nastaveni', ['akce' => 'obnova-nahled', 'zaloha' => $m[1], 'heslo' => '']);
    ok(str_contains($r['body'], 'Obnovit zálohu ' . $m[1]) && str_contains($r['body'], 'média 1, kategorie 1, soubory 1'), 'náhled: ' . mb_substr(strip_tags($r['body']), 0, 600));
    ok(!str_contains($r['body'], 'heslo-zaloh-123'), 'heslo se do stránky nevrací');
    eq(['navic.mp4'], $media(), 'náhled nic nemění');
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'obnova', 'zaloha' => 'twb-galerie-jina.zip']);
    ok(str_contains($r['body'], 'Potvrzení obnovy vypršelo'));
    $b->jdi('index.php?p=nastaveni', ['akce' => 'obnova-nahled', 'zaloha' => $m[1]]);
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'obnova', 'zaloha' => $m[1]]);
    ok(str_contains($r['body'], 'Záloha obnovena'), mb_substr(strip_tags($r['body']), 0, 400));
    eq(['spot.mp4'], $media());
    eq('Léto', $s->db()->query("SELECT c.name FROM media m JOIN categories c ON c.id = m.category_id WHERE m.id = $id")->fetchColumn());
    ok(is_file($soubor), 'soubor obnoven');
    $v = (new Prohlizec($s))->jdi("soubor.php/$klic/spot.mp4");
    eq([200, 5000], [$v['code'], strlen($v['body'])], 'veřejný odkaz po obnově funguje');
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'obnova', 'zaloha' => $m[1]]);
    ok(str_contains($r['body'], 'Potvrzení obnovy vypršelo'), 'potvrzení platí jednou');
    $r = $b->jdi('index.php?p=nastaveni');
    ok(str_contains($r['body'], 'před obnovou'), 'pojistná záloha v seznamu');
    ok(!str_contains($b->jdi('index.php?p=prehled')['body'], 'Zálohy nejsou nastavené'), 'heslo záloh zůstalo po obnově');
    eq(200, $b->jdi('index.php?p=protokol')['code']);
});

test('obnova z nahraného souboru; zrušení; cizí cesta', function () use ($b, $s, $app, $media) {
    $r = $b->jdi('index.php?p=nastaveni');
    preg_match('/href="(index\.php\?p=nastaveni&amp;stahnout=[^"]+)"/', $r['body'], $m);
    $zip = $b->jdi(html_entity_decode($m[1]));
    eq('application/zip', $zip['type']);
    $soubor = docasna('zaloha') . '.zip';
    file_put_contents($soubor, $zip['body']);
    $r = $b->jdi('index.php?p=nastaveni', ['akce' => 'obnova-nahled', 'zaloha' => '', 'heslo' => 'heslo-zaloh-123', 'soubor' => new CURLFile($soubor, 'application/zip', 'moje.zip')]);
    ok(preg_match('/Obnovit zálohu (twb-galerie-[0-9-]+-nahrana\.zip)/', $r['body']) === 1, mb_substr(strip_tags($r['body']), 0, 300));
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'obnova-zrusit']);
    ok(str_contains($r['body'], 'Obnova zrušena'));
    eq(['spot.mp4'], $media(), 'zrušení nic nezmění');
    $r = $b->jdi('index.php?p=nastaveni', ['akce' => 'obnova-nahled', 'zaloha' => '']);
    ok(str_contains($r['body'], 'Vyberte zálohu nebo nahrajte soubor'));
    $r = $b->jdi('index.php?p=nastaveni', ['akce' => 'obnova-nahled', 'zaloha' => '../config.php']);
    ok(str_contains($r['body'], 'Záloha nenalezena'));
});

test('aktualizace: stav, zjištění nové verze, instalace bez nabídky; slug twb-galerie', function () use ($b, $app) {
    $r = $b->jdi('index.php?p=nastaveni');
    ok(str_contains($r['body'], 'id="aktualizace"') && str_contains($r['body'], 'upd_••••••••1234'), 'maskovaný token');
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'aktualizace-zjistit']);
    ok(str_contains($r['body'], 'nemá vydání (HTTP 404)'), mb_substr(strip_tags($r['body']), 0, 500));
    ok(!str_contains($r['body'], 'Nainstalovat verzi'));
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'aktualizace-instalovat', 'verze' => '9.9.9']);
    ok(str_contains($r['body'], 'už není k dispozici'));
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'aktualizace-vratit']);
    ok(str_contains($r['body'], 'Předchozí verze není uložená'));
    eq('twb-galerie', $app->aktualizace()->stav()['app']);
});
