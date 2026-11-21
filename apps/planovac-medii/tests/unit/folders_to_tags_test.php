<?php
// Složky galerie nahrazené tagy (1.18.0): převod při aktualizaci databáze i při obnovení starší zálohy (migrace v18).
require dirname(__DIR__) . '/lib.php';
require_once APP_DIR . '/lib/tags.php';

test('migrace v18: médium dostane tag se jménem své složky, archivované podle složky před archivací; složky zmizí', function () {
    $now = date('Y-m-d H:i:s');
    q("INSERT INTO folders (name, created_at) VALUES ('Loterie', ?)", [$now]); $f1 = (int)db()->lastInsertId();
    q("INSERT INTO folders (name, created_at) VALUES ('Tabák, cigarety a dlouhý název přes třicet znaků', ?)", [$now]); $f2 = (int)db()->lastInsertId();
    $ins = function (string $n, ?int $folder, ?int $arch = null, ?array $tags = null) use ($now) {
        q("INSERT INTO media (filename, original_name, mime, kind, size, md5, folder_id, archive_folder_id, archived_at, tags, created_at) VALUES ('', ?, 'image/jpeg', 'image', 1, 'aa', ?, ?, ?, ?, ?)",
            [$n, $folder, $arch, $arch ? $now : null, $tags ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null, $now]);
        return (int)db()->lastInsertId();
    };
    $a = $ins('a.jpg', $f1);
    $b = $ins('b.jpg', $f1, null, ['loterie', 'Akce']);     // tag už má (jiná velikost písmen) → nepřidá se znovu
    $c = $ins('c.jpg', $f2);
    $d = $ins('d.jpg', null, $f1);                           // archivované ze složky Loterie
    $e = $ins('e.jpg', null);
    $full = $ins('f.jpg', $f1, null, array_map(fn($i) => "t$i", range(1, 10)));
    set_setting('schema_version', '17');
    run_migrations(17);
    $t = fn(int $id) => media_tags(q('SELECT tags FROM media WHERE id = ?', [$id])->fetch());
    eq(['Loterie'], $t($a));
    eq(['loterie', 'Akce'], $t($b));
    eq(['Tabák cigarety a dlouhý název'], $t($c), 'čárky pryč, nejvýš 30 znaků');
    eq(['Loterie'], $t($d));
    eq([], $t($e));
    eq(10, count($t($full)), 'plné médium beze změny');
    eq(0, (int)q('SELECT COUNT(*) FROM folders')->fetchColumn());
    eq(0, (int)q('SELECT COUNT(*) FROM media WHERE folder_id IS NOT NULL OR archive_folder_id IS NOT NULL')->fetchColumn());
    eq((string)SCHEMA_VERSION, (string)setting('schema_version'), 'převod doběhl do poslední verze');
});
