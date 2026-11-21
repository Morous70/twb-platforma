<?php
declare(strict_types=1);
// Knihovna médií: evidence souborů v uploads, tagy (od 1.4.0 místo alb) a archiv.
// Tagy jsou v library.tags jako JSON seznam. Velikost písmen se při porovnání nerozlišuje; použije se už existující zápis.
// Archiv (library.archived_at): soubor zůstává v příspěvcích a dál se publikuje, jen se nenabízí ve výběru.

const TAG_MAX_LEN = 30;
const TAG_MAX_COUNT = 10;

function library_register(PDO $pdo, string $file, string $type, string $orig = '', array $tags = []): void {
    $path = PUBLIC_DIR . '/uploads/' . basename($file);
    $w = $h = null;
    if ($type === 'image' && ($gi = @getimagesize($path))) { $w = (int)$gi[0]; $h = (int)$gi[1]; }
    $name = trim(pathinfo($orig, PATHINFO_FILENAME)) ?: ($type === 'video' ? 'Video' : 'Obrázek');
    $tags = $tags ? tags_clean($pdo, $tags) : [];
    $pdo->prepare("INSERT OR IGNORE INTO library (file, name, type, size, w, h, created_at, tags) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([basename($file), mb_substr($name, 0, 120), $type, (int)@filesize($path), $w, $h, (int)@filemtime($path) ?: time(),
                   $tags ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null]);
}

/** Dorovná evidenci s diskem: nové soubory přidá, chybějící odebere. */
function library_sync(PDO $pdo): void {
    $dir = PUBLIC_DIR . '/uploads';
    if (!is_dir($dir)) return;
    $have = array_column($pdo->query("SELECT id, file FROM library")->fetchAll(), 'id', 'file');
    $onDisk = [];
    foreach (scandir($dir) ?: [] as $f) {
        if ($f[0] === '.' || !is_file("$dir/$f") || $f === 'index.html') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        $type = in_array($ext, ['jpg', 'jpeg', 'png'], true) ? 'image' : (in_array($ext, ['mp4', 'mov'], true) ? 'video' : null);
        if (!$type) continue;
        $onDisk[$f] = true;
        if (!isset($have[$f])) library_register($pdo, $f, $type, '');
    }
    foreach ($have as $f => $id) if (!isset($onDisk[$f])) $pdo->prepare("DELETE FROM library WHERE id = ?")->execute([$id]);
}

function library_usage(PDO $pdo, string $file): int {
    $a = $pdo->prepare("SELECT (SELECT COUNT(*) FROM posts WHERE media_file = ?) + (SELECT COUNT(*) FROM post_media WHERE file = ?)");
    $a->execute([$file, $file]);
    return (int)$a->fetchColumn();
}

