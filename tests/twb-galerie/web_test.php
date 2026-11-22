<?php
declare(strict_types=1);
// TWB Media galerie 0.1: kostra na vestavěném webovém serveru – instalace, přihlášení (heslo, zámek, obnova hesla e-mailem), stránky, nastavení, ochrana.
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use MediaGalerie\{App, Schema};

echo "Media galerie: web\n";
$s = new TestServer();
register_shutdown_function(fn() => $s->zastav());
$b = new Prohlizec($s);

/** Poslední e-mail uložený do souboru (mail_to_file). */
function posledniMail(TestServer $s): ?string
{
    $f = glob($s->data . '/mail/mail-*.txt') ?: [];
    sort($f);
    return $f ? (string)file_get_contents(end($f)) : null;
}

test('aplikace: migrace modulů a schématu, opakovaně bez změny', function () {
    $app = novaApp();
    eq(Schema::verze(), (int)$app->nastaveni()->dej('schema_version'));
    foreach (App::TABULKY as $t) $app->db->q("SELECT COUNT(*) FROM $t")->fetchColumn();
    $app->db->q('SELECT COUNT(*) FROM passkeys')->fetchColumn();
    $app->db->q('SELECT COUNT(*) FROM auth_tokens')->fetchColumn();
    eq(false, $app->migruj(), 'podruhé se nic nemění');
    eq(false, $app->nainstalovano(), 'bez hesla není nainstalováno');
    ok(!in_array('passkeys', App::TABULKY, true) && !in_array('auth_tokens', App::TABULKY, true), 'Passkey a tokeny se nezálohují');
});

test('aplikace: odhlášení po nečinnosti z nastavení, jinak z config.php', function () {
    eq(App::NECINNOST_MIN * 60, novaApp()->limitNecinnosti());
    $app = novaApp(['session_timeout_min' => 30]);
    eq(1800, $app->limitNecinnosti());
    $app->nastaveni()->uloz('idle_minutes', '0');
    eq(0, $app->limitNecinnosti());
});

test('nenainstalovaná aplikace vede na instalaci; ochranné stránky nepustí dál', function () use ($b) {
    $r = $b->jdi('/');
    eq(302, $r['code']);
    eq('install.php', $r['location']);
    eq(403, $b->jdi('health.php?key=x')['code']);
});

test('instalace: kontrola hesla a e-mailu, pak přihlášení', function () use ($s, $b) {
    $r = $b->jdi('install.php');
    ok(str_contains($r['body'], 'Nainstalovat'));
    ok(str_contains($r['body'], 'name="reset_email"'));
    $r = $b->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'jine']);
    ok(str_contains($r['body'], 'Hesla se neshodují'));
    $r = $b->jdi('install.php', ['heslo' => 'kratke', 'heslo2' => 'kratke']);
    ok(str_contains($r['body'], 'aspoň 8 znaků'));
    ok(!str_contains($r['body'], 'value="kratke"'), 'heslo se do stránky nevrací');
    $r = $b->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'tajneheslo1', 'reset_email' => 'neni-email']);
    ok(str_contains($r['body'], 'platný e-mail'));
    $r = $b->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'tajneheslo1', 'reset_email' => 'ja@kazbo.eu']);
    eq(303, $r['code']);
    $r = $b->jdi('index.php');
    eq(200, $r['code']);
    ok(str_contains($r['body'], '<h1>Přehled <a class="pomoc"'));
    ok(str_contains($r['body'], 'TWB Media galerie'));
    eq('ja@kazbo.eu', $s->db()->query("SELECT v FROM settings WHERE k = 'reset_email'")->fetchColumn());
    eq(302, $b->jdi('install.php')['code'], 'instalace podruhé nejde');
});

test('všechny stránky se načtou bez chyb', function () use ($b) {
    foreach (['prehled', 'galerie', 'kampane', 'api', 'napoveda', 'nastaveni', 'protokol'] as $p) {
        $r = $b->jdi("index.php?p=$p");
        eq(200, $r['code'], $p);
        ok(str_contains($r['body'], '<h1>'), $p);
    }
    foreach (['co-aplikace-umi', 'nahravani', 'verejne-odkazy', 'kampane-a-tagy', 'prihlaseni', 'zapomenute-heslo', 'google', 'api'] as $st) eq(200, $b->jdi("index.php?p=napoveda&s=$st")['code'], $st);
    eq(404, $b->jdi('index.php?p=neexistuje')['code']);
    eq(404, $b->jdi('index.php?p=napoveda&s=neni')['code']);
});

