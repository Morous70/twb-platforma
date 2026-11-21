<?php
// Nápověda (verze 1.7.0): obsah, stránky, odkazy, hledání, API.
require dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__, 2) . '/lib/help.php';

test('obsah: každá stránka z _obsah.json existuje a každý soubor je v obsahu', function () {
    [$errors] = help_check();
    ok(!$errors, "chyby obsahu:\n  " . implode("\n  ", array_slice($errors, 0, 20)));
    ok(count(help_order()) >= 60, 'stránek: ' . count(help_order()));
});

test('obsah: žádná nevyřešená značka [OVĚŘIT]', function () {
    [, $verify, $shots] = help_check();
    eq(0, $verify, 'nevyřešených [OVĚŘIT]');
    echo "      (značek [SNÍMEK] k doplnění: $shots)\n";
});

test('stránka: obsah, související a sousední stránky', function () {
    $p = help_page('jak-nahrat-soubor');
    ok($p !== null);
    eq('Jak nahrát obrázek, video nebo PDF', $p['title']);
    contains('# Jak nahrát obrázek, video nebo PDF', $p['md']);
    ok(!str_contains($p['md'], 'titulek:'), 'hlavička se nesmí dostat do textu');
    ok(count($p['related']) >= 2 && isset($p['related'][0]['title']), 'související stránky');
    eq('co-aplikace-umi', help_page('co-aplikace-umi')['id']);
    eq(null, help_page('co-aplikace-umi')['prev'], 'první stránka nemá předchozí');
    eq('prihlaseni-a-orientace', help_page('co-aplikace-umi')['next']['id']);
    eq(true, help_page('jak-zalohovat-a-obnovit')['admin']);
    eq(false, help_page('jak-nahrat-soubor')['admin']);
});

test('stránka: neznámé nebo podezřelé ID vrátí null', function () {
    foreach (['', 'neexistuje', '../config', '../../config', 'zaciname/prvni-obrazek', 'prvni-obrazek.md', "prvni-obrazek\0"] as $id) {
        eq(null, help_page($id), "id " . show($id));
    }
});

test('balíček: obsah, kontexty a index pro hledání', function () {
    $b = help_bundle();
    eq(8, count($b['sections']));
    eq(count(help_order()), count($b['index']));
    eq('prehled', $b['context']['prehled']);
    foreach ($b['index'] as $r) ok($r['text'] !== '' && !str_contains($r['text'], '**') && !str_contains($r['text'], '](') , "text bez formátování: {$r['id']}");
});

test('čistý text pro hledání neobsahuje značky Markdownu', function () {
    $t = help_plain("# Nadpis\n\n**Tučně** a [odkaz](jak-nahrat-soubor) a `kód`.\n\n| A | B |\n|---|---|\n| 1 | 2 |\n\n[SNÍMEK: něco]\n");
    contains('Nadpis Tučně a odkaz a kód', $t);
    ok(!str_contains($t, '---'), $t);
    ok(!str_contains($t, 'SNÍMEK') && !str_contains($t, '|') && !str_contains($t, '---'), $t);
});

test('hlavička: parsování a stránka bez hlavičky', function () {
    [$m, $b] = help_split("---\ntitulek: Ahoj\nadmin: ano\n---\n# Text\n");
    eq(['titulek' => 'Ahoj', 'admin' => 'ano'], $m);
    eq("# Text\n", $b);
    [$m2, $b2] = help_split("# Jen text\n");
    eq([], $m2);
    eq("# Jen text\n", $b2);
});

test('kontexty vedou na existující stránky', function () {
    foreach (help_toc()['context'] as $k => $id) ok(help_page($id) !== null, "kontext $k → $id");
});

test('HTTP: nápověda jen pro přihlášené, neznámá stránka 404, složka help/ je chráněná', function () {
    $B = getenv('BASE_URL');
    $jar = TMP_DIR . '/help-cookies.txt'; @unlink($jar);
    $get = function (string $url) use ($jar) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
        $body = (string)curl_exec($ch);
        return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body, json_decode($body, true)];
    };
    eq(401, $get("$B/api.php?a=help")[0], 'bez přihlášení');
    set_setting('password_hash', password_hash('test-heslo-123', PASSWORD_DEFAULT));
    $ch = curl_init("$B/");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'password=test-heslo-123', CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    curl_exec($ch);
    unset($ch);   // uloží cookies do souboru
    [$code, , $j] = $get("$B/api.php?a=help");
    eq(200, $code);
    ok(count($j['sections']) === 8 && count($j['index']) >= 60, 'balíček');
    [$code, , $j] = $get("$B/api.php?a=help&page=prvni-obrazek");
    eq(200, $code);
    eq('prvni-obrazek', $j['id']);
    eq(404, $get("$B/api.php?a=help&page=../config")[0]);
    eq(404, $get("$B/api.php?a=help&page=neexistuje")[0]);
});
