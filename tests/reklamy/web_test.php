<?php
declare(strict_types=1);
// TWB Reklamy 0.1: aplikace na vestavěném webovém serveru – instalace, přihlášení, stránky, formuláře, ochrana.
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

echo "Reklamy: web\n";
$s = new TestServer();
register_shutdown_function(fn() => $s->zastav());
$b = new Prohlizec($s);

test('nenainstalovaná aplikace vede na instalaci; ochranné stránky nepustí dál', function () use ($s, $b) {
    $r = $b->jdi('/');
    eq(302, $r['code']);
    eq('install.php', $r['location']);
    eq(403, $b->jdi('cron.php')['code']);
    eq(403, $b->jdi('cron.php?key=spatny')['code']);
    eq(403, $b->jdi('health.php?key=x')['code']);
    eq(403, $b->jdi('media.php?id=1')['code']);
});

test('instalace: kontrola hesla, pak přihlášení', function () use ($s, $b) {
    $r = $b->jdi('install.php');
    ok(str_contains($r['body'], 'Nainstalovat'));
    ok(!str_contains($r['body'], 'name="base_url"'), 'config.php existuje, adresa se nezadává');
    $r = $b->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'jine']);
    ok(str_contains($r['body'], 'Hesla se neshodují'));
    $r = $b->jdi('install.php', ['heslo' => 'kratke', 'heslo2' => 'kratke']);
    ok(str_contains($r['body'], 'aspoň 8 znaků'));
    ok(!str_contains($r['body'], 'value="kratke"'), 'heslo se do stránky nevrací');
    $r = $b->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'tajneheslo1']);
    eq(303, $r['code']);
    $r = $b->jdi('index.php');
    eq(200, $r['code']);
    ok(str_contains($r['body'], '<h1>Přehled</h1>'));
    ok(str_contains($r['body'], 'Zálohy nejsou nastavené'));
    eq(302, $b->jdi('install.php')['code'], 'instalace podruhé nejde');
});

test('všechny stránky se načtou bez chyb', function () use ($b) {
    foreach (['prehled', 'kampane', 'kampan', 'bannery', 'weby', 'galerie', 'nastaveni', 'protokol'] as $p) {
        $r = $b->jdi("index.php?p=$p");
        eq(200, $r['code'], $p);
        ok(str_contains($r['body'], '<h1>'), $p);
    }
    eq(404, $b->jdi('index.php?p=neexistuje')['code']);
    eq(404, $b->jdi('index.php?p=kampan&id=999')['code']);
    eq(404, $b->jdi('index.php?p=banner&id=999')['code']);
    eq(404, $b->jdi('index.php?p=zona&id=999')['code']);
});

test('formulář bez CSRF se odmítne', function () use ($s, $b) {
    $r = $b->jdi('index.php?p=kampan', ['name' => 'Podvržená', 'csrf' => 'x']);
    eq(303, $r['code']);
    ok(str_contains($b->jdi($r['location'])['body'], 'Formulář vypršel'));
    eq(0, (int)$s->db()->query('SELECT COUNT(*) FROM campaigns')->fetchColumn());
});

