<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';

use TWB\Aktualizace\{Aktualizace, Manifest, Balicek};
use TWB\Zalohy\Zalohy;

const APP_SLUG = 'test-app';
const ZIP_KOREN = 'test-app/';

function klice(): array
{
    static $k = null;
    if ($k === null) { $p = sodium_crypto_sign_keypair(); $k = ['tajny' => sodium_crypto_sign_secretkey($p), 'verejny' => base64_encode(sodium_crypto_sign_publickey($p))]; }
    return $k;
}

/** Podepsaná odpověď latest.php. */
function podepsane(array $payload, ?string $tajny = null): string
{
    $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $sig = sodium_crypto_sign_detached($raw, $tajny ?? klice()['tajny']);
    return json_encode(['algorithm' => 'Ed25519', 'payload' => $payload, 'payload_b64' => base64_encode($raw), 'signature' => base64_encode($sig)]);
}

function vydani(string $verze, string $zipCesta, array $zmena = []): array
{
    return $zmena + ['app' => APP_SLUG, 'version' => $verze, 'release_id' => "rel-$verze", 'size' => filesize($zipCesta), 'sha256' => hash_file('sha256', $zipCesta),
                     'released_at' => '2027-01-01T00:00:00Z', 'download_path' => '/api/v1/download.php?id=' . $verze];
}

/** ZIP s balíčkem: [relativní cesta => obsah]. */
function balicek(string $verze, array $soubory, string $koren = ZIP_KOREN, ?array $nahrad = null): string
{
    $soubory = $nahrad ?? ($soubory + ['VERSION' => $verze . "\n"]);
    $cesta = sys_get_temp_dir() . '/twb-bal-' . bin2hex(random_bytes(4)) . '.zip';
    $z = new ZipArchive(); $z->open($cesta, ZipArchive::CREATE);
    foreach ($soubory as $n => $obsah) $z->addFromString($koren . $n, $obsah);
    $z->close();
    return $cesta;
}

/**
 * Nainstalovaná aplikace verze 1.0.0 + falešný server.
 * $server->zip = cesta ke ZIPu ke stažení; $server->latest = tělo odpovědi.
 */
function prostredi(array $opts = []): array
{
    $h = novyHost();
    $app = sys_get_temp_dir() . '/twb-app-' . bin2hex(random_bytes(4));
    mkdir("$app/lib", 0775, true); mkdir("$app/data", 0775, true);
    file_put_contents("$app/VERSION", "1.0.0\n");
    file_put_contents("$app/index.php", 'stare');
    file_put_contents("$app/lib/stary.php", 'stary soubor');
    file_put_contents("$app/config.php", 'moje-tajemstvi');
    file_put_contents("$app/data/db.sqlite", 'data');
    $h->nastaveni->uloz('backup_password', 'heslo-zaloh');
    $h->nastaveni->uloz('schema_version', '5');
    $zalohy = new Zalohy($h, 'TWB Test', '1.0.0', 5, ['settings'], ['settings'], [], [], 'twb-test-zaloha-', 'Nastavení → Zálohy', null, fn() => 1_800_000_000);
    $server = (object)['zip' => null, 'latest' => null, 'kod' => 200, 'kodStazeni' => 200, 'volani' => [], 'zdravi' => null];
    $http = function (string $url, ?string $ulozit) use ($server): array {
        $server->volani[] = $url;
        if ($ulozit !== null) { if ($server->kodStazeni === 200) copy($server->zip, $ulozit); return ['code' => $server->kodStazeni, 'body' => '']; }
        return ['code' => $server->kod, 'body' => (string)$server->latest];
    };
    $zdravi = function (string $url) use ($app, $server, $h): array {
        if ($server->zdravi) return ($server->zdravi)($url, $app);
        return ['code' => 200, 'body' => json_encode(['ok' => true, 'version' => trim((string)file_get_contents("$app/VERSION"))]), 'error' => ''];
    };
    $t = 1_800_000_000;
    $a = new Aktualizace($h, $zalohy, APP_SLUG, 'TWB Test', '1.0.0', $app, $opts['klic'] ?? klice()['verejny'], ZIP_KOREN, 'https://example.test/app',
        $opts['token'] ?? 'upd_abcdefghijklmnop', 'https://updates.example.test', ['config.php', 'data'], ['index.php', 'lib/nove.php'], 'twb-test-', $opts['vyzadujToken'] ?? true, $http, $zdravi, fn() => $t);
    return [$h, $a, $server, $app, $zalohy];
}

