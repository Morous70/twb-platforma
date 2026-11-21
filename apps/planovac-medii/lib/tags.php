<?php
// Tagy médií (od 1.17.0): volné štítky pro třídění a hledání (na rozdíl od Skupiny, která určuje nahrazování).
// Uložené v media.tags jako JSON seznam. Velikost písmen se při porovnání nerozlišuje; použije se už existující zápis.

const TAG_MAX_LEN = 30;
const TAG_MAX_COUNT = 10;

function tag_key(string $t): string { return mb_strtolower($t, 'UTF-8'); }

/** Všechny tagy v galerii (i v archivu): [klíč => [name, count]], seřazené podle názvu. */
function tags_all(): array {
    $out = [];
    foreach (q('SELECT tags FROM media WHERE tags IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $j) {
        foreach (json_decode((string)$j, true) ?: [] as $t) {
            $k = tag_key((string)$t);
            if (!isset($out[$k])) $out[$k] = ['name' => (string)$t, 'count' => 0];
            $out[$k]['count']++;
        }
    }
    uasort($out, fn($a, $b) => strcoll($a['name'], $b['name']) ?: strcmp($a['name'], $b['name']));
    return $out;
}

/** Jeden tag: mezery a čárky pryč, nejvýš 30 znaků. Prázdný → null. */
function tag_clean(string $t): ?string {
    $t = trim(preg_replace('/[\s,;]+/u', ' ', $t));
    if ($t === '') return null;
    if (mb_strlen($t) > TAG_MAX_LEN) throw new InvalidArgumentException('Tag „' . mb_substr($t, 0, TAG_MAX_LEN) . '…“ je delší než ' . TAG_MAX_LEN . ' znaků.');
    return $t;
}

/** Seznam tagů média: vyčistit, bez opakování, zápis podle už existujícího tagu, nejvýš 10. */
function tags_clean($list, ?array $known = null): array {
    if (is_string($list)) $list = preg_split('/[,;]/u', $list);
    if (!is_array($list)) return [];
    $known ??= tags_all();
    $out = [];
    foreach ($list as $t) {
        if (!is_scalar($t) || ($t = tag_clean((string)$t)) === null) continue;
        $k = tag_key($t);
        if (isset($out[$k])) continue;
        $out[$k] = $known[$k]['name'] ?? $t;
    }
    if (count($out) > TAG_MAX_COUNT) throw new InvalidArgumentException('Médium může mít nejvýš ' . TAG_MAX_COUNT . ' tagů.');
    return array_values($out);
}

function media_tags(array $m): array {
    $t = json_decode((string)($m['tags'] ?? ''), true);
    return is_array($t) ? array_values(array_map('strval', $t)) : [];
}

function media_set_tags(int $id, array $tags): void {
    q('UPDATE media SET tags = ? WHERE id = ?', [$tags ? json_encode(array_values($tags), JSON_UNESCAPED_UNICODE) : null, $id]);
}

/** Hromadně: přidat a odebrat tagy u více médií. Vrací počet změněných médií. */
function tags_bulk(array $ids, array $add, array $remove): int {
    $known = tags_all();
    $add = tags_clean($add, $known);
    $rm = array_flip(array_map('tag_key', array_filter(array_map(fn($t) => is_scalar($t) ? trim((string)$t) : '', $remove))));
    $n = 0;
    foreach ($ids as $id) {
        $m = q('SELECT id, original_name, tags FROM media WHERE id = ?', [(int)$id])->fetch();
        if (!$m) continue;
        $cur = media_tags($m);
        $new = array_values(array_filter($cur, fn($t) => !isset($rm[tag_key($t)])));
        $new = array_merge($new, $add);
        try { $new = tags_clean($new, $known); }
        catch (InvalidArgumentException $e) { throw new InvalidArgumentException("„{$m['original_name']}“: " . $e->getMessage()); }
        if ($new === $cur) continue;
        media_set_tags((int)$m['id'], $new);
        $n++;
    }
    return $n;
}

/** Přejmenování tagu ve všech médiích (když nový název už existuje, tagy se sloučí). Vrací počet médií. */
function tag_rename(string $from, string $to): int {
    $to = tag_clean($to) ?? throw new InvalidArgumentException('Zadejte nový název tagu.');
    $fk = tag_key(trim($from));
    $known = tags_all();
    if (!isset($known[$fk])) throw new InvalidArgumentException('Tag nenalezen.');
    $tk = tag_key($to);
    $target = ($tk !== $fk && isset($known[$tk])) ? $known[$tk]['name'] : $to; // sloučení: zůstane zápis existujícího tagu
    $n = 0;
    foreach (q('SELECT id, tags FROM media WHERE tags IS NOT NULL')->fetchAll() as $m) {
        $cur = media_tags($m);
        if (!in_array($fk, array_map('tag_key', $cur), true)) continue;
        $new = []; $seen = [];
        foreach ($cur as $t) {
            $t = tag_key($t) === $fk ? $target : $t;
            if (isset($seen[tag_key($t)])) continue;
            $seen[tag_key($t)] = true; $new[] = $t;
        }
        media_set_tags((int)$m['id'], $new);
        $n++;
    }
    return $n;
}

/** Smazání tagu ze všech médií. Vrací počet médií. */
function tag_delete(string $name): int {
    $k = tag_key(trim($name));
    $n = 0;
    foreach (q('SELECT id, tags FROM media WHERE tags IS NOT NULL')->fetchAll() as $m) {
        $cur = media_tags($m);
        $new = array_values(array_filter($cur, fn($t) => tag_key($t) !== $k));
        if (count($new) === count($cur)) continue;
        media_set_tags((int)$m['id'], $new);
        $n++;
    }
    return $n;
}