test('kampaň, banner, web, zóna a přiřazení přes formuláře', function () use ($s, $b) {
    $b->jdi('index.php?p=kampan');
    $r = $b->jdi('index.php?p=kampan', ['name' => 'Advent <b>', 'priority' => '15']);
    eq(200, $r['code']);
    ok(str_contains($r['body'], 'Priorita'), 'chyba u pole');
    ok(str_contains($r['body'], 'value="Advent &lt;b&gt;"'), 'formulář zůstal vyplněný a escapovaný');
    $r = $b->odesli('index.php?p=kampan', ['name' => 'Advent <b>', 'priority' => '7', 'kind' => 'standard', 'status' => 'active', 'starts_at' => '2026-11-20T08:00', 'tags' => 'zima']);
    eq('index.php?p=kampan&id=1', $r['presmerovano']);
    ok(str_contains($r['body'], 'Uloženo.'));
    ok(str_contains($r['body'], 'Advent &lt;b&gt;'));
    $r = $b->odesli('index.php?p=banner&kampan=1&typ=html', ['name' => 'Čtverec', 'width' => '300', 'height' => '250', 'html' => '<p onclick="x()">Ahoj</p>', 'target_url' => 'https://kazbo.eu']);
    eq('index.php?p=banner&id=1', $r['presmerovano']);
    ok(str_contains($r['body'], 'srcdoc="&lt;p onclick=&quot;x()&quot;&gt;Ahoj&lt;/p&gt;"'), 'náhled HTML v izolovaném rámci');
    $r = $b->odesli('index.php?p=weby', ['akce' => 'novy-web', 'name' => 'Kazbo', 'url' => 'https://kazbo.eu']);
    eq('index.php?p=web&id=1', $r['presmerovano']);
    $r = $b->odesli('index.php?p=web&id=1', ['akce' => 'nova-zona', 'name' => 'Vpravo', 'kind' => 'banner', 'width' => '300', 'height' => '250']);
    eq('index.php?p=zona&id=1', $r['presmerovano']);
    $r = $b->odesli('index.php?p=zona&id=1', ['akce' => 'priradit', 'kampane' => ['1']]);
    ok(str_contains($r['body'], 'Bannery v zóně (1)'));
    eq(1, (int)$s->db()->query('SELECT COUNT(*) FROM zone_campaigns')->fetchColumn());
    $r = $b->jdi('index.php?p=web&id=1', ['akce' => 'nova-zona', 'name' => '', 'width' => '300']);
    ok(str_contains($r['body'], 'Vyplňte pole'));
    ok(str_contains($r['body'], 'value="Kazbo"'), 'formulář webu nepřevzal hodnoty formuláře zóny');
    foreach (['kampane', 'bannery', 'weby', 'prehled', 'kampan&id=1', 'banner&id=1', 'web&id=1', 'zona&id=1'] as $p) eq(200, $b->jdi("index.php?p=$p")['code'], $p);
    // pozastavení a smazání
    $b->jdi('index.php?p=kampane');
    $b->odesli('index.php?p=kampane', ['akce' => 'stav', 'id' => '1', 'stav' => 'paused']);
    eq('paused', $s->db()->query('SELECT status FROM campaigns WHERE id = 1')->fetchColumn());
    $b->jdi('index.php?p=banner&id=1');
    $r = $b->odesli('index.php?p=banner&id=1', ['akce' => 'smazat']);
    eq('index.php?p=kampan&id=1', $r['presmerovano']);
    eq(0, (int)$s->db()->query('SELECT COUNT(*) FROM banners')->fetchColumn());
});

test('galerie: nahrání obrázku, banner z galerie, náhled jen po přihlášení', function () use ($s, $b) {
    $b->jdi('index.php?p=galerie');
    $png = pngSoubor(728, 90);
    $r = $b->odesli('index.php?p=galerie', ['akce' => 'nahrat', 'soubory[0]' => new CURLFile($png, 'image/png', 'leaderboard.png')]);
    ok(str_contains($r['body'], 'Nahráno: leaderboard.png'), mb_substr(strip_tags($r['body']), 0, 300));
    $txt = docasna('txt');
    file_put_contents($txt, 'neni obrazek');
    $r = $b->odesli('index.php?p=galerie', ['akce' => 'nahrat', 'soubory[0]' => new CURLFile($txt, 'image/png', 'podvrh.png')]);
    ok(str_contains($r['body'], 'není obrázek'), 'obsah souboru se kontroluje');
    $m = $b->jdi('media.php?id=1');
    eq([200, 'image/png'], [$m['code'], $m['type']]);
    $b->jdi('index.php?p=banner&kampan=1&typ=image');
    $r = $b->odesli('index.php?p=banner&kampan=1&typ=image', ['name' => 'Leader', 'media_id' => '1']);
    eq('index.php?p=banner&id=2', $r['presmerovano']);
    eq(['728', '90'], array_map('strval', array_values($s->db()->query('SELECT width, height FROM banners WHERE id = 2')->fetch())));
    $b->jdi('index.php?p=galerie');
    $r = $b->odesli('index.php?p=galerie', ['akce' => 'smazat', 'id' => '1']);
    ok(str_contains($r['body'], 'používá banner'));
});

