<?php
// Texty slidů: proměnné, formát data, formát čísla, filtry.
require dirname(__DIR__) . '/lib.php';

$d = new DateTimeImmutable('2026-10-09 14:30'); // pátek
$txt = fn(string $s, ?DateTimeImmutable $t = null) => resolve_text($s, $t ?? $d)[0];

test('formát data – pojmenované', function () use ($d) {
    $cases = ['' => '9. 10. 2026', 'kratke' => '9. 10.', 'bezmezer' => '9.10.2026', 'nuly' => '09.10.2026', 'den' => 'pátek 9. 10.',
              'den-plne' => 'pátek 9. 10. 2026', 'slovem' => '9. října', 'dlouhe' => '9. října 2026', 'den-slovem' => 'pátek 9. října',
              'den-dlouhe' => 'pátek 9. října 2026', 'dentydne' => 'pátek', 'mesic-rok' => 'říjen 2026', 'Krátké' => '9. 10.'];
    foreach ($cases as $f => $exp) eq($exp, fmt_date($d, $f), "formát „{$f}“");
});

test('formát data – vlastní zástupné znaky', function () use ($d) {
    eq('09/10/26', fmt_date($d, 'DD/MM/RR'));
    eq('9. října 2026 (pá)', fmt_date($d, 'D. MĚSÍCE RRRR (DN)'));
    eq('PÁTEK', mb_strtoupper(fmt_date($d, 'DEN')));
    eq('říjen', fmt_date($d, 'MĚSÍC'));
    eq('9. 10. 2026', fmt_date($d, 'nesmysl'), 'neznámý název → výchozí');
});

test('rozpoznání data (i s časem na konci)', function () {
    foreach (['2026-11-26', '2026-11-26 20:00', '2026-11-26T20:00:00+01:00', '26. 11. 2026', '26.11.2026 20:00', '26. 11. 2026, 20:00'] as $s) {
        eq('2026-11-26', parse_any_date($s)?->format('Y-m-d'), $s);
    }
    eq(null, parse_any_date('31. 2. 2026'), 'neplatné datum');
    eq(null, parse_any_date('zítra'));
});

test('proměnné data', function () use ($txt, $d) {
    eq('9. 10. 2026', $txt('{datum}'));
    eq('10. 10. 2026', $txt('{datum:+1}'));
    eq('Pátek / pátek', $txt('{Den} / {den}'));
    eq('říjen 2026', $txt('{mesic} {rok}'));
    eq('41', $txt('{týden}'));
    eq('14', $txt('{hodina}'));
    eq('5', $txt('{cislodne}'));
    eq('9. října', $txt('{datum|datum:slovem}'));
});

test('odpočet a počet dní', function () use ($txt) {
    eq('dnes', $txt('{odpocet:9. 10. 2026}'));
    eq('zítra', $txt('{odpocet:2026-10-10}'));
    eq('za 2 dny', $txt('{odpocet:2026-10-11}'));
    eq('za 5 dní', $txt('{odpocet:2026-10-14}'));
    eq('proběhlo', $txt('{odpocet:2026-10-01}'));
    eq('5', $txt('{dnu:2026-10-14}'));
    eq('0', $txt('{dnu:2026-10-01}'));
});

test('filtry čísel', function () {
    $f = fn(string $v, string ...$fl) => apply_filters($v, $fl);
    eq('125 tis.', $f('125000', 'tis'));
    eq('125,5 mil.', $f('125 500 000', 'mil'));
    eq('125 mil.', $f('125000000', 'mil'));
    eq('1,25 mld.', $f('1250000000', 'mld'));
    eq('1 milion', $f('1000000', 'milionu'));
    eq('3 miliony', $f('3000000', 'milionu'));
    eq('5 milionů', $f('5000000', 'milionu'));
    eq('1,5 milionu', $f('1500000', 'milionu'));
    eq('2 miliardy', $f('2000000000', 'miliard'));
    eq('1 234,5', $f('1234.5', 'cislo'));
    eq('1 234,50 Kč', $f('1234.5', 'kc:0,00'));
    eq('1 235 Kč', $f('1234.5', 'kc:0'));
    eq('1234567', $f('1 234 567', 'bezmezer'));
    eq('42 %', $f('42', 'pripona: %'));
    eq('text', $f('text', 'kc'), 'nečíslo se nemění');
});

test('filtry textu', function () {
    $f = fn(string $v, string ...$fl) => apply_filters($v, $fl);
    eq('ŽLUŤOUČKÝ', $f('žluťoučký', 'velka'));
    eq('žluť', $f('ŽLUŤ', 'mala'));
    eq('Čtvrtek', $f('čtvrtek', 'prvni'));
    eq('—', $f('  ', 'vychozi:—'));
    eq('1-2-3', $f(' 1  2 3 ', 'cisla:-'));
});

test('neznámá proměnná zůstane a nahlásí se, |vychozi ji nahradí', function () use ($d) {
    [$out, $unknown] = resolve_text('Cena {neexistuje}', $d);
    eq('Cena {neexistuje}', $out);
    eq(['{neexistuje}'], $unknown);
    eq('Cena ?', resolve_text('Cena {neexistuje|vychozi:?}', $d)[0]);
});

test('hodnoty ze sekce Data', function () use ($txt) {
    $now = date('Y-m-d H:i:s');
    q('INSERT INTO data_rows (grp, param, value, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', ['Ceník', 'Káva', '45', $now, $now]);
    data_rows(true);
    eq('45 Kč', $txt('{Ceník:Káva|kc:0}'));
});

test('slide se vykreslí do JPG 1920×1080 s textem', function () use ($d) {
    $design = sanitize_design(['elements' => [['type' => 'text', 'text' => 'Dnes je {den}', 'x' => 100, 'y' => 100, 'w' => 1000, 'h' => 200, 'size' => 80]]]);
    $jpg = render_slide($design, $d);
    $info = getimagesizefromstring($jpg);
    eq([1920, 1080, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]]);
    contains('Dnes je pátek', json_encode(design_texts($design, $d), JSON_UNESCAPED_UNICODE));
});
