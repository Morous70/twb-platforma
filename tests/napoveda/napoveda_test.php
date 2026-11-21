<?php
// Nápověda: obsah, stránky, odkazy, hledání, kontrola (přeneseno z testů Plánovače médií 1.7.0).
require dirname(__DIR__) . '/lib.php';

use TWB\Napoveda\Napoveda;

/** Malá nápověda ve dočasné složce. */
function malaNapoveda(array $zmeny = []): array
{
    $d = sys_get_temp_dir() . '/twb-nap-' . bin2hex(random_bytes(3));
    mkdir("$d/zaciname", 0775, true);
    mkdir("$d/jak-na", 0775, true);
    file_put_contents("$d/_obsah.json", json_encode([
        'sekce' => [
            ['id' => 'zaciname', 'titulek' => 'Začínáme', 'stranky' => ['uvod', 'druha']],
            ['id' => 'jak-na', 'titulek' => 'Jak na to', 'skupiny' => [['titulek' => 'Soubory', 'stranky' => ['nahrat']]]],
        ],
        'kontext' => ['galerie' => 'nahrat'],
    ], JSON_UNESCAPED_UNICODE));
    file_put_contents("$d/zaciname/uvod.md", "---\ntitulek: Úvod\nsekce: zaciname\nadmin: ne\n---\n# Úvod\n\nViz [nahrání](nahrat) a [web](https://example.com).\n");
    file_put_contents("$d/zaciname/druha.md", "---\ntitulek: Druhá\nsekce: zaciname\nadmin: ano\nsouvisi: uvod, nahrat\nklicova: heslo\n---\n# Druhá\n\n**Tučně** [SNÍMEK: obrazovka]\n");
    file_put_contents("$d/jak-na/nahrat.md", "---\ntitulek: Jak nahrát\nsekce: jak-na\nadmin: ne\nkontext: galerie\n---\n# Jak nahrát\n\nText.\n");
    foreach ($zmeny as $soubor => $obsah) file_put_contents("$d/$soubor", $obsah);
    return [new Napoveda($d), $d];
}

test('obsah: sekce, skupiny, pořadí a příznak „pro správce“', function () {
    [$n] = malaNapoveda();
    eq(['uvod', 'druha', 'nahrat'], $n->poradi());
    $o = $n->obsah();
    eq(['zaciname', 'jak-na'], array_column($o['sections'], 'id'));
    eq('Soubory', $o['sections'][1]['groups'][0]['title']);
    eq(true, $o['pages']['druha']['admin']);
    eq(false, $o['pages']['uvod']['admin']);
});

test('stránka: text bez hlavičky, související a sousední stránky', function () {
    [$n] = malaNapoveda();
    $p = $n->stranka('druha');
    eq('Druhá', $p['title']);
    ok(!str_contains($p['md'], 'titulek:'), 'hlavička se nesmí dostat do textu');
    eq(['uvod', 'nahrat'], array_column($p['related'], 'id'));
    eq('uvod', $p['prev']['id']);
    eq('nahrat', $p['next']['id']);
    eq(null, $n->stranka('uvod')['prev']);
    eq(null, $n->stranka('nahrat')['next']);
});

test('stránka: neznámé nebo podezřelé ID vrátí null', function () {
    [$n] = malaNapoveda();
    foreach (['', 'neexistuje', '../_obsah', '../../config', 'zaciname/uvod', 'uvod.md', "uvod\0"] as $id) eq(null, $n->stranka($id), 'id ' . show($id));
});

test('balíček: obsah, kontexty a index pro hledání bez formátování', function () {
    [$n] = malaNapoveda();
    $b = $n->balicek();
    eq(2, count($b['sections']));
    eq(['galerie' => 'nahrat'], $b['context']);
    eq(3, count($b['index']));
    foreach ($b['index'] as $r) ok($r['text'] !== '' && !str_contains($r['text'], '**') && !str_contains($r['text'], ']('), "čistý text: {$r['id']}");
    eq('heslo', $b['index'][1]['keys']);
});

test('čistý text pro hledání neobsahuje značky Markdownu', function () {
    $t = Napoveda::cistyText("# Nadpis\n\n**Tučně** a [odkaz](jak-nahrat-soubor) a `kód`.\n\n| A | B |\n|---|---|\n| 1 | 2 |\n\n[SNÍMEK: něco]\n");
    ok(str_contains($t, 'Nadpis Tučně a odkaz a kód'), $t);
    ok(!str_contains($t, 'SNÍMEK') && !str_contains($t, '|') && !str_contains($t, '---'), $t);
});

test('hlavička: parsování a stránka bez hlavičky', function () {
    [$m, $b] = Napoveda::rozdel("---\ntitulek: Ahoj\nadmin: ano\n---\n# Text\n");
    eq(['titulek' => 'Ahoj', 'admin' => 'ano'], $m);
    eq("# Text\n", $b);
    [$m2, $b2] = Napoveda::rozdel("# Jen text\n");
    eq([], $m2);
    eq("# Jen text\n", $b2);
});

test('kontrola: správný obsah nemá chyby, počítá značky [SNÍMEK]', function () {
    [$n] = malaNapoveda();
    [$chyby, $overit, $snimky] = $n->zkontroluj();
    eq([], $chyby);
    eq([0, 1], [$overit, $snimky]);
});

test('kontrola: najde rozbité odkazy, chybějící hlavičku, soubor mimo obsah a neznámý kontext', function () {
    [$n] = malaNapoveda([
        'zaciname/uvod.md' => "---\ntitulek: Úvod\nsekce: jak-na\nadmin: možná\nkontext: nic\nsouvisi: nikde\n---\nBez nadpisu [rozbito](neexistuje) [OVĚŘIT: x]\n",
        'zaciname/sirotek.md' => "---\ntitulek: S\nsekce: zaciname\nadmin: ne\n---\n# S\n",
    ]);
    [$chyby, $overit] = $n->zkontroluj();
    $text = implode("\n", $chyby);
    foreach (['nesouhlasí se složkou', 'admin musí být ano nebo ne', 'neznámý kontext „nic“', 'neexistující stránku „nikde“',
              'odkaz na neexistující stránku „neexistuje“', 'chybí nadpis', 'sirotek.md není v _obsah.json'] as $c) ok(str_contains($text, $c), "chybí hláška: $c\n$text");
    eq(1, $overit);
});

test('chybějící nebo rozbitý _obsah.json dá prázdnou nápovědu', function () {
    $d = sys_get_temp_dir() . '/twb-nap-prazdna-' . bin2hex(random_bytes(3)); mkdir($d);
    eq([], (new Napoveda($d))->poradi());
    file_put_contents("$d/_obsah.json", '{rozbito');
    eq(['sections' => [], 'pages' => [], 'context' => []], (new Napoveda($d))->obsah());
});

test('skutečná nápověda Plánovače médií projde kontrolou beze změny', function () {
    $d = KOREN . '/apps/planovac-medii/help';
    ok(is_dir($d), 'složka help/ v importu');
    $n = new Napoveda($d);
    [$chyby, $overit] = $n->zkontroluj();
    ok(!$chyby, "chyby obsahu:\n  " . implode("\n  ", array_slice($chyby, 0, 10)));
    ok(count($n->poradi()) >= 60, 'stránek: ' . count($n->poradi()));
    eq(0, $overit, 'nevyřešené [OVĚŘIT]');
    eq(null, $n->stranka('../config'));
});