function nabidni(object $server, Aktualizace $a, string $verze, array $soubory, array $zmenaVydani = []): string
{
    $zip = balicek($verze, $soubory);
    $server->zip = $zip;
    $server->latest = podepsane(vydani($verze, $zip, $zmenaVydani));
    $a->zkontroluj();
    return $zip;
}

$NOVE = ['index.php' => 'nove', 'lib/nove.php' => 'nova knihovna', 'config.php' => 'PREPSANO', 'data/db.sqlite' => 'PREPSANO'];

// ---------- manifest ----------
test('manifest: platné vydání se ověří a vrátí podepsané údaje', function () {
    $zip = balicek('1.1.0', ['a' => 'b']);
    $r = Manifest::over(podepsane(vydani('1.1.0', $zip)), APP_SLUG, klice()['verejny'], 1000000);
    eq('1.1.0', $r['version']);
    eq(hash_file('sha256', $zip), $r['sha256']);
});
test('manifest: „žádné vydání“ není chyba, ale jen pro správnou aplikaci', function () {
    eq(null, Manifest::over(json_encode(['app' => APP_SLUG, 'update' => null]), APP_SLUG, klice()['verejny'], 1000));
    throws(fn() => Manifest::over(json_encode(['app' => 'jina', 'update' => null]), APP_SLUG, klice()['verejny'], 1000), 'jinou aplikaci');
});
test('manifest: cizí podpis, cizí klíč a upravený obsah se odmítnou (kód 1)', function () {
    $zip = balicek('1.1.0', ['a' => 'b']);
    $cizi = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    foreach ([podepsane(vydani('1.1.0', $zip), $cizi)] as $telo) {
        try { Manifest::over($telo, APP_SLUG, klice()['verejny'], 1000000); throw new TestFailure('mělo selhat'); } catch (RuntimeException $e) { eq(1, $e->getCode()); }
    }
    $j = json_decode(podepsane(vydani('1.1.0', $zip)), true);
    $j['payload_b64'] = base64_encode(json_encode(vydani('9.9.9', $zip)));   // upravené bajty, původní podpis
    try { Manifest::over(json_encode($j), APP_SLUG, klice()['verejny'], 1000000); throw new TestFailure('mělo selhat'); } catch (RuntimeException $e) { eq(1, $e->getCode()); }
    $j = json_decode(podepsane(vydani('1.1.0', $zip)), true);
    $j['payload']['sha256'] = str_repeat('0', 64);   // nepodepsaný „payload“ se ignoruje
    eq('1.1.0', Manifest::over(json_encode($j), APP_SLUG, klice()['verejny'], 1000000)['version']);
    eq(hash_file('sha256', $zip), Manifest::over(json_encode($j), APP_SLUG, klice()['verejny'], 1000000)['sha256'], 'bere se podepsaný sha256');
    throws(fn() => Manifest::over(podepsane(vydani('1.1.0', $zip)), APP_SLUG, base64_encode(random_bytes(32)), 1000000), 'Podpis');
});
test('manifest: neplatný tvar údajů (verze, sha, velikost, cesta ke stažení, jiná aplikace)', function () {
    $zip = balicek('1.1.0', ['a' => 'b']);
    foreach ([['version' => '1.1'], ['version' => '1.1.0-beta'], ['sha256' => 'abc'], ['size' => 0], ['size' => 99999999], ['app' => 'jina'],
              ['download_path' => 'https://zly.example/x.zip'], ['download_path' => '/api/v1/download.php?id=../../x'], ['release_id' => '../x']] as $zmena) {
        throws(fn() => Manifest::over(podepsane(vydani('1.1.0', $zip, $zmena)), APP_SLUG, klice()['verejny'], 1000000), 'očekávaný tvar');
    }
    throws(fn() => Manifest::over('ne json', APP_SLUG, klice()['verejny'], 1), 'JSON');
    throws(fn() => Manifest::over('{"neco":1}', APP_SLUG, klice()['verejny'], 1), 'podpis');
});