test('formulář bez CSRF se odmítne', function () use ($s, $b) {
    $r = $b->jdi('index.php?p=nastaveni', ['akce' => 'obnova-email', 'reset_email' => 'utocnik@example.com', 'csrf' => 'x']);
    eq(303, $r['code']);
    ok(str_contains($b->jdi($r['location'])['body'], 'Formulář vypršel'));
    eq('ja@kazbo.eu', $s->db()->query("SELECT v FROM settings WHERE k = 'reset_email'")->fetchColumn());
});

test('nastavení: e-mail pro obnovu, odhlášení po nečinnosti, změna hesla', function () use ($s, $b) {
    $b->jdi('index.php?p=nastaveni');
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'necinnost', 'idle_minutes' => '3']);
    ok(str_contains($r['body'], '5 až 1440'));
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'necinnost', 'idle_minutes' => '45']);
    ok(str_contains($r['body'], 'Uloženo'));
    ok(str_contains($r['body'], 'data-necinnost="2700"'), 'limit se pošle do stránky');
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'obnova-email', 'reset_email' => 'spatne']);
    ok(str_contains($r['body'], 'platný e-mail'));
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'heslo', 'stare' => 'spatne-heslo', 'nove' => 'noveheslo1', 'nove2' => 'noveheslo1']);
    ok(str_contains($r['body'], 'Současné heslo nesouhlasí'));
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'heslo', 'stare' => 'tajneheslo1', 'nove' => 'noveheslo1', 'nove2' => 'noveheslo1']);
    ok(str_contains($r['body'], 'Heslo změněno'));
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'obnova-email', 'reset_email' => '']);
    ok(str_contains($b->jdi('index.php')['body'], 'Není nastavený e-mail pro obnovu'));
    $b->odesli('index.php?p=nastaveni', ['akce' => 'obnova-email', 'reset_email' => 'ja@kazbo.eu']);
    ok(!str_contains($b->jdi('index.php')['body'], 'Není nastavený e-mail pro obnovu'));
});

test('odhlášení jen POSTem, pak přihlášení heslem', function () use ($b) {
    eq(303, $b->jdi('index.php?p=odhlasit')['code']);
    eq(200, $b->jdi('index.php?p=prehled')['code'], 'GET neodhlásí');
    $r = $b->odesli('index.php?p=odhlasit', []);
    ok(str_contains($r['body'], 'name="heslo"'));
    $r = $b->jdi('index.php', ['akce' => 'prihlasit', 'heslo' => 'noveheslo1']);
    eq(303, $r['code']);
    ok(str_contains($b->jdi('index.php')['body'], '<h1>Přehled'));
    $b->odesli('index.php?p=odhlasit', []);
});

test('zámek po pěti chybných pokusech, i správné heslo pak počká', function () use ($s, $b) {
    $b->jdi('index.php');
    for ($i = 1; $i <= 5; $i++) {
        $r = $b->jdi('index.php', ['akce' => 'prihlasit', 'heslo' => 'spatne' . $i]);
        eq(401, $r['code']);
    }
    ok(str_contains($r['body'], 'minut') || str_contains($r['body'], 'pokus'), 'hláška zámku');
    $r = $b->jdi('index.php', ['akce' => 'prihlasit', 'heslo' => 'noveheslo1']);
    eq(401, $r['code'], 'zamčeno i pro správné heslo');
    $s->db()->exec("UPDATE settings SET v = '0' WHERE k = 'login_fails'");
});

