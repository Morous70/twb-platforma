<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';

use TWB\Jadro\Host;
use TWB\Zalohy\{Zalohy, Skupina, Snimek};

/** Aplikace se dvěma skupinami souborů: galerie (media) a písma (fonts). */
function aplikace(int $schema = 3, ?callable $cas = null, ?callable $po = null): array
{
    $h = novyHost();
    $h->db->q('CREATE TABLE media (id INTEGER PRIMARY KEY, filename TEXT, orig_filename TEXT)');
    $h->db->q('CREATE TABLE notes (id INTEGER PRIMARY KEY, media_id INTEGER REFERENCES media(id), text TEXT)');
    $h->nastaveni->uloz('backup_password', 'tajne-heslo');
    $h->nastaveni->uloz('schema_version', (string)$schema);
    $media = $h->datovaSlozka . '/media';
    $fonty = $h->datovaSlozka . '/fonts';
    @mkdir($media); @mkdir($fonty);
    $soubory = function (string $slozka, bool $zDb) use ($h): callable {
        return function () use ($slozka, $zDb, $h): array {
            $out = [];
            if ($zDb) {
                foreach ($h->db->q('SELECT filename, orig_filename FROM media')->fetchAll() as $m)
                    foreach ([$m['filename'], $m['orig_filename']] as $f) if ($f && is_file("$slozka/$f")) $out[$f] = md5_file("$slozka/$f");
            } else {
                foreach (glob("$slozka/u_*.ttf") ?: [] as $p) $out[basename($p)] = md5_file($p);
            }
            ksort($out);
            return $out;
        };
    };
    $z = new Zalohy($h, 'TWB Test', '1.0.0', $schema, ['settings', 'media', 'notes'], ['settings', 'media'], ['jen_zde'],
        [new Skupina('media', $media, $soubory($media, true), uklidit: true, bezKomprese: true),
         new Skupina('fonts', $fonty, $soubory($fonty, false), klic: 'font_files', vzdy: true)],
        'twb-test-zaloha-', 'Nastavení → Zálohy', $po, $cas);
    return [$h, $z, $media, $fonty];
}

function pridejMedium(Host $h, string $slozka, string $nazev, string $obsah): void
{
    file_put_contents("$slozka/$nazev", $obsah);
    $h->db->q('INSERT INTO media (filename) VALUES (?)', [$nazev]);
}

test('záloha bez hesla se nevytvoří', function () {
    [$h, $z] = aplikace();
    $h->nastaveni->uloz('backup_password', '');
    throws(fn() => $z->vytvor(), 'heslo záloh');
});

test('záloha je šifrovaný ZIP – bez hesla ani se špatným heslem se neotevře', function () {
    [$h, $z, $media] = aplikace();
    pridejMedium($h, $media, 'a.jpg', 'AAAA');
    $v = $z->vytvor();
    ok($v['files'], 'první záloha je vždy se soubory');
    throws(fn() => $z->otevri($z->cesta($v['name']), 'spatne'), 'nesprávné heslo');
    [$zip, $db] = $z->otevri($z->cesta($v['name']), 'tajne-heslo');
    eq(['a.jpg' => md5('AAAA')], $db['media_files']);
    $zip->close();
    // čitelnost bez hesla: obsah nesmí být v ZIPu vidět
    $syrove = file_get_contents($z->cesta($v['name']));
    ok(!str_contains($syrove, 'AAAA') && !str_contains($syrove, 'filename'), 'obsah není šifrovaný');
});

