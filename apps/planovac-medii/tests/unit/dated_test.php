<?php
// Výběr podle data (verze 1.4.0) a oprava roku 0002 (1.4.1, migrace v10).
require dirname(__DIR__) . '/lib.php';

$a = make_media('a.jpg');
$def = make_scenario('Výchozí', [$a], ['default' => 1]);
$pool = make_media('Extra renta', 'dated');
$e1 = make_media('er-1.jpg'); $e2 = make_media('er-2.jpg'); $e3 = make_media('er-3.jpg');
$day = fn(int $m, string $d) => q('INSERT INTO dated_items (pool_id, media_id, day) VALUES (?, ?, ?)', [$pool, $m, $d]);
$day($e1, '2026-10-17'); $day($e2, '2026-10-24'); $day($e3, '2026-10-24'); $day($e1, '2026-10-31');

test('ve výchozím scénáři: položka jen ve svůj den', function () use ($def, $pool) {
    q('INSERT INTO scenario_media (scenario_id, media_id) VALUES (?, ?)', [$def, $pool]);
    eq(['a.jpg', 'er-1.jpg'], playing('2026-10-17 08:00'));
    eq(['a.jpg'], playing('2026-10-18 08:00'), 'den bez položky');
    eq(['a.jpg', 'er-1.jpg'], playing('2026-10-31 23:59'), 'jedna položka ve více dnech');
});

test('více položek na stejný den', function () {
    eq(['a.jpg', 'er-2.jpg', 'er-3.jpg'], playing('2026-10-24 12:00'));
});

test('výsledek uvádí, ze kterého výběru položka je', function () use ($pool) {
    $r = resolve_at(new DateTimeImmutable('2026-10-24 12:00'));
    $row = array_values(array_filter($r['media'], fn($x) => $x['media']['original_name'] === 'er-2.jpg'))[0];
    eq('Extra renta', $row['pool']);
    eq($pool, $row['pool_id']);
});

test('scénář jen s výběrem podle data mimo jeho dny neběží', function () use ($pool) {
    $s = make_scenario('Renta', [$pool], ['exclusive' => 1]);
    $r = resolve_at(new DateTimeImmutable('2026-10-18 10:00'));
    eq(['Renta'], $r['idle']);
    eq('default', $r['mode']);
    $r = resolve_at(new DateTimeImmutable('2026-10-24 10:00'));
    eq('exclusive', $r['mode']);
    eq(['er-2.jpg', 'er-3.jpg'], playing('2026-10-24 10:00'));
    q('DELETE FROM scenarios WHERE id = ?', [$s]);
});

test('položka bez skupiny přebírá skupinu výběru', function () use ($pool, $e2) {
    q("UPDATE media SET grp = 'renta' WHERE id = ?", [$pool]);
    $x = make_media('renta-jinak.jpg', 'image', ['grp' => 'renta']);
    $s = make_scenario('Krátká akce', [$x], ['from' => '2026-10-24', 'to' => '2026-10-24', 'priority' => 3]);
    $names = playing('2026-10-24 10:00');
    ok(in_array('renta-jinak.jpg', $names, true) && !in_array('er-2.jpg', $names, true), show($names));
    q("UPDATE media SET grp = NULL WHERE id = ?", [$pool]);
    q('DELETE FROM scenarios WHERE id = ?', [$s]);
});

test('smazání média odstraní i jeho dny', function () use ($e3) {
    q('DELETE FROM media WHERE id = ?', [$e3]);
    eq(0, (int)q('SELECT COUNT(*) FROM dated_items WHERE media_id = ?', [$e3])->fetchColumn());
    eq(['a.jpg', 'er-2.jpg'], playing('2026-10-24 12:00'));
});

test('migrace v10: rok 0002 → aktuální rok, duplicity pryč', function () use ($pool, $e1, $e2) {
    q("INSERT INTO dated_items (pool_id, media_id, day) VALUES (?, ?, '0002-10-22'), (?, ?, '0002-10-31')", [$pool, $e2, $pool, $e1]);
    set_setting('schema_version', '9');
    migrate();
    $y = date('Y');
    $days = q('SELECT media_id, day FROM dated_items WHERE pool_id = ? ORDER BY day, media_id', [$pool])->fetchAll();
    $txt = array_map(fn($r) => (int)$r['media_id'] . '@' . substr((string)$r['day'], 0, 10), $days);
    ok(in_array("$e2@$y-10-22", $txt, true), 'opravený den: ' . show($txt));
    eq(0, (int)q("SELECT COUNT(*) FROM dated_items WHERE day < '2000-01-01'")->fetchColumn(), 'žádný rok pod 2000');
    eq(count($txt), count(array_unique($txt)), 'bez duplicit');
    eq((string)SCHEMA_VERSION, setting('schema_version'));
});