/** Použití souborů v příspěvcích: [file => ['all' => n, 'upcoming' => n]] (upcoming = koncepty a naplánované). */
function library_usage_map(PDO $pdo): array {
    $out = [];
    $rows = $pdo->query("SELECT media_file AS f, status FROM posts WHERE media_file IS NOT NULL
                         UNION ALL SELECT m.file AS f, p.status FROM post_media m JOIN posts p ON p.id = m.post_id")->fetchAll();
    foreach ($rows as $r) {
        $f = (string)$r['f'];
        $out[$f] ??= ['all' => 0, 'upcoming' => 0];
        $out[$f]['all']++;
        if (in_array($r['status'], ['draft', 'scheduled', 'publishing'], true)) $out[$f]['upcoming']++;
    }
    return $out;
}

// ---------- tagy ----------

function tag_key(string $t): string { return mb_strtolower($t, 'UTF-8'); }

function item_tags(array $it): array {
    $t = json_decode((string)($it['tags'] ?? ''), true);
    return is_array($t) ? array_values(array_map('strval', $t)) : [];
}

/** Všechny tagy v knihovně (i v archivu): [klíč => ['name', 'count']], seřazené podle názvu. */
function tags_all(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query("SELECT tags FROM library WHERE tags IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $j) {
        foreach (json_decode((string)$j, true) ?: [] as $t) {
            $k = tag_key((string)$t);
            $out[$k] ??= ['name' => (string)$t, 'count' => 0];
            $out[$k]['count']++;
        }
    }
    uasort($out, fn($a, $b) => strcmp(tag_sort_key($a['name']), tag_sort_key($b['name'])));
    return $out;
}
function tag_sort_key(string $s): string {
    return strtr(mb_strtolower($s), ['á' => 'a~', 'č' => 'c~', 'ď' => 'd~', 'é' => 'e~', 'ě' => 'e~', 'í' => 'i~', 'ň' => 'n~', 'ó' => 'o~',
        'ř' => 'r~', 'š' => 's~', 'ť' => 't~', 'ú' => 'u~', 'ů' => 'u~', 'ý' => 'y~', 'ž' => 'z~']);
}

/** Jeden tag: mezery a čárky sloučit, nejvýš 30 znaků. Prázdný → null. */
function tag_clean(string $t): ?string {
    $t = trim((string)preg_replace('/[\s,;]+/u', ' ', $t));
    if ($t === '') return null;
    if (mb_strlen($t) > TAG_MAX_LEN) throw new InvalidArgumentException('Tag „' . mb_substr($t, 0, TAG_MAX_LEN) . '…“ je delší než ' . TAG_MAX_LEN . ' znaků.');
    return $t;
}

/** Seznam tagů: vyčistit, bez opakování, zápis podle už existujícího tagu, nejvýš 10. */
function tags_clean(PDO $pdo, $list, ?array $known = null): array {
    if (is_string($list)) $list = preg_split('/[,;]/u', $list);
    if (!is_array($list)) return [];
    $known ??= tags_all($pdo);
    $out = [];
    foreach ($list as $t) {
        if (!is_scalar($t) || ($t = tag_clean((string)$t)) === null) continue;
        $k = tag_key($t);
        if (isset($out[$k])) continue;
        $out[$k] = $known[$k]['name'] ?? $t;
    }
    if (count($out) > TAG_MAX_COUNT) throw new InvalidArgumentException('Soubor může mít nejvýš ' . TAG_MAX_COUNT . ' tagů.');
    return array_values($out);
}

function item_set_tags(PDO $pdo, int $id, array $tags): void {
    $pdo->prepare("UPDATE library SET tags = ? WHERE id = ?")
        ->execute([$tags ? json_encode(array_values($tags), JSON_UNESCAPED_UNICODE) : null, $id]);
}

/** Hromadně přidat a odebrat tagy. Vrací počet změněných souborů. */
function tags_bulk(PDO $pdo, array $ids, array $add, array $remove): int {
    $known = tags_all($pdo);
    $add = tags_clean($pdo, $add, $known);
    $rm = array_flip(array_map('tag_key', array_filter(array_map(fn($t) => is_scalar($t) ? trim((string)$t) : '', $remove))));
    $q = $pdo->prepare("SELECT id, name, tags FROM library WHERE id = ?");
    $n = 0;
    foreach ($ids as $id) {
        $q->execute([(int)$id]);
        if (!($it = $q->fetch())) continue;
        $cur = item_tags($it);
        $new = array_merge(array_values(array_filter($cur, fn($t) => !isset($rm[tag_key($t)]))), $add);
        try { $new = tags_clean($pdo, $new, $known); }
        catch (InvalidArgumentException $e) { throw new InvalidArgumentException("„{$it['name']}“: " . $e->getMessage()); }
        if ($new === $cur) continue;
        item_set_tags($pdo, (int)$it['id'], $new);
        $n++;
    }
    return $n;
}

/** Přejmenování tagu (když nový název už existuje, tagy se sloučí). Vrací počet souborů. */
function tag_rename(PDO $pdo, string $from, string $to): int {
    $to = tag_clean($to) ?? throw new InvalidArgumentException('Zadejte nový název tagu.');
    $fk = tag_key(trim($from));
    $known = tags_all($pdo);
    if (!isset($known[$fk])) throw new InvalidArgumentException('Tag nenalezen.');
    $tk = tag_key($to);
    $target = ($tk !== $fk && isset($known[$tk])) ? $known[$tk]['name'] : $to;
    $n = 0;
    foreach ($pdo->query("SELECT id, tags FROM library WHERE tags IS NOT NULL")->fetchAll() as $it) {
        $cur = item_tags($it);
        if (!in_array($fk, array_map('tag_key', $cur), true)) continue;
        $new = []; $seen = [];
        foreach ($cur as $t) {
            $t = tag_key($t) === $fk ? $target : $t;
            if (isset($seen[tag_key($t)])) continue;
            $seen[tag_key($t)] = true; $new[] = $t;
        }
        item_set_tags($pdo, (int)$it['id'], $new);
        $n++;
    }
    return $n;
}

/** Smazání tagu ze všech souborů (soubory zůstávají). Vrací počet souborů. */
function tag_delete(PDO $pdo, string $name): int {
    $k = tag_key(trim($name));
    $n = 0;
    foreach ($pdo->query("SELECT id, tags FROM library WHERE tags IS NOT NULL")->fetchAll() as $it) {
        $cur = item_tags($it);
        $new = array_values(array_filter($cur, fn($t) => tag_key($t) !== $k));
        if (count($new) === count($cur)) continue;
        item_set_tags($pdo, (int)$it['id'], $new);
        $n++;
    }
    return $n;
}