test('obnova vrátí databázi i soubory a zachová místní nastavení', function () {
    [$h, $z, $media, $fonty] = aplikace();
    pridejMedium($h, $media, 'a.jpg', 'AAAA');
    $h->db->q('INSERT INTO notes (media_id, text) VALUES (1, ?)', ['Poznámka č. 1']);
    file_put_contents("$fonty/u_x.ttf", 'FONT');
    $h->nastaveni->uloz('jen_zde', 'puvodni');
    $v = $z->vytvor();

    // změny po záloze
    $h->db->q('DELETE FROM notes');
    $h->db->q("UPDATE media SET filename = 'b.jpg'");
    unlink("$media/a.jpg"); file_put_contents("$media/b.jpg", 'BBBB'); unlink("$fonty/u_x.ttf");
    $h->nastaveni->uloz('jen_zde', 'nove');
    file_put_contents("$media/sirotek.jpg", 'X');

    $r = $z->obnov($z->cesta($v['name']), 'tajne-heslo');
    eq([], $r['missing']);
    eq('Poznámka č. 1', $h->db->q('SELECT text FROM notes')->fetchColumn());
    eq('a.jpg', $h->db->q('SELECT filename FROM media')->fetchColumn());
    eq('AAAA', file_get_contents("$media/a.jpg"));
    eq('FONT', file_get_contents("$fonty/u_x.ttf"));
    eq('nove', $h->nastaveni->dej('jen_zde'), 'místní nastavení se nepřepisuje');
    ok(!is_file("$media/b.jpg") && !is_file("$media/sirotek.jpg"), 'nepoužívané soubory se uklidí');
    ok(is_file($z->cesta($r['safety'])), 'pojistná záloha existuje');
    ok(str_contains($r['safety'], '-pred-obnovou'));
});

test('pojistná záloha obsahuje stav před obnovením', function () {
    [$h, $z, $media] = aplikace();
    pridejMedium($h, $media, 'a.jpg', 'AAAA');
    $v = $z->vytvor();
    $h->db->q('INSERT INTO notes (text) VALUES (?)', ['po záloze']);
    $r = $z->obnov($z->cesta($v['name']), 'tajne-heslo');
    eq(0, (int)$h->db->q('SELECT COUNT(*) FROM notes')->fetchColumn());
    [$zip, $db] = $z->otevri($z->cesta($r['safety']), 'tajne-heslo');
    $zip->close();
    eq('po záloze', $db['tables']['notes'][0]['text']);
});

test('soubory se při běžné záloze nepřidávají, dokud se nezmění', function () {
    $t = 1_800_000_000;
    [$h, $z, $media] = aplikace(3, function () use (&$t) { return $t; });
    pridejMedium($h, $media, 'a.jpg', 'AAAA');
    $a = $z->vytvor();
    $t += 60; $b = $z->vytvor();
    ok($a['files'] && !$b['files'], 'druhá záloha je jen databáze');
    $t += 60; pridejMedium($h, $media, 'c.jpg', 'CCCC');
    ok($z->vytvor()['files'], 'po změně galerie zase se soubory');
    $t += 60; ok($z->vytvor('', true)['files'], 'vynucení');
});

test('obnova z zálohy jen s databází vezme soubory z jiné zálohy; poškozené označí jako chybějící', function () {
    $t = 1_800_000_000;
    [$h, $z, $media] = aplikace(3, function () use (&$t) { return $t; });
    pridejMedium($h, $media, 'a.jpg', 'AAAA');
    $plna = $z->vytvor();
    $t += 60; $jen = $z->vytvor();
    ok(!$jen['files']);
    unlink("$media/a.jpg");
    $r = $z->obnov($z->cesta($jen['name']), 'tajne-heslo');
    eq([], $r['missing']);
    eq('AAAA', file_get_contents("$media/a.jpg"));

    // úplná ztráta: smazat všechny zálohy se soubory kromě jen-databázové
    unlink("$media/a.jpg");
    unlink($z->cesta($plna['name']));
    foreach ($z->seznam() as $b) if ($b['files']) unlink($z->cesta($b['name']));
    $jen2 = array_values(array_filter($z->seznam(), fn($b) => !$b['files']))[0]['name'];
    $r = $z->obnov($z->cesta($jen2), 'tajne-heslo');
    eq(['a.jpg'], $r['missing']);
});

test('záloha z novější verze aplikace se odmítne', function () {
    [$h, $z] = aplikace(5);
    $v = $z->vytvor();
    [$h2, $z2] = aplikace(4);
    copy($z->cesta($v['name']), $z2->slozka() . '/' . $v['name']);
    $msg = throws(fn() => $z2->obnov($z2->cesta($v['name']), 'tajne-heslo'), 'novější verze');
    ok(str_contains($msg, '1.0.0'));
});

test('obnova starší zálohy zavolá převod databáze s verzí zálohy', function () {
    $volano = [];
    [$h, $z] = aplikace(3, null, function (int $s) use (&$volano) { $volano[] = $s; });
    $h->nastaveni->uloz('schema_version', '2');
    $v = $z->vytvor();
    $h->nastaveni->uloz('schema_version', '3');
    $z->obnov($z->cesta($v['name']), 'tajne-heslo');
    eq([2], $volano);
    eq('2', $h->nastaveni->dej('schema_version'), 'verze schématu se vrací na verzi zálohy (převod ji zvedne)');
});

