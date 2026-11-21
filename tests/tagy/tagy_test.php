<?php
// Tagy: přenesené testy z Plánovače médií (1.17.0) + varianta Social Planneru (tabulka library) a české řazení.
require dirname(__DIR__) . '/lib.php';

use TWB\Jadro\Db;
use TWB\Tagy\Tagy;

function mediaDb(): array
{
    $h = novyHost();
    $h->db->q('CREATE TABLE media (id INTEGER PRIMARY KEY AUTOINCREMENT, original_name TEXT NOT NULL, tags TEXT)');
    return [$h, new Tagy($h->db)];
}
function pridej(Db $db, string $nazev, ?array $tagy = null): int
{
    $db->q('INSERT INTO media (original_name, tags) VALUES (?, ?)', [$nazev, $tagy ? json_encode($tagy, JSON_UNESCAPED_UNICODE) : null]);
    return $db->posledniId();
}
function tagyId(Db $db, int $id): array
{
    $j = json_decode((string)$db->q('SELECT tags FROM media WHERE id = ?', [$id])->fetchColumn(), true);
    return is_array($j) ? $j : [];
}

test('čištění: mezery, čárky, opakování bez ohledu na velikost písmen, nejvýš 30 znaků a 10 tagů', function () {
    [, $t] = mediaDb();
    eq(['Vánoce', 'Akce 2026'], $t->vycistiSeznam(['  Vánoce ', 'vánoce', "Akce\t 2026", '', 'VÁNOCE'], []));
    eq(['Vánoce', 'Loterie'], $t->vycistiSeznam('Vánoce, Loterie; vánoce', []));
    throws(fn() => $t->vycistiSeznam([str_repeat('á', 31)], []), 'delší než 30 znaků');
    eq([str_repeat('á', 30)], $t->vycistiSeznam([str_repeat('á', 30)], []));
    throws(fn() => $t->vycistiSeznam(array_map(fn($i) => "t$i", range(1, 11)), []), 'Médium může mít nejvýš 10 tagů');
    eq(10, count($t->vycistiSeznam(array_map(fn($i) => "t$i", range(1, 10)), [])));
});

test('nový tag se zapíše jako už existující (vánoce → Vánoce); seznam tagů s počty', function () {
    [$h, $t] = mediaDb();
    pridej($h->db, 'a.jpg', ['Vánoce', 'Sportka']);
    pridej($h->db, 'b.jpg', ['Sportka']);
    $c = pridej($h->db, 'c.jpg');
    $t->nastav($c, $t->vycistiSeznam(['vánoce', 'Nový']));
    eq(['Vánoce', 'Nový'], tagyId($h->db, $c));
    $vse = $t->vsechny();
    eq(['Nový' => 1, 'Sportka' => 2, 'Vánoce' => 2], array_combine(array_column($vse, 'name'), array_column($vse, 'count')));
});

test('hromadně: přidat a odebrat u více médií, beze změny se nepočítá, limit 10 u konkrétního média', function () {
    [$h, $t] = mediaDb();
    $a = pridej($h->db, 'a.jpg', ['Vánoce', 'Sportka']);
    $b = pridej($h->db, 'b.jpg', ['Sportka']);
    $c = pridej($h->db, 'c.jpg', ['Vánoce', 'Nový']);
    eq(3, $t->hromadne([$a, $b, $c], ['Akce'], []));
    eq(['Vánoce', 'Sportka', 'Akce'], tagyId($h->db, $a));
    eq(0, $t->hromadne([$a, $b, $c], ['akce'], []), 'už mají');
    eq(2, $t->hromadne([$a, $c], [], ['VÁNOCE']));
    eq(['Sportka', 'Akce'], tagyId($h->db, $a));
    eq(['Nový', 'Akce'], tagyId($h->db, $c));
    $d = pridej($h->db, 'd.jpg', array_map(fn($i) => "x$i", range(1, 10)));
    throws(fn() => $t->hromadne([$d], ['jeden navíc'], []), '„d.jpg“: Médium může mít nejvýš 10 tagů');
    eq(0, $t->hromadne([9999], ['Akce'], []), 'neexistující ID se přeskočí');
});

test('přejmenování: ve všech médiích; na existující tag se sloučí bez zdvojení; smazání ze všech', function () {
    [$h, $t] = mediaDb();
    $a = pridej($h->db, 'a.jpg', ['Vánoce', 'Sportka', 'Akce']);
    $b = pridej($h->db, 'b.jpg', ['Sportka', 'Akce']);
    $c = pridej($h->db, 'c.jpg', ['Nový', 'Akce']);
    eq(1, $t->prejmenuj('nový', 'Novinka'));
    eq(['Novinka', 'Akce'], tagyId($h->db, $c));
    eq(3, $t->prejmenuj('Akce', 'sportka'), 'sloučení');
    eq(['Vánoce', 'Sportka'], tagyId($h->db, $a), 'bez zdvojení, zápis existujícího tagu');
    eq(['Sportka'], tagyId($h->db, $b));
    eq(['Novinka', 'Sportka'], tagyId($h->db, $c));
    throws(fn() => $t->prejmenuj('neexistuje', 'x'), 'nenalezen');
    throws(fn() => $t->prejmenuj('Sportka', '  '), 'Zadejte nový název');
    eq(3, $t->smaz('SPORTKA'));
    eq([['Vánoce'], [], ['Novinka']], [tagyId($h->db, $a), tagyId($h->db, $b), tagyId($h->db, $c)]);
    eq(null, $h->db->q('SELECT tags FROM media WHERE id = ?', [$b])->fetchColumn(), 'prázdné = NULL');
});

test('české řazení: diakritika hned za základním písmenem, nezávisle na locale', function () {
    [$h, $t] = mediaDb();
    pridej($h->db, 'a', ['Čaj', 'Cukr', 'Dort', 'Ždímání', 'Zebra', 'Řeka', 'Rum']);
    eq(['Cukr', 'Čaj', 'Dort', 'Rum', 'Řeka', 'Zebra', 'Ždímání'], array_column($t->vsechny(), 'name'));
});

test('varianta Social Planneru: tabulka library, sloupec name, pojem „Soubor“', function () {
    $h = novyHost();
    $h->db->q('CREATE TABLE library (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, tags TEXT)');
    $h->db->q('INSERT INTO library (name, tags) VALUES (?, ?)', ['foto.jpg', json_encode(array_map(fn($i) => "x$i", range(1, 10)))]);
    $t = new Tagy($h->db, 'library', 'id', 'name', 'tags', 'Soubor');
    eq(['x1', 'x10'], array_slice(array_column($t->vsechny(), 'name'), 0, 2), 'abecedně');
    throws(fn() => $t->hromadne([1], ['navíc'], []), '„foto.jpg“: Soubor může mít nejvýš 10 tagů');
    eq(1, $t->hromadne([1], ['nový'], ['x1', 'x2']));
});

test('neplatný název tabulky nebo sloupce se odmítne (ochrana SQL)', function () {
    [$h] = mediaDb();
    throws(fn() => new Tagy($h->db, 'media; DROP TABLE media'), 'Neplatný název');
    throws(fn() => new Tagy($h->db, 'media', 'id', 'original_name', 'tags) --'), 'Neplatný název');
});
