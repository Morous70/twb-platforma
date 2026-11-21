<?php
declare(strict_types=1);
// TWB Reklamy 0.5: obnova zálohy a aktualizace v rozhraní (Nastavení).
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use Reklamy\App;

echo "Reklamy: obnova zálohy a aktualizace v rozhraní\n";
$falesny = new TestServer();   // „Update Server“ bez API: latest.php vrátí 404
$s = new TestServer(['update_server' => $falesny->url, 'update_token' => 'upd_testovacitoken1234']);
register_shutdown_function(function () use ($s, $falesny) { $s->zastav(); $falesny->zastav(); });
$app = new App(KOREN . '/apps/reklamy', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
$app->migruj();
$app->heslo()->nastav('tajneheslo1');
$b = new Prohlizec($s);
$kampane = fn() => $s->db()->query('SELECT name FROM campaigns ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

test('obnova ze zálohy v úložišti: náhled obsahu, potvrzení, pojistná záloha', function () use ($b, $s, $app, $kampane) {
    $b->jdi('index.php');
    $b->jdi('index.php', ['akce' => 'prihlasit', 'heslo' => 'tajneheslo1']);
    $b->jdi('index.php?p=nastaveni');
    $b->odesli('index.php?p=nastaveni', ['akce' => 'zalohy-heslo', 'backup_password' => 'heslo-zaloh-123', 'backup_auto' => '1']);
    kampan($app, ['name' => 'Před zálohou']);
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'zaloha']);
    ok(str_contains($r['body'], 'Záloha vytvořena'));
    preg_match('/<option value="(twb-reklamy-[a-z0-9-]+\.zip)"/', $r['body'], $m);
    ok(isset($m[1]), 'záloha v nabídce obnovy');
    kampan($app, ['name' => 'Po záloze']);
    $r = $b->jdi('index.php?p=nastaveni', ['akce' => 'obnova-nahled', 'zaloha' => $m[1], 'heslo' => 'spatne-heslo']);
    ok(str_contains($r['body'], 'nesprávné heslo'), 'špatné heslo zálohy');
    $r = $b->jdi('index.php?p=nastaveni', ['akce' => 'obnova-nahled', 'zaloha' => $m[1], 'heslo' => '']);
    ok(str_contains($r['body'], 'Obnovit zálohu ' . $m[1]) && str_contains($r['body'], 'kampaně 1,'), 'náhled: ' . mb_substr(strip_tags($r['body']), 0, 300));
    ok(!str_contains($r['body'], 'heslo-zaloh-123'), 'heslo se do stránky nevrací');
    eq(['Před zálohou', 'Po záloze'], $kampane(), 'náhled nic nemění');
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'obnova', 'zaloha' => 'twb-reklamy-jina.zip']);
    ok(str_contains($r['body'], 'Potvrzení obnovy vypršelo'));
    $b->jdi('index.php?p=nastaveni', ['akce' => 'obnova-nahled', 'zaloha' => $m[1]]);
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'obnova', 'zaloha' => $m[1]]);
    ok(str_contains($r['body'], 'Záloha obnovena'), mb_substr(strip_tags($r['body']), 0, 400));
    eq(['Před zálohou'], $kampane());
    ok(str_contains($r['body'], 'před obnovou'), 'pojistná záloha v seznamu');
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'obnova', 'zaloha' => $m[1]]);
    ok(str_contains($r['body'], 'Potvrzení obnovy vypršelo'), 'potvrzení platí jednou');
    eq(200, $b->jdi('index.php?p=protokol')['code']);
});

test('obnova z nahraného souboru; zrušení', function () use ($b, $s, $app, $kampane) {
    $r = $b->jdi('index.php?p=nastaveni');
    preg_match('/href="(index\.php\?p=nastaveni&amp;stahnout=[^"]+)"/', $r['body'], $m);
    $zip = $b->jdi(html_entity_decode($m[1]));
    eq('application/zip', $zip['type']);
    $soubor = docasna('zaloha') . '.zip';
    file_put_contents($soubor, $zip['body']);
    kampan($app, ['name' => 'Navíc']);
    $r = $b->jdi('index.php?p=nastaveni', ['akce' => 'obnova-nahled', 'zaloha' => '', 'heslo' => 'heslo-zaloh-123', 'soubor' => new CURLFile($soubor, 'application/zip', 'moje.zip')]);
    ok(preg_match('/Obnovit zálohu (twb-reklamy-[0-9-]+-nahrana\.zip)/', $r['body'], $n) === 1, mb_substr(strip_tags($r['body']), 0, 300));
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'obnova-zrusit']);
    ok(str_contains($r['body'], 'Obnova zrušena') && str_contains($r['body'], '– nahraná'));
    eq(['Před zálohou', 'Navíc'], $kampane(), 'zrušení nic nezmění');
    $r = $b->jdi('index.php?p=nastaveni', ['akce' => 'obnova-nahled', 'zaloha' => '']);
    ok(str_contains($r['body'], 'Vyberte zálohu nebo nahrajte soubor'));
    $r = $b->jdi('index.php?p=nastaveni', ['akce' => 'obnova-nahled', 'zaloha' => '../config.php']);
    ok(str_contains($r['body'], 'Záloha nenalezena'));
});

test('aktualizace: stav, zjištění nové verze, instalace bez nabídky', function () use ($b) {
    $r = $b->jdi('index.php?p=nastaveni');
    ok(str_contains($r['body'], 'id="aktualizace"') && str_contains($r['body'], 'upd_••••••••1234'), 'maskovaný token');
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'aktualizace-zjistit']);
    ok(str_contains($r['body'], 'nemá vydání (HTTP 404)'), mb_substr(strip_tags($r['body']), 0, 500));
    ok(!str_contains($r['body'], 'Nainstalovat verzi'));
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'aktualizace-instalovat', 'verze' => '9.9.9']);
    ok(str_contains($r['body'], 'už není k dispozici'));
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'aktualizace-vratit']);
    ok(str_contains($r['body'], 'Předchozí verze není uložená'));
});

test('aktualizace se ptá Update Serveru na slug twb-reklamy', function () use ($app) {
    eq('twb-reklamy', $app->aktualizace()->stav()['app']);
});
