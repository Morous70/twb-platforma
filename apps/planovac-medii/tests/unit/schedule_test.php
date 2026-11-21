<?php
// Plánovač: který scénář a která média běží v daném okamžiku.
require dirname(__DIR__) . '/lib.php';

$a = make_media('a.jpg'); $b = make_media('b.jpg'); $c = make_media('c.jpg');
$def = make_scenario('Výchozí', [$a], ['default' => 1]);

test('bez plánovaného scénáře běží výchozí', function () {
    $r = resolve_at(new DateTimeImmutable('2026-10-19 10:00'));
    eq('default', $r['mode']);
    eq(['a.jpg'], playing('2026-10-19 10:00'));
});

$plan = make_scenario('Říjen', [$b], ['from' => '2026-10-01', 'to' => '2026-10-31']);
test('plánovaný scénář nahradí výchozí jen ve svém období', function () {
    eq(['b.jpg'], playing('2026-10-19 10:00'));
    eq(['a.jpg'], playing('2026-11-01 10:00'));
    eq(['b.jpg'], playing('2026-10-31 23:59'), 'poslední den platí celý');
});

test('dny v týdnu a hodiny', function () use ($plan) {
    q("UPDATE scenarios SET weekdays = '12345', hours = ? WHERE id = ?", [str_repeat('0', 8) . str_repeat('1', 10) . str_repeat('0', 6), $plan]);
    eq(['b.jpg'], playing('2026-10-19 08:00'), 'pondělí 8:00');
    eq(['a.jpg'], playing('2026-10-19 07:59'), 'před 8. hodinou');
    eq(['a.jpg'], playing('2026-10-19 18:00'), 'od 18 h už ne');
    eq(['a.jpg'], playing('2026-10-18 10:00'), 'neděle');
    q("UPDATE scenarios SET weekdays = '1234567', hours = ? WHERE id = ?", [str_repeat('1', 24), $plan]);
});

test('výjimka (vynechaný den) a pozastavení', function () use ($plan) {
    q('UPDATE scenarios SET exceptions = ? WHERE id = ?', [json_encode(['2026-10-20']), $plan]);
    eq(['a.jpg'], playing('2026-10-20 10:00'));
    q('UPDATE scenarios SET exceptions = NULL, paused = 1 WHERE id = ?', [$plan]);
    eq(['a.jpg'], playing('2026-10-19 10:00'));
    q('UPDATE scenarios SET paused = 0 WHERE id = ?', [$plan]);
});

test('více aktivních scénářů se sčítá, výhradní ostatní vypne', function () use ($c) {
    $x = make_scenario('Akce', [$c], ['from' => '2026-10-19', 'to' => '2026-10-19']);
    eq(['b.jpg', 'c.jpg'], playing('2026-10-19 10:00'));
    q('UPDATE scenarios SET exclusive = 1 WHERE id = ?', [$x]);
    $r = resolve_at(new DateTimeImmutable('2026-10-19 10:00'));
    eq('exclusive', $r['mode']);
    eq(['Říjen'], $r['ignored']);
    eq(['c.jpg'], playing('2026-10-19 10:00'));
    q('DELETE FROM scenarios WHERE id = ?', [$x]);
});

test('skupina: vyhraje vyšší priorita, pak kratší platnost', function () use ($plan) {
    $g1 = make_media('cena-1.jpg', 'image', ['grp' => 'cena']);
    $g2 = make_media('cena-2.jpg', 'image', ['grp' => 'cena']);
    q('INSERT INTO scenario_media (scenario_id, media_id) VALUES (?, ?)', [$plan, $g1]);
    $short = make_scenario('Týden', [$g2], ['from' => '2026-10-19', 'to' => '2026-10-25']);
    $r = resolve_at(new DateTimeImmutable('2026-10-19 10:00'));
    $names = array_values(array_map(fn($x) => $x['media']['original_name'], $r['media']));
    ok(in_array('cena-2.jpg', $names, true) && !in_array('cena-1.jpg', $names, true), 'kratší platnost vyhrává: ' . show($names));
    eq('cena', $r['suppressed'][0]['group'] ?? null);
    q('UPDATE scenarios SET priority = 3 WHERE id = ?', [$plan]);
    ok(in_array('cena-1.jpg', playing('2026-10-19 10:00'), true), 'vyšší priorita vyhrává');
    q('UPDATE scenarios SET priority = 2 WHERE id = ?', [$plan]);
    q('DELETE FROM scenarios WHERE id = ?', [$short]);
    q('DELETE FROM scenario_media WHERE media_id = ?', [$g1]);
});

test('pevný scénář běží všude, v konkrétním scénáři jde vypnout', function () use ($plan, $def) {
    $logo = make_media('logo.png');
    $fix = make_scenario('Logo', [$logo], ['fixed' => 1]);
    eq(['b.jpg', 'logo.png'], playing('2026-10-19 10:00'));
    eq(['a.jpg', 'logo.png'], playing('2026-11-05 10:00'), 'i k výchozímu');
    q('INSERT INTO scenario_fixed_off (scenario_id, fixed_id) VALUES (?, ?)', [$plan, $fix]);
    $r = resolve_at(new DateTimeImmutable('2026-10-19 10:00'));
    eq(['Logo'], $r['fixed_off']);
    eq(['b.jpg'], playing('2026-10-19 10:00'));
    eq(['a.jpg', 'logo.png'], playing('2026-11-05 10:00'), 've výchozím zůstává');
    q('DELETE FROM scenarios WHERE id = ?', [$fix]);
});

