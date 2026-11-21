<?php
// Vzdálené zdroje: CSV (oddělovač, kódování, záhlaví), adresa tabulky Google, hledání hodnoty podle cesty.
require dirname(__DIR__) . '/lib.php';

function add_source(string $name, $json, string $format = 'json'): void {
    q('INSERT INTO sources (name, url, interval_min, payload, format, ok_at, created_at) VALUES (?, ?, 15, ?, ?, ?, ?)',
      [$name, 'https://example.invalid/' . $name, json_encode($json, JSON_UNESCAPED_UNICODE), $format, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
    sources_all(true);
}

test('adresa tabulky Google → export CSV (s listem)', function () {
    eq('https://docs.google.com/spreadsheets/d/AbC_12-x/export?format=csv&gid=0', csv_url('https://docs.google.com/spreadsheets/d/AbC_12-x/edit?usp=sharing'));
    eq('https://docs.google.com/spreadsheets/d/AbC/export?format=csv&gid=123', csv_url('https://docs.google.com/spreadsheets/d/AbC/edit#gid=123'));
    $pub = 'https://docs.google.com/spreadsheets/d/AbC/pub?output=csv';
    eq($pub, csv_url($pub), 'publikované CSV beze změny');
    eq('https://example.com/a.csv', csv_url('https://example.com/a.csv'));
});

test('CSV se středníkem a záhlavím', function () {
    $rows = csv_to_rows("Položka;Cena;Poznámka\nKáva;45;\"s mlékem; velká\"\nČaj;30;\n\n", true);
    eq(2, count($rows));
    eq(['_row' => 'Káva', 'Položka' => 'Káva', 'Cena' => '45', 'Poznámka' => 's mlékem; velká'], $rows[0]);
});

test('CSV s čárkou, BOM a Windows-1250 (Excel)', function () {
    eq('Žluťoučký', csv_to_rows("\xEF\xBB\xBFa,b\nŽluťoučký,1\n", true)[0]['a']);
    $cp = iconv('UTF-8', 'Windows-1250', "Název;Cena\nŽluťoučký kůň;12\n");
    eq('Žluťoučký kůň', csv_to_rows($cp, true)[0]['Název']);
});

test('CSV bez záhlaví: sloupce 1, 2, …; prázdná a duplicitní záhlaví', function () {
    $rows = csv_to_rows("x;1\ny;2\n", false);
    eq(['_row' => 'x', '1' => 'x', '2' => '1'], $rows[0]);
    $rows = csv_to_rows("A;;A;b.c\n1;2;3;4\n", true);
    eq(['_row', 'A', 'Sloupec 2', 'A 2', 'b c'], array_keys($rows[0]));
});

test('CSV chyby: prázdné, webová stránka, jen záhlaví', function () {
    throws(fn() => csv_to_rows("  \n", true), 'prázdný');
    throws(fn() => csv_to_rows("<!DOCTYPE html><html>", true), 'webovou stránku');
    throws(fn() => csv_to_rows("a;b\n", true), 'jen záhlaví');
});

test('hodnota z CSV podle prvního sloupce i čísla řádku', function () {
    add_source('Ceník', csv_to_rows("Položka;Cena\nKáva;45\nČaj;30\n", true), 'csv');
    eq('45', source_value('Ceník', 'Káva.Cena'));
    eq('45', source_value('Ceník', 'kava.cena'), 'bez diakritiky a velikosti písmen');
    eq('30', source_value('Ceník', '2.Cena'));
    eq(null, source_value('Ceník', 'Pivo.Cena'));
    $d = new DateTimeImmutable('2026-10-09');
    eq('Káva za 45 Kč', resolve_text('Káva za {Ceník:Káva.Cena|kc:0}', $d)[0]);
});

test('CSV s číselnými záhlavími (oprava 1.2.0)', function () {
    add_source('Roky', csv_to_rows("Hra;2025;2026\nSportka;10;20\n", true), 'csv');
    eq('20', source_value('Roky', 'Sportka.2026'));
});

test('JSON: hledání přes identifikátor v seznamu a přeskočení úrovní', function () {
    add_source('Allwyn2', ['success' => true, 'data' => [
        ['game' => 'sportka', 'label' => 'Sportka', 'draw' => ['datum' => '2026-10-08', 'data' => ['jackpot' => 125000000, 'cisla' => [1, 7, 22]]]],
        ['game' => 'eurojackpot', 'label' => 'Eurojackpot', 'draw' => ['data' => ['jackpot' => 2.5e9]]],
    ]]);
    eq('125000000', source_value('Allwyn2', 'sportka.jackpot'));
    eq('2500000000', source_value('Allwyn2', 'Eurojackpot.draw.data.jackpot'));
    eq('1 7 22', source_value('Allwyn2', 'sportka.cisla'));
    eq('125 mil.', resolve_text('{Allwyn2:sportka.jackpot|mil}', new DateTimeImmutable())[0]);
    eq(null, source_value('Allwyn2', 'sportka.draw'), 'objekt není hodnota');
});