// ---------- kontrola ----------
test('kontrola nabídne novější verzi a neukáže stejnou ani starší', function () {
    [$h, $a, $server] = prostredi();
    nabidni($server, $a, '1.1.0', ['index.php' => 'x']);
    eq('1.1.0', $a->stav()['available']['version']);
    eq('ok', $a->stav()['result']['result']);
    nabidni($server, $a, '1.0.0', ['index.php' => 'x']);
    eq(null, $a->stav()['available']);
    ok(str_contains($a->stav()['result']['message'], 'aktuální'));
    nabidni($server, $a, '0.9.0', ['index.php' => 'x']);
    eq(null, $a->stav()['available']);
});
test('kontrola: chyby serveru, token, 404 a špatný podpis se zapíšou s kódem výsledku', function () {
    [$h, $a, $server] = prostredi();
    foreach ([[401, 'auth_error'], [403, 'auth_error'], [404, 'no_release'], [500, 'server_error']] as [$kod, $vysl]) {
        $server->kod = $kod; $a->zkontroluj();
        eq($vysl, $a->stav()['result']['result'], "HTTP $kod");
    }
    $server->kod = 200;
    $server->latest = podepsane(vydani('1.1.0', balicek('1.1.0', ['a' => 'b'])), sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
    $a->zkontroluj();
    eq('signature_error', $a->stav()['result']['result']);
    eq(null, $a->stav()['available']);
    $server->latest = 'rozbitý';
    $a->zkontroluj();
    eq('server_error', $a->stav()['result']['result']);
});
test('kontrola vyžaduje token (kromě režimu public_signed); token je v přehledu zamaskovaný', function () {
    [$h, $a] = prostredi(['token' => '']);
    ok(str_contains((string)$a->stav()['unsupported'], 'update_token'));
    $a->zkontroluj();
    eq('config_error', $a->stav()['result']['result']);
    [$h, $b, $server] = prostredi(['token' => '', 'vyzadujToken' => false]);
    eq(null, $b->stav()['unsupported']);
    [$h, $c] = prostredi();
    eq('upd_••••••••mnop', $c->stav()['token']);
    ok(!str_contains(json_encode($c->stav()), 'abcdefgh'), 'celý token nikde není');
});
test('automatická kontrola jen jednou za 24 hodin', function () {
    [$h, $a, $server] = prostredi();
    $server->latest = json_encode(['app' => APP_SLUG, 'update' => null]);
    $a->kontrolaDojde(); $a->kontrolaDojde();
    eq(1, count($server->volani));
    $h->nastaveni->uloz('update_checked_at', (string)(1_800_000_000 - 86400 - 1));
    $a->kontrolaDojde();
    eq(2, count($server->volani));
});

// ---------- instalace ----------
test('instalace: přepíše soubory, zachová config.php a data, uloží předchozí verzi a zálohu', function () use ($NOVE) {
    [$h, $a, $server, $app, $zalohy] = prostredi();
    nabidni($server, $a, '1.1.0', $NOVE);
    $r = $a->instaluj('1.1.0');
    eq(true, $r['ok'], $r['message']);
    eq('nove', file_get_contents("$app/index.php"));
    eq('nova knihovna', file_get_contents("$app/lib/nove.php"));
    eq("1.1.0\n", file_get_contents("$app/VERSION"));
    eq('moje-tajemstvi', file_get_contents("$app/config.php"), 'config.php se nepřepisuje');
    eq('data', file_get_contents("$app/data/db.sqlite"), 'data se nepřepisují');
    eq('1.0.0', $a->stav()['previous']);
    eq(null, $a->stav()['available']);
    ok(count(array_filter($zalohy->seznam(), fn($b) => str_contains($b['name'], '-pred-aktualizaci'))) === 1, 'záloha před aktualizací');
    ok(is_file("$app/lib/stary.php"), 'cizí/starý soubor se při první aktualizaci nemaže (evidence zatím nebyla)');
});
test('instalace: druhá aktualizace smaže jen soubory, které nainstalovala první a nové verze je nemá', function () {
    [$h, $a, $server, $app] = prostredi();
    nabidni($server, $a, '1.1.0', ['index.php' => 'v11', 'lib/nove.php' => 'n', 'lib/dalsi.php' => 'd']);
    ok($a->instaluj('1.1.0')['ok']);
    file_put_contents("$app/.user.ini", 'od hostingu');
    $a2 = new Aktualizace($h, (function () use ($h) { return new Zalohy($h, 'TWB Test', '1.1.0', 5, ['settings'], ['settings'], [], [], 'twb-test-zaloha-', 'x', null, fn() => 1_800_000_100); })(),
        APP_SLUG, 'TWB Test', '1.1.0', $app, klice()['verejny'], ZIP_KOREN, 'https://example.test/app', 'upd_abcdefghijklmnop', 'https://updates.example.test',
        ['config.php', 'data'], ['index.php', 'lib/nove.php'], 'twb-test-', true, fn($u, $s) => $s !== null ? (copy($server->zip, $s) ? ['code' => 200, 'body' => ''] : ['code' => 500, 'body' => '']) : ['code' => 200, 'body' => $server->latest],
        fn() => ['code' => 200, 'body' => json_encode(['ok' => true, 'version' => trim((string)file_get_contents("$app/VERSION"))]), 'error' => ''], fn() => 1_800_000_100);
    $server->zip = balicek('1.2.0', ['index.php' => 'v12', 'lib/nove.php' => 'n2']);
    $server->latest = podepsane(vydani('1.2.0', $server->zip));
    $a2->zkontroluj();
    ok($a2->instaluj('1.2.0')['ok']);
    ok(!is_file("$app/lib/dalsi.php"), 'soubor odstraněný z balíčku zmizel');
    ok(is_file("$app/.user.ini"), 'cizí soubor zůstal');
    ok(is_file("$app/lib/stary.php"), 'soubor z doby před evidencí zůstal');
    eq('v12', file_get_contents("$app/index.php"));
});
test('instalace: špatný kontrolní součet nebo velikost – nic se nezmění', function () use ($NOVE) {
    [$h, $a, $server, $app] = prostredi();
    $zip = balicek('1.1.0', $NOVE);
    $server->latest = podepsane(vydani('1.1.0', $zip, ['sha256' => str_repeat('a', 64)]));
    $server->zip = $zip;
    $a->zkontroluj();
    $r = $a->instaluj('1.1.0');
    eq(false, $r['ok']);
    ok(str_contains($r['message'], 'Kontrolní součet'));
    eq('stare', file_get_contents("$app/index.php"));
    eq('hash_error', $a->stav()['result']['result']);
});
test('instalace: stažení selže – chyba se zapíše jednou', function () use ($NOVE) {
    [$h, $a, $server, $app] = prostredi();
    nabidni($server, $a, '1.1.0', $NOVE);
    $server->kodStazeni = 403;
    $r = $a->instaluj('1.1.0');
    eq(false, $r['ok']);
    eq('auth_error', $a->stav()['result']['result']);
    eq(1, count(array_filter($h->protokol->zaznamy, fn($z) => str_contains($z[1], 'Stažení balíčku'))), 'chyba se zapíše právě jednou');
    eq('stare', file_get_contents("$app/index.php"));
});
test('instalace: balíček s verzí, která nesedí, nebo bez povinného souboru se odmítne před zásahem do aplikace', function () use ($NOVE) {
    [$h, $a, $server, $app] = prostredi();
    $zip = balicek('1.1.0', [], ZIP_KOREN, ['index.php' => 'x', 'lib/nove.php' => 'x', 'VERSION' => "1.5.0\n"]);
    $server->zip = $zip; $server->latest = podepsane(vydani('1.1.0', $zip)); $a->zkontroluj();
    ok(str_contains($a->instaluj('1.1.0')['message'], 'místo 1.1.0'));
    $zip = balicek('1.1.0', [], ZIP_KOREN, ['index.php' => 'x', 'VERSION' => "1.1.0\n"]);
    $server->zip = $zip; $server->latest = podepsane(vydani('1.1.0', $zip)); $a->zkontroluj();
    ok(str_contains($a->instaluj('1.1.0')['message'], 'chybí lib/nove.php'));
    eq('stare', file_get_contents("$app/index.php"));
});
test('instalace: Zip Slip, soubory mimo složku a symbolické odkazy se odmítnou', function () {
    @unlink(sys_get_temp_dir() . '/utek.php');
    [$h, $a, $server, $app] = prostredi();
    foreach (['../utek.php', 'a/../../utek.php', '/abs.php', 'c:/win.php', 'a\\b.php'] as $zla) {
        $zip = balicek('1.1.0', [], ZIP_KOREN, ['index.php' => 'x', 'lib/nove.php' => 'x', 'VERSION' => "1.1.0\n", $zla => 'zlo']);
        throws(fn() => Balicek::rozbal($zip, sys_get_temp_dir() . '/twb-cil-' . bin2hex(random_bytes(3)), ZIP_KOREN, []), 'nepovolenou cestu');
    }
    $zip = sys_get_temp_dir() . '/twb-mimo-' . bin2hex(random_bytes(4)) . '.zip';
    $z = new ZipArchive(); $z->open($zip, ZipArchive::CREATE); $z->addFromString('jina-slozka/x.php', 'x'); $z->close();
    throws(fn() => Balicek::rozbal($zip, sys_get_temp_dir() . '/twb-cil-' . bin2hex(random_bytes(3)), ZIP_KOREN, []), 'mimo složku');
    $zip = sys_get_temp_dir() . '/twb-link-' . bin2hex(random_bytes(4)) . '.zip';
    $z = new ZipArchive(); $z->open($zip, ZipArchive::CREATE); $z->addFromString(ZIP_KOREN . 'odkaz', '/etc/passwd');
    $z->setExternalAttributesName(ZIP_KOREN . 'odkaz', ZipArchive::OPSYS_UNIX, 0120777 << 16); $z->close();
    throws(fn() => Balicek::rozbal($zip, sys_get_temp_dir() . '/twb-cil-' . bin2hex(random_bytes(3)), ZIP_KOREN, []), 'symbolický odkaz');
    ok(!is_file(sys_get_temp_dir() . '/utek.php'));
});
test('instalace: zachované cesty v balíčku se nerozbalí', function () {
    $zip = balicek('1.1.0', ['index.php' => 'x', 'config.php' => 'zlo', 'data/a.txt' => 'zlo', 'datamodel.php' => 'ok']);
    $cil = sys_get_temp_dir() . '/twb-cil-' . bin2hex(random_bytes(3));
    $soubory = Balicek::rozbal($zip, $cil, ZIP_KOREN, ['config.php', 'data']);
    ok(in_array('datamodel.php', $soubory, true), 'předpona „data“ nesmí zachytit „datamodel.php“');
    ok(!in_array('config.php', $soubory, true) && !in_array('data/a.txt', $soubory, true));
});
test('instalace: když nová verze neprojde kontrolou, vrátí se předchozí soubory', function () use ($NOVE) {
    [$h, $a, $server, $app] = prostredi();
    nabidni($server, $a, '1.1.0', $NOVE);
    $server->zdravi = fn() => ['code' => 500, 'body' => 'Fatal error', 'error' => ''];
    $r = $a->instaluj('1.1.0');
    eq(false, $r['ok']);
    ok(str_contains($r['message'], 'Vrácena předchozí verze 1.0.0'));
    eq('stare', file_get_contents("$app/index.php"));
    eq("1.0.0\n", file_get_contents("$app/VERSION"));
    ok(!is_file("$app/lib/nove.php"), 'soubor nové verze po návratu zmizel');
    eq('rollback', $a->stav()['result']['result']);
    eq('moje-tajemstvi', file_get_contents("$app/config.php"));
    eq(null, $h->nastaveni->dej('update_health_key'), 'tajný klíč kontroly se uklidí');
});
test('instalace: vrácení vrátí i databázi, když ji nová verze převedla na vyšší verzi dat', function () use ($NOVE) {
    [$h, $a, $server, $app] = prostredi();
    $h->nastaveni->uloz('moje_nastaveni', 'puvodni');
    nabidni($server, $a, '1.1.0', $NOVE);
    $server->zdravi = function () use ($h) {   // nová verze si „převedla“ databázi a pak selhala
        $h->nastaveni->uloz('schema_version', '6');
        $h->nastaveni->uloz('moje_nastaveni', 'po-prevodu');
        return ['code' => 500, 'body' => 'chyba', 'error' => ''];
    };
    $r = $a->instaluj('1.1.0');
    eq(false, $r['ok']);
    eq('5', $h->nastaveni->dej('schema_version'));
    eq('puvodni', $h->nastaveni->dej('moje_nastaveni'));
});
test('instalace: bez převodu databáze se databáze při návratu nesahá', function () use ($NOVE) {
    [$h, $a, $server, $app] = prostredi();
    nabidni($server, $a, '1.1.0', $NOVE);
    $server->zdravi = function () use ($h) { $h->nastaveni->uloz('novy_zaznam', 'zustane'); return ['code' => 500, 'body' => 'x', 'error' => '']; };
    $a->instaluj('1.1.0');
    eq('zustane', $h->nastaveni->dej('novy_zaznam'));
});
test('instalace: podmínky – nabídka, novější verze, heslo záloh, souběh', function () use ($NOVE) {
    [$h, $a, $server, $app] = prostredi();
    throws(fn() => $a->instaluj('1.1.0'), 'už není k dispozici');
    nabidni($server, $a, '1.1.0', $NOVE);
    throws(fn() => $a->instaluj('1.2.0'), 'už není k dispozici');
    $h->nastaveni->uloz('backup_password', '');
    throws(fn() => $a->instaluj('1.1.0'), 'heslo záloh');
    $h->nastaveni->uloz('backup_password', 'heslo-zaloh');
    mkdir($h->datovaSlozka . '/update', 0775, true);
    $zamek = fopen($h->datovaSlozka . '/update/install.lock', 'c'); flock($zamek, LOCK_EX);
    throws(fn() => $a->instaluj('1.1.0'), 'už probíhá');
    flock($zamek, LOCK_UN);
    eq('stare', file_get_contents("$app/index.php"));
});
test('ruční návrat bez uložené verze selže srozumitelně', function () {
    [$h, $a] = prostredi();
    throws(fn() => $a->vratPredchozi(), 'není uložená');
});
test('ruční návrat po úspěšné aktualizaci vrátí starou verzi', function () use ($NOVE) {
    [$h, $a, $server, $app] = prostredi();
    nabidni($server, $a, '1.1.0', $NOVE);
    ok($a->instaluj('1.1.0')['ok']);
    $r = $a->vratPredchozi();
    eq('1.0.0', $r['version']);
    eq('stare', file_get_contents("$app/index.php"));
    eq(null, $a->stav()['previous']);
});
test('neplatné parametry se odmítnou', function () {
    [$h, , , , $z] = prostredi();
    foreach ([['Zly Slug', '1.0.0', 'x/'], [APP_SLUG, '1.0', 'x/'], [APP_SLUG, '1.0.0', 'bez-lomitka']] as [$slug, $verze, $koren]) {
        throws(fn() => new Aktualizace($h, $z, $slug, 'A', $verze, sys_get_temp_dir(), 'k', $koren, 'https://x'));
    }
});