test('vložená sada (embed_only) a vložený scénář s vlastními pravidly', function () use ($plan) {
    $s = make_media('sada.jpg');
    $set = make_scenario('Sada', [$s], ['embed' => 1]);
    q('INSERT INTO scenario_includes (scenario_id, child_id, follow_rules) VALUES (?, ?, 0)', [$plan, $set]);
    eq(['b.jpg', 'sada.jpg'], playing('2026-10-19 10:00'));
    eq(['a.jpg'], playing('2026-11-05 10:00'), 'sada sama neběží');
    $w = make_media('vikend.jpg');
    $we = make_scenario('Víkend', [$w], ['weekdays' => '67']);
    q('INSERT INTO scenario_includes (scenario_id, child_id, follow_rules) VALUES (?, ?, 1)', [$plan, $we]);
    q('UPDATE scenarios SET paused = 1 WHERE id = ?', [$we]);
    eq(['b.jpg', 'sada.jpg'], playing('2026-10-24 10:00'), 'pozastavený vložený scénář se vynechá');
    q('UPDATE scenarios SET paused = 0 WHERE id = ?', [$we]);
    ok(!in_array('vikend.jpg', playing('2026-10-19 10:00'), true), 'v pondělí vložený víkend neplatí');
    q('DELETE FROM scenarios WHERE id IN (?, ?)', [$set, $we]);
});

test('cyklus vnořených scénářů nezacyklí plánovač', function () use ($plan) {
    $x = make_scenario('X', [], ['embed' => 1]);
    q('INSERT INTO scenario_includes (scenario_id, child_id, follow_rules) VALUES (?, ?, 0), (?, ?, 0)', [$plan, $x, $x, $plan]);
    eq(['b.jpg'], playing('2026-10-19 10:00'));
    q('DELETE FROM scenarios WHERE id = ?', [$x]);
});

test('scénář bez obsahu se bere jako neběžící (nastoupí výchozí)', function () use ($plan) {
    $empty = make_scenario('Prázdný', [], ['exclusive' => 1, 'from' => '2026-10-19', 'to' => '2026-10-19']);
    q('UPDATE scenarios SET paused = 1 WHERE id = ?', [$plan]);
    $r = resolve_at(new DateTimeImmutable('2026-10-19 10:00'));
    eq(['Prázdný'], $r['idle']);
    eq('default', $r['mode']);
    eq(['a.jpg'], playing('2026-10-19 10:00'));
    q('UPDATE scenarios SET paused = 0 WHERE id = ?', [$plan]);
    q('DELETE FROM scenarios WHERE id = ?', [$empty]);
});

test('výchozí pozastaven a nic neběží → prázdno', function () use ($def) {
    q('UPDATE scenarios SET paused = 1 WHERE id = ?', [$def]);
    $r = resolve_at(new DateTimeImmutable('2026-11-05 10:00'));
    eq('empty', $r['mode']);
    eq([], $r['media']);
    q('UPDATE scenarios SET paused = 0 WHERE id = ?', [$def]);
});

test('náhodný výběr: jedno médium, za hodinu jiné, bez opakování v balíčku', function () use ($def) {
    $pool = make_media('Náhodně', 'random');
    $ids = [];
    foreach (['r1.jpg', 'r2.jpg', 'r3.jpg'] as $n) { $ids[] = $id = make_media($n); q('INSERT INTO pool_members (pool_id, media_id) VALUES (?, ?)', [$pool, $id]); }
    q('DELETE FROM scenario_media WHERE scenario_id = ?', [$def]);
    q('INSERT INTO scenario_media (scenario_id, media_id) VALUES (?, ?)', [$def, $pool]);
    $seen = [];
    foreach (['10', '11', '12'] as $h) {
        $t = new DateTimeImmutable("2026-11-05 $h:00");
        $r = expand_pools(resolve_at($t), $t, true);
        $names = array_values(array_map(fn($x) => $x['media']['original_name'], $r['media']));
        eq(1, count($names), "v $h h právě jedno médium");
        $t2 = new DateTimeImmutable("2026-11-05 $h:40");
        $again = array_values(array_map(fn($x) => $x['media']['original_name'], expand_pools(resolve_at($t2), $t2, true)['media']));
        eq($names, $again, 'v rámci hodiny stejné');
        $seen[] = $names[0];
    }
    eq(3, count(array_unique($seen)), 'za tři hodiny tři různá: ' . show($seen));
    q('DELETE FROM scenario_media WHERE scenario_id = ?', [$def]);
    q('INSERT INTO scenario_media (scenario_id, media_id) VALUES (?, ?)', [$def, (int)q("SELECT id FROM media WHERE original_name = 'a.jpg'")->fetchColumn()]);
});

test('cílové názvy souborů: shodné názvy dostanou (2)', function () {
    $m1 = ['id' => 1, 'original_name' => 'cena.jpg', 'md5' => 'x', 'mime' => 'image/jpeg', 'filename' => 'f1'];
    $m2 = ['id' => 2, 'original_name' => 'Cena.jpg', 'md5' => 'y', 'mime' => 'image/jpeg', 'filename' => 'f2'];
    $t = target_files(['media' => [1 => ['media' => $m1], 2 => ['media' => $m2]]]);
    eq(['cena.jpg', 'Cena (2).jpg'], array_column($t, 'name'));
});