test('zapomenuté heslo: jednotná odpověď, cizí adresa nic nepošle, odkaz jednou', function () use ($s, $b) {
    $r = $b->jdi('index.php?zapomenute');
    ok(str_contains($r['body'], 'Poslat odkaz'));
    $r = $b->jdi('index.php?zapomenute', ['akce' => 'zapomenute', 'email' => 'cizi@example.com']);
    ok(str_contains($r['body'], 'Pokud adresa souhlasí'));
    eq(null, posledniMail($s), 'na cizí adresu nic');
    $s->db()->exec("DELETE FROM settings WHERE k = 'reset_last_sent'");
    $r = $b->jdi('index.php?zapomenute', ['akce' => 'zapomenute', 'email' => 'JA@kazbo.eu']);
    ok(str_contains($r['body'], 'Pokud adresa souhlasí'));
    $mail = posledniMail($s);
    ok($mail !== null && str_contains($mail, 'To: ja@kazbo.eu'));
    ok(preg_match('~(http://\S+/index\.php\?reset=([0-9a-f]+))~', (string)$mail, $m) === 1, 'odkaz z base_url');
    $token = $m[2];
    $r = $b->jdi('index.php?reset=' . $token);
    ok(str_contains($r['body'], 'Nastavit heslo'));
    $r = $b->jdi('index.php?reset=' . $token, ['akce' => 'reset', 'heslo' => 'kratke', 'heslo2' => 'kratke']);
    ok(str_contains($r['body'], 'aspoň 8'));
    ok(str_contains($b->jdi('index.php?reset=' . $token)['body'], 'Nastavit heslo'), 'krátké heslo token nespotřebuje');
    $r = $b->jdi('index.php?reset=' . $token, ['akce' => 'reset', 'heslo' => 'obnovene1', 'heslo2' => 'obnovene1']);
    eq(303, $r['code']);
    ok(str_contains($b->jdi('index.php')['body'], 'Heslo je změněné'));
    ok(str_contains($b->jdi('index.php?reset=' . $token)['body'], 'už neplatí'), 'odkaz jen jednou');
    $r = $b->jdi('index.php?reset=' . $token, ['akce' => 'reset', 'heslo' => 'jineheslo1', 'heslo2' => 'jineheslo1']);
    eq(401, $r['code']);
    ok((int)$s->db()->query("SELECT v FROM settings WHERE k = 'login_fails'")->fetchColumn() >= 1, 'neplatný odkaz se počítá do zámku');
    $s->db()->exec("UPDATE settings SET v = '0' WHERE k = 'login_fails'");
    eq(303, $b->jdi('index.php', ['akce' => 'prihlasit', 'heslo' => 'obnovene1'])['code']);
});

test('protokol zaznamenal přihlášení a změny', function () use ($s, $b) {
    $r = $b->jdi('index.php?p=protokol');
    foreach (['Aplikace nainstalována', 'Heslo změněno', 'Odhlášení po nečinnosti: 45 min'] as $t) ok(str_contains($r['body'], $t), $t);
});

test('Passkey rozhraní: jen POST s CSRF; výzva registrace pro adresu aplikace', function () use ($b) {
    eq(405, $b->jdi('passkey.php?akce=moznosti-prihlaseni')['code']);
    eq(403, $b->jdi('passkey.php?akce=moznosti-prihlaseni', json: json_encode(['csrf' => 'x']))['code']);
    $b->jdi('index.php?p=nastaveni');
    // 127.0.0.1 je bezpečný kontext, takže výzva se vydá
    $r = $b->jdi('passkey.php?akce=moznosti-registrace', json: json_encode(['csrf' => $b->csrf]));
    $j = json_decode($r['body'], true);
    eq([200, true, '127.0.0.1'], [$r['code'], $j['ok'] ?? null, $j['publicKey']['rp']['id'] ?? null]);
    $r = $b->jdi('passkey.php?akce=registrovat', json: json_encode(['csrf' => $b->csrf, 'credential' => ['response' => ['clientDataJSON' => 'xx']]]));
    eq(400, $r['code']);
    ok(str_contains($b->jdi('index.php?p=nastaveni')['body'], 'Přidat toto zařízení'));
});

test('Google bez konfigurace jen vrátí hlášku', function () use ($b) {
    $r = $b->jdi('google.php');
    eq(303, $r['code']);
});

test('kontrola zdraví s klíčem vrátí verzi', function () use ($s, $b) {
    $s->db()->exec("INSERT OR REPLACE INTO settings (k, v) VALUES ('update_health_key', 'klic-zdravi-123')");
    $r = $b->jdi('health.php?key=klic-zdravi-123');
    eq(200, $r['code']);
    $j = json_decode($r['body'], true);
    eq(true, $j['ok']);
    eq(trim((string)file_get_contents(KOREN . '/apps/twb-galerie/VERSION')), $j['version']);
});

test('ochrana souborů: .htaccess v kořeni i v data/', function () {
    $h = (string)file_get_contents(KOREN . '/apps/twb-galerie/.htaccess');
    ok(str_contains($h, 'config\.php') && str_contains($h, '/modules/'));
    ok(str_contains((string)file_get_contents(KOREN . '/apps/twb-galerie/data/.htaccess'), 'Require all denied'));
});