test('selhání při obnově databáze vrátí vše zpět', function () {
    [$h, $z, $media] = aplikace();
    pridejMedium($h, $media, 'a.jpg', 'AAAA');
    $h->db->q('INSERT INTO notes (media_id, text) VALUES (1, ?)', ['ok']);
    $v = $z->vytvor();
    // poškodit zálohu tak, aby vložení selhalo: nová záloha s duplicitním id
    [$zip, $db] = $z->otevri($z->cesta($v['name']), 'tajne-heslo'); $zip->close();
    $db['tables']['media'][] = $db['tables']['media'][0];
    $h->db->q('INSERT INTO notes (text) VALUES (?)', ['nesmí zmizet']);
    $rozbita = new ReflectionClass($z);
    $cesta = $z->slozka() . '/twb-test-zaloha-rozbita.zip';
    $zz = new ZipArchive(); $zz->open($cesta, ZipArchive::CREATE); $zz->setPassword('tajne-heslo');
    $zz->addFromString('database.json', json_encode($db)); $zz->setEncryptionName('database.json', ZipArchive::EM_AES_256, 'tajne-heslo'); $zz->close();
    throws(fn() => $z->obnov($cesta, 'tajne-heslo'), 'nic se nezměnilo');
    eq(2, (int)$h->db->q('SELECT COUNT(*) FROM notes')->fetchColumn());
    ok($h->db->pdo()->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION);
    // cizí klíče zase zapnuté
    eq(1, (int)$h->db->q('PRAGMA foreign_keys')->fetchColumn());
});

test('záloha bez povinných tabulek je poškozená, cizí ZIP není záloha', function () {
    [$h, $z] = aplikace();
    $cesta = $z->slozka() . '/twb-test-zaloha-x.zip';
    $zz = new ZipArchive(); $zz->open($cesta, ZipArchive::CREATE); $zz->setPassword('p');
    $zz->addFromString('database.json', json_encode(['tables' => ['settings' => []]])); $zz->setEncryptionName('database.json', ZipArchive::EM_AES_256, 'p'); $zz->close();
    throws(fn() => $z->otevri($cesta, 'p'), 'poškozená');
    file_put_contents($z->slozka() . '/twb-test-zaloha-y.zip', 'ne zip');
    throws(fn() => $z->otevri($z->slozka() . '/twb-test-zaloha-y.zip', 'p'), 'platná záloha');
});

test('cesta k záloze odmítne cizí názvy a procházení složek', function () {
    [$h, $z] = aplikace();
    foreach (['../x.zip', 'jina-predpona.zip', 'twb-test-zaloha-../a.zip', 'twb-test-zaloha-neexistuje.zip'] as $n) throws(fn() => $z->cesta($n), 'nenalezena');
});

test('úklid ponechá poslední zálohy a vždy nejnovější se soubory', function () {
    $t = 1_800_000_000;
    [$h, $z, $media] = aplikace(3, function () use (&$t) { return $t; });
    pridejMedium($h, $media, 'a.jpg', 'AAAA');
    $z->vytvor();   // se soubory
    for ($i = 0; $i < 9; $i++) { $t += 100; $z->vytvor(); }   // jen databáze
    $s = $z->seznam();
    eq(8, count($s), '7 nejnovějších + jedna se soubory');
    ok(count(array_filter($s, fn($b) => $b['files'])) === 1);
});

test('denní záloha: až od zadané hodiny a jen jednou za den', function () {
    $t = strtotime('2027-03-10 02:00:00');
    [$h, $z] = aplikace(3, function () use (&$t) { return $t; });
    eq(null, $z->dojde(), 'před třetí hodinou ne');
    $t = strtotime('2027-03-10 03:05:00');
    ok($z->dojde() !== null, 'po třetí ano');
    $t = strtotime('2027-03-10 18:00:00');
    eq(null, $z->dojde(), 'podruhé ten den ne');
    $t = strtotime('2027-03-11 03:01:00');
    ok($z->dojde() !== null, 'další den ano');
    $h->nastaveni->uloz('backup_auto', '0');
    $t = strtotime('2027-03-12 04:00:00');
    eq(null, $z->dojde(), 'vypnuto');
});