test('nastavení: zálohy, změna hesla, stažení zálohy', function () use ($s, $b) {
    $b->jdi('index.php?p=nastaveni');
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'zalohy-heslo', 'backup_password' => 'kratke']);
    ok(str_contains($r['body'], 'aspoň 10 znaků'));
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'zalohy-heslo', 'backup_password' => 'heslo-zaloh-123', 'backup_auto' => '1']);
    ok(str_contains($r['body'], 'Zálohovat teď'));
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'zaloha']);
    ok(preg_match('/Záloha vytvořena: (twb-reklamy-[a-z0-9-]+\.zip)/', $r['body'], $m) === 1);
    $z = $b->jdi('index.php?p=nastaveni&stahnout=' . $m[1]);
    eq([200, 'application/zip'], [$z['code'], $z['type']]);
    eq("PK", substr($z['body'], 0, 2));
    eq(404, $b->jdi('index.php?p=nastaveni&stahnout=../../config.php')['code']);
    $b->jdi('index.php?p=nastaveni');
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'heslo', 'stare' => 'spatne', 'nove' => 'noveheslo1', 'nove2' => 'noveheslo1']);
    ok(str_contains($r['body'], 'Současné heslo nesouhlasí'));
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'heslo', 'stare' => 'tajneheslo1', 'nove' => 'noveheslo1', 'nove2' => 'noveheslo1']);
    ok(str_contains($r['body'], 'Heslo změněno.'));
});

test('cron a kontrola zdraví s klíčem', function () use ($s, $b) {
    $r = $b->jdi('cron.php?key=tajny-cron-klic-123');
    eq(200, $r['code']);
    ok(str_starts_with($r['body'], 'OK'), $r['body']);
    ok(str_contains($r['body'], 'Záloha'), 'cron zálohuje');
    ok((int)$s->db()->query("SELECT v FROM settings WHERE k = 'cron_last_run'")->fetchColumn() > time() - 60);
    $s->db()->exec("INSERT OR REPLACE INTO settings (k, v) VALUES ('update_health_key', 'klic-zdravi')");
    $r = $b->jdi('health.php?key=klic-zdravi');
    $j = json_decode($r['body'], true);
    eq([true, trim((string)file_get_contents(KOREN . '/apps/reklamy/VERSION'))], [$j['ok'] ?? null, $j['version'] ?? null]);
    eq(403, $b->jdi('health.php?key=jiny')['code']);
});

test('Passkey rozhraní: jen POST s CSRF, mimo HTTPS nedostupné', function () use ($s, $b) {
    eq(405, $b->jdi('passkey.php?akce=moznosti-prihlaseni')['code']);
    eq(403, $b->jdi('passkey.php?akce=moznosti-prihlaseni', json: json_encode(['csrf' => 'x']))['code']);
    // 127.0.0.1 je bezpečný kontext, takže výzva se vydá
    $r = $b->jdi('passkey.php?akce=moznosti-registrace', json: json_encode(['csrf' => $b->csrf]));
    $j = json_decode($r['body'], true);
    eq([200, true, '127.0.0.1'], [$r['code'], $j['ok'] ?? null, $j['publicKey']['rp']['id'] ?? null]);
    $r = $b->jdi('passkey.php?akce=registrovat', json: json_encode(['csrf' => $b->csrf, 'credential' => ['response' => ['clientDataJSON' => 'xx']]]));
    eq(400, $r['code']);
});

test('odhlášení, chybné heslo a zámek po pěti pokusech', function () use ($s, $b) {
    $b->jdi('index.php');
    $r = $b->jdi('index.php?p=odhlasit', []);
    eq(303, $r['code']);
    $r = $b->jdi('index.php');
    ok(str_contains($r['body'], '<h1>Přihlášení</h1>'));
    eq(403, $b->jdi('media.php?id=1')['code']);
    $r = $b->jdi('index.php', ['akce' => 'prihlasit', 'heslo' => 'tajneheslo1']);
    ok(str_contains($r['body'], 'Nesprávné heslo'), 'staré heslo už neplatí');
    $r = $b->jdi('index.php', ['akce' => 'prihlasit', 'heslo' => 'noveheslo1']);
    eq(303, $r['code']);
    eq(200, $b->jdi('index.php?p=kampane')['code']);
    $b->jdi('index.php?p=odhlasit', []);
    $b->jdi('index.php');
    for ($i = 0; $i < 5; $i++) $r = $b->jdi('index.php', ['akce' => 'prihlasit', 'heslo' => 'spatne' . $i]);
    ok(str_contains($r['body'], 'Příliš mnoho pokusů'));
    $r = $b->jdi('index.php', ['akce' => 'prihlasit', 'heslo' => 'noveheslo1']);
    ok(str_contains($r['body'], 'Příliš mnoho pokusů'), 'ani správné heslo během zámku');
    $log = implode("\n", $s->db()->query('SELECT message FROM log')->fetchAll(PDO::FETCH_COLUMN));
    ok(str_contains($log, 'zablokováno'));
});
