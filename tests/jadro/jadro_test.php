<?php
require dirname(__DIR__) . '/lib.php';

use TWB\Jadro\{Db, Nastaveni, Registr, Migrace, ModulMigrace, Loader};

test('zavaděč: třída modulu se najde podle jmenného prostoru', function () {
    ok(class_exists(\TWB\Tagy\Tagy::class), 'Tagy');
    ok(class_exists(\TWB\Pisma\Pisma::class), 'Pisma');
    ok(!class_exists('TWB\\Neexistuje\\Nic'), 'neexistující modul');
    ok(!class_exists('TWB\\..\\x\\Y'), 'neplatný název');
});

test('Db: dotaz s parametry, poslední ID, transakce vrací změny zpět', function () {
    $db = Db::pripoj('sqlite::memory:');
    ok($db->jeSqlite());
    $db->q('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, a TEXT)');
    $db->q('INSERT INTO t (a) VALUES (?)', ['x']);
    eq(1, $db->posledniId());
    throws(fn() => $db->transakce(function (Db $d) { $d->q('INSERT INTO t (a) VALUES (?)', ['y']); throw new RuntimeException('selhání'); }), 'selhání');
    eq(1, (int)$db->q('SELECT COUNT(*) FROM t')->fetchColumn(), 'vráceno zpět');
    eq(5, $db->transakce(fn() => 5));
});

test('Db::identifikator: povolí jen bezpečné názvy', function () {
    eq('`media`', Db::identifikator('media'));
    throws(fn() => Db::identifikator('media; DROP TABLE x'), 'Neplatný název');
    throws(fn() => Db::identifikator('1abc'), 'Neplatný název');
    throws(fn() => Db::identifikator(''), 'Neplatný název');
});

test('Nastavení: výchozí hodnota, uložení, přepsání, null, vlastní tabulka a sloupce (meta)', function () {
    $h = novyHost();
    eq('vychozi', $h->nastaveni->dej('a', 'vychozi'));
    $h->nastaveni->uloz('a', 5);
    eq('5', $h->nastaveni->dej('a'));
    $h->nastaveni->uloz('a', 'jiné');
    eq('jiné', $h->nastaveni->dej('a'));
    $h->nastaveni->uloz('a', null);
    eq(null, $h->nastaveni->dej('a', 'x'), 'null je platná hodnota');
    $h->db->q('CREATE TABLE meta (`key` TEXT PRIMARY KEY, `value` TEXT NOT NULL)');
    $n = new Nastaveni($h->db, 'meta', 'key', 'value');
    $n->uloz('custom_fonts', '{}');
    eq('{}', $n->dej('custom_fonts'));
    throws(fn() => new Nastaveni($h->db, 'meta; x'), 'Neplatný název');
});

test('Registr: akce (duplicita, neznámá), haky a slučování výsledků', function () {
    $r = new Registr();
    $r->akce('slide_save', fn(array $v) => ['id' => $v['id'] ?? 0]);
    eq(['id' => 7], $r->proved('slide_save', ['id' => 7]));
    ok($r->maAkci('slide_save') && !$r->maAkci('jina'));
    throws(fn() => $r->akce('slide_save', fn() => 1), 'už je zaregistrovaná');
    throws(fn() => $r->proved('jina'), 'Neznámá API akce');
    eq(['slide_save'], $r->seznamAkci());
    eq([], $r->sluc('media_pouziti', 3), 'bez obsluhy');
    $r->hak('media_pouziti', fn(int $id) => ["scénář $id"]);
    $r->hak('media_pouziti', fn(int $id) => ['banner A', 'banner B']);
    eq(['scénář 3', 'banner A', 'banner B'], $r->sluc('media_pouziti', 3));
});

final class TestMigrace implements ModulMigrace
{
    public static int $v = 2;
    public static function verze(): int { return self::$v; }
    public static function proved(Db $db, int $z): void
    {
        if ($z < 1) $db->q('CREATE TABLE modul_a (id INTEGER PRIMARY KEY)');
        if ($z < 2) $db->q('ALTER TABLE modul_a ADD COLUMN nazev TEXT');
    }
}

test('Migrace: nová instalace, opakované spuštění nic nemění, zvýšení verze, novější schéma v databázi', function () {
    $h = novyHost();
    $m = new Migrace($h->db);
    eq(0, $m->verze('a'));
    ok($m->spust('a', TestMigrace::class), 'poprvé');
    eq(2, $m->verze('a'));
    ok(!$m->spust('a', TestMigrace::class), 'podruhé nic');
    $h->db->q('INSERT INTO modul_a (nazev) VALUES (?)', ['ok']);
    TestMigrace::$v = 3;
    $h->db->q('UPDATE module_versions SET version = 5 WHERE module = ?', ['a']);
    throws(fn() => $m->spust('a', TestMigrace::class), 'novější schéma');
    TestMigrace::$v = 2;
    throws(fn() => $m->spust('a', stdClass::class), 'neimplementuje');
});

test('Migrace: selhání vrátí změny zpět a verzi nezapíše', function () {
    $h = novyHost();
    $m = new Migrace($h->db);
    $tr = new class implements ModulMigrace {
        public static function verze(): int { return 1; }
        public static function proved(Db $db, int $z): void { $db->q('CREATE TABLE b (id INTEGER)'); throw new RuntimeException('rozbito'); }
    };
    throws(fn() => $m->spust('b', $tr::class), 'rozbito');
    eq(0, $m->verze('b'));
    eq(0, (int)$h->db->q("SELECT COUNT(*) FROM sqlite_master WHERE name = 'b'")->fetchColumn(), 'tabulka nevznikla');
});

test('Host: cfg s výchozí hodnotou, vlastní registr', function () {
    $h = novyHost();
    eq('x', $h->cfg('neni', 'x'));
    ok($h->registr instanceof Registr);
});