test('dvě zálohy ve stejné vteřině se nepřepíšou', function () {
    [$h, $z] = aplikace(3, fn() => 1_800_000_000);
    $a = $z->vytvor(); $b = $z->vytvor();
    ok($a['name'] !== $b['name']);
    ok(is_file($z->cesta($a['name'])) && is_file($z->cesta($b['name'])));
});

test('info o záloze počítá řádky tabulek', function () {
    [$h, $z, $media] = aplikace();
    pridejMedium($h, $media, 'a.jpg', 'AAAA');
    $i = $z->info($z->cesta($z->vytvor()['name']), 'tajne-heslo');
    eq(1, $i['tabulky']['media']);
    eq(1, $i['files']);
    eq('1.0.0', $i['app_version']);
});

test('neplatná předpona, skupina nebo tabulka se odmítne', function () {
    $h = novyHost();
    throws(fn() => new Zalohy($h, 'A', '1', 1, ['settings'], prefix: 'Zle Jmeno'), 'předpona');
    throws(fn() => new Zalohy($h, 'A', '1', 1, ['settings; DROP TABLE x']), 'Neplatný název');
    throws(fn() => new Zalohy($h, 'A', '1', 1, ['settings'], skupiny: [new Skupina('../x', '/tmp', fn() => [])]), 'skupiny');
});

test('snímek SQLite je samostatná použitelná databáze; automatické snímky se prořezávají', function () {
    $dir = sys_get_temp_dir() . '/twb-snimek-' . bin2hex(random_bytes(4));
    $soubor = sys_get_temp_dir() . '/twb-file-' . bin2hex(random_bytes(4)) . '.sqlite';
    $db = TWB\Jadro\Db::pripoj('sqlite:' . $soubor);
    $db->q('CREATE TABLE t (a TEXT)'); $db->q("INSERT INTO t VALUES ('ahoj')");
    $s = Snimek::automaticky($db, $dir, 'plan-', 2, 1_800_000_000);
    $kopie = TWB\Jadro\Db::pripoj('sqlite:' . $s);
    eq('ahoj', $kopie->q('SELECT a FROM t')->fetchColumn());
    Snimek::automaticky($db, $dir, 'plan-', 2, 1_800_000_000);
    Snimek::automaticky($db, $dir, 'plan-', 2, 1_800_000_000);
    $v = glob("$dir/plan-*.sqlite");
    eq(2, count($v));
    ok(in_array("$dir/plan-" . date('Ymd-His', 1_800_000_000) . '-v3.sqlite', $v, true), 'zůstaly nejnovější');
    throws(fn() => Snimek::automaticky($db, $dir, '../x', 2), 'předpona');
    $mysqlLike = novyHost();   // paměťová SQLite – ale ověříme aspoň chování na neexistující cestě
    throws(fn() => Snimek::vytvor($mysqlLike->db, '/neexistuje/slozka/x.sqlite'));
});

test('soubor, jehož kontrolní součet nesedí, se neobnoví a hlásí se jako chybějící', function () {
    [$h, $z, $media] = aplikace();
    pridejMedium($h, $media, 'a.jpg', 'AAAA');
    $v = $z->vytvor();
    [$zip, $db] = $z->otevri($z->cesta($v['name']), 'tajne-heslo');
    $data = $zip->getFromName('media/a.jpg'); $zip->close();
    $db['media_files']['a.jpg'] = md5('jiny obsah');   // záloha tvrdí něco jiného, než v ní je
    $cesta = sys_get_temp_dir() . '/podvrh-' . bin2hex(random_bytes(4)) . '.zip';
    $zz = new ZipArchive(); $zz->open($cesta, ZipArchive::CREATE); $zz->setPassword('tajne-heslo');
    $zz->addFromString('database.json', json_encode($db)); $zz->addFromString('media/a.jpg', $data);
    $zz->setEncryptionName('database.json', ZipArchive::EM_AES_256, 'tajne-heslo'); $zz->setEncryptionName('media/a.jpg', ZipArchive::EM_AES_256, 'tajne-heslo'); $zz->close();
    unlink("$media/a.jpg");
    foreach ($z->seznam() as $b) unlink($z->cesta($b['name']));   // žádná jiná záloha, ze které by šlo vzít soubor
    $r = $z->obnov($cesta, 'tajne-heslo');
    eq(['a.jpg'], $r['missing']);
    ok(!is_file("$media/a.jpg"));
});
