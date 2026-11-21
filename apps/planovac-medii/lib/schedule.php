<?php
// Rozvrhový engine: z aktivních scénářů sestaví, jaká média mají být ve složce v daném okamžiku.

const PRIORITY_LABEL = [1 => 'nízká', 2 => 'normální', 3 => 'vysoká'];

function load_scenarios(): array {
    $list = q('SELECT * FROM scenarios ORDER BY id')->fetchAll();
    $links = q('SELECT scenario_id, media_id FROM scenario_media')->fetchAll();
    $byScen = [];
    foreach ($links as $l) $byScen[(int)$l['scenario_id']][] = (int)$l['media_id'];
    $inc = [];
    foreach (q('SELECT scenario_id, child_id, follow_rules FROM scenario_includes ORDER BY child_id')->fetchAll() as $r) {
        $inc[(int)$r['scenario_id']][] = ['id' => (int)$r['child_id'], 'follow' => (int)$r['follow_rules']];
    }
    $off = [];
    foreach (q('SELECT scenario_id, fixed_id FROM scenario_fixed_off ORDER BY fixed_id')->fetchAll() as $r) $off[(int)$r['scenario_id']][] = (int)$r['fixed_id'];
    foreach ($list as &$s) {
        $s['id'] = (int)$s['id'];
        foreach (['is_default', 'priority', 'exclusive', 'paused', 'embed_only', 'is_fixed'] as $k) $s[$k] = (int)($s[$k] ?? 0);
        $s['fixed_off'] = $off[$s['id']] ?? [];
        $s['exceptions'] = $s['exceptions'] ? (json_decode($s['exceptions'], true) ?: []) : [];
        $s['media_ids'] = $byScen[$s['id']] ?? [];
        $s['includes'] = $inc[$s['id']] ?? [];
    }
    return $list;
}

function load_media_map(): array {
    $map = [];
    foreach (q('SELECT * FROM media')->fetchAll() as $m) { $m['id'] = (int)$m['id']; $m['members'] = []; $map[$m['id']] = $m; }
    foreach (q('SELECT pool_id, media_id FROM pool_members ORDER BY media_id')->fetchAll() as $r) {
        if (isset($map[(int)$r['pool_id']], $map[(int)$r['media_id']])) $map[(int)$r['pool_id']]['members'][] = (int)$r['media_id'];
    }
    // výběr podle data: pool → [den => [média]]
    try {
        foreach (q('SELECT pool_id, media_id, day FROM dated_items ORDER BY day, media_id')->fetchAll() as $r) {
            $p = (int)$r['pool_id']; $mid = (int)$r['media_id'];
            if (!isset($map[$p], $map[$mid])) continue;
            $map[$p]['days'][substr((string)$r['day'], 0, 10)][] = $mid;
            if (!in_array($mid, $map[$p]['members'], true)) $map[$p]['members'][] = $mid;
        }
    } catch (Throwable $e) { /* před převodem databáze */ }
    return $map;
}

// ---------- Náhodný výběr: rotace bez opakování, změna 1× za hodinu ----------

/**
 * Vrátí ID vylosovaného média pro danou hodinu.
 * $commit = true (synchronizace): posune balíček a uloží stav.
 * $commit = false (zobrazení): jen přečte stav, nic nemění; vrací null, pokud ještě nebylo losováno.
 */
function pool_pick(int $poolId, array $memberIds, string $hourKey, bool $commit): ?int {
    if (!$memberIds) return null;
    $st = q('SELECT * FROM random_state WHERE pool_id = ?', [$poolId])->fetch() ?: null;
    $current = $st && $st['current_id'] !== null ? (int)$st['current_id'] : null;
    $currentValid = $current !== null && in_array($current, $memberIds, true);

    if ($st && $st['hour_key'] === $hourKey && $currentValid) return $current;
    if (!$commit) return $currentValid ? $current : null;

    // další v balíčku (jen média, která ve výběru stále jsou)
    $deck = $st && $st['deck'] ? array_values(array_filter(json_decode($st['deck'], true) ?: [], fn($id) => in_array((int)$id, $memberIds, true))) : [];
    if (!$deck) {
        $deck = $memberIds;
        shuffle($deck);
        // nový cyklus nezačíná tím, čím skončil předchozí
        if (count($deck) > 1 && (int)$deck[0] === $current) $deck[] = array_shift($deck);
    }
    $next = (int)array_shift($deck);
    if ($st) q('UPDATE random_state SET deck = ?, current_id = ?, hour_key = ? WHERE pool_id = ?', [json_encode(array_values($deck)), $next, $hourKey, $poolId]);
    else q('INSERT INTO random_state (pool_id, deck, current_id, hour_key) VALUES (?, ?, ?, ?)', [$poolId, json_encode(array_values($deck)), $next, $hourKey]);
    return $next;
}

/** Nahradí náhodné výběry v sestavě vylosovaným médiem. */
function expand_pools(array $resolved, DateTimeInterface $t, bool $commit, ?array $mediaMap = null): array {
    $mediaMap ??= load_media_map();
    $hourKey = $t->format('Y-m-d H');
    $out = [];
    $pending = [];
    foreach ($resolved['media'] as $mid => $row) {
        if (($row['media']['kind'] ?? '') !== 'random') { $out[$mid] = $row; continue; }
        $pool = $row['media'];
        $members = array_values(array_filter($pool['members'], fn($id) => isset($mediaMap[$id]) && in_array($mediaMap[$id]['kind'], ['image', 'video', 'slide', 'html'], true)));
        $pick = pool_pick($pool['id'], $members, $hourKey, $commit);
        if ($pick === null) {
            if ($members) $pending[] = ['pool' => $pool['id'], 'scenario' => $row['scenario']];
            continue;
        }
        if (!isset($out[$pick])) $out[$pick] = ['media' => $mediaMap[$pick], 'scenario' => $row['scenario'], 'pool' => $pool['original_name'], 'pool_id' => $pool['id']];
    }
    ksort($out);
    $resolved['media'] = $out;
    $resolved['pending_pools'] = $pending;
    return $resolved;
}

/** Je scénář (mimo výchozí, pevný a sadu) aktivní v daném okamžiku? */
function scenario_active(array $s, DateTimeInterface $t): bool {
    if ($s['paused'] || $s['is_default'] || $s['embed_only'] || $s['is_fixed']) return false;
    return rules_match($s, $t);
}

/** Platí v daném okamžiku časová pravidla scénáře (datum, dny, hodiny, výjimky)? */
function rules_match(array $s, DateTimeInterface $t): bool {
    $date = $t->format('Y-m-d');
    if ($s['date_from'] && $date < $s['date_from']) return false;
    if ($s['date_to'] && $date > $s['date_to']) return false;
    if (!str_contains($s['weekdays'], $t->format('N'))) return false;   // 1 = pondělí … 7 = neděle
    if (($s['hours'][(int)$t->format('G')] ?? '0') !== '1') return false;
    if (in_array($date, $s['exceptions'], true)) return false;
    return true;
}

/**
 * Všechna média scénáře včetně vložených scénářů (rekurzivně).
 * Vrací [[mediaId, popis původu], …]. Vložený scénář se přeskočí, je-li pozastavený,
 * nebo má-li se řídit vlastními pravidly a ta právě neplatí. Cykly se ignorují.
 */
function effective_media(array $s, DateTimeInterface $t, array $byId, array $visited = [], string $path = ''): array {
    $path = $path === '' ? $s['name'] : $path;
    $visited[$s['id']] = true;
    $out = array_map(fn($mid) => [$mid, $path], $s['media_ids']);
    foreach ($s['includes'] as $inc) {
        $c = $byId[$inc['id']] ?? null;
        if (!$c || $c['paused'] || isset($visited[$c['id']])) continue;
        if ($inc['follow'] && !$c['is_default'] && !$c['embed_only'] && !$c['is_fixed'] && !rules_match($c, $t)) continue;
        foreach (effective_media($c, $t, $byId, $visited, $path . ' › ' . $c['name']) as $row) $out[] = $row;
    }
    return $out;
}

/**
 * Výběry podle data nahradí médii, která mají právě tento den. Řádky [médium, původ, id výběru|null].
 * Výběr bez média pro tento den nedodá nic.
 */
function expand_dated(array $rows, DateTimeInterface $t, array $mediaMap): array {
    $day = $t->format('Y-m-d');
    $out = [];
    foreach ($rows as [$mid, $via]) {
        $m = $mediaMap[$mid] ?? null;
        if (!$m) continue;
        if ($m['kind'] !== 'dated') { $out[] = [$mid, $via, null]; continue; }
        foreach ($m['days'][$day] ?? [] as $x) if (isset($mediaMap[$x])) $out[] = [$x, $via, $mid];
    }
    return $out;
}

/** Dodá scénář v tuto chvíli aspoň jedno médium? (prázdný výběr podle data / náhodný výběr se nepočítá) */
function has_content(array $rows, array $mediaMap): bool {
    foreach ($rows as [$mid]) {
        $m = $mediaMap[$mid] ?? null;
        if (!$m) continue;
        if ($m['kind'] === 'random' && !array_filter($m['members'], fn($x) => isset($mediaMap[$x]) && in_array($mediaMap[$x]['kind'], ['image', 'video', 'slide', 'html'], true))) continue;
        return true;
    }
    return false;
}

/** Pořadí pro souboj ve skupině: vyšší priorita > omezená platnost > kratší platnost > novější scénář. */
function scenario_rank(array $s): array {
    $limited = ($s['date_from'] || $s['date_to']) ? 1 : 0;
    $len = PHP_INT_MAX;
    if ($s['date_from'] && $s['date_to']) {
        $len = (int)((strtotime($s['date_to']) - strtotime($s['date_from'])) / 86400);
    } elseif ($limited) {
        $len = PHP_INT_MAX - 1;
    }
    return [$s['priority'], $limited, -$len, $s['id']];
}

/**
 * Vrátí výsledek pro okamžik $t:
 *  media: [mediaId => [ 'media'=>…, 'scenario'=>název ]]
 *  active: názvy aktivních scénářů, mode: normal|exclusive|default|empty
 *  suppressed: [ ['media'=>…, 'scenario'=>…, 'by'=>…, 'group'=>…] ]
 */
function resolve_at(DateTimeInterface $t, ?array $scenarios = null, ?array $mediaMap = null): array {
    $scenarios ??= load_scenarios();
    $mediaMap ??= load_media_map();

    $byId = [];
    foreach ($scenarios as $sc) $byId[$sc['id']] = $sc;
    $eff = [];
    $effOf = function (array $s) use (&$eff, $t, $byId, $mediaMap) {
        return $eff[$s['id']] ??= expand_dated(effective_media($s, $t, $byId), $t, $mediaMap);
    };
    // scénář, který by teď nezobrazil nic (např. výběr podle data bez média na dnešek), se bere jako neběžící
    $idle = [];
    $active = [];
    foreach ($scenarios as $s) {
        if (!scenario_active($s, $t)) continue;
        if (has_content($effOf($s), $mediaMap)) $active[] = $s; else $idle[] = $s['name'];
    }
    $mode = 'normal';
    $exclusive = array_values(array_filter($active, fn($s) => $s['exclusive']));
    $ignored = [];
    if ($exclusive) {
        $mode = 'exclusive';
        foreach ($active as $s) if (!$s['exclusive']) $ignored[] = $s['name'];
        $active = $exclusive;
    }
    if (!$active) {
        $def = array_values(array_filter($scenarios, fn($s) => $s['is_default'] && !$s['paused']));
        if ($def) { $active = [$def[0]]; $mode = 'default'; }
        else $mode = 'empty';
    }

    // pevné scénáře: přidají se ke všemu, co běží (i k výchozímu a výhradnímu, i když nic neběží),
    // pokud je některý z běžících scénářů nemá vypnuté
    $offIds = [];
    foreach ($active as $s) foreach ($s['fixed_off'] as $fid) $offIds[$fid] = true;
    $fixed = array_values(array_filter($scenarios, fn($s) => $s['is_fixed'] && !$s['paused'] && !isset($offIds[$s['id']])));
    $fixedOff = array_values(array_map(fn($s) => $s['name'], array_filter($scenarios, fn($s) => $s['is_fixed'] && !$s['paused'] && isset($offIds[$s['id']]))));
    $main = $active;
    $active = array_merge($active, $fixed);
    foreach ($active as $s) $effOf($s);

    // Kdo vyhrává jednotlivé skupiny
    // skupina média; položka výběru podle data bez vlastní skupiny přebírá skupinu výběru
    $grpOf = fn(int $mid, ?int $poolId) => (string)(($mediaMap[$mid]['grp'] ?? '') !== '' && $mediaMap[$mid]['grp'] !== null
        ? $mediaMap[$mid]['grp'] : ($poolId ? ($mediaMap[$poolId]['grp'] ?? '') : ''));
    $groupWinner = [];
    foreach ($active as $s) {
        foreach ($eff[$s['id']] as [$mid, , $poolId]) {
            $g = $grpOf($mid, $poolId);
            if ($g === '') continue;
            if (!isset($groupWinner[$g]) || scenario_rank($s) > scenario_rank($groupWinner[$g])) $groupWinner[$g] = $s;
        }
    }

    $result = [];
    $suppressed = [];
    foreach ($active as $s) {
        foreach ($eff[$s['id']] as [$mid, $via, $poolId]) {
            if (!isset($mediaMap[$mid])) continue;
            $m = $mediaMap[$mid];
            $g = $grpOf($mid, $poolId);
            if ($g !== '' && $groupWinner[$g]['id'] !== $s['id']) {
                $suppressed[] = ['media' => $m, 'scenario' => $via, 'by' => $groupWinner[$g]['name'], 'group' => $g];
                continue;
            }
            if (!isset($result[$mid])) $result[$mid] = ['media' => $m, 'scenario' => $via]
                + ($poolId ? ['pool' => $mediaMap[$poolId]['original_name'], 'pool_id' => $poolId] : []);
        }
    }
    // médium vytlačené jen v jednom scénáři, ale přítomné přes jiný, nehlásíme jako vytlačené
    $suppressed = array_values(array_filter($suppressed, fn($x) => !isset($result[$x['media']['id']])));

    ksort($result);
    return [
        'media' => $result,
        'active' => array_map(fn($s) => $s['name'], $main),
        'fixed' => array_map(fn($s) => $s['name'], $fixed),
        'fixed_off' => $fixedOff,
        'ignored' => $ignored,
        'idle' => $idle,
        'mode' => $mode,
        'suppressed' => $suppressed,
    ];
}

/** Cílové názvy souborů na Disku (při shodě názvů přidá „ (2)“). */
function target_files(array $resolved): array {
    $out = [];
    $used = [];
    foreach ($resolved['media'] as $mid => $row) {
        $m = $row['media'];
        $name = $m['original_name'];
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $i = 2;
        while (isset($used[mb_strtolower($name)])) {
            $name = $base . " ($i)" . ($ext !== '' ? ".$ext" : '');
            $i++;
        }
        $used[mb_strtolower($name)] = true;
        $out[] = ['media_id' => $mid, 'name' => $name, 'md5' => $m['md5'], 'mime' => $m['mime'],
                  'path' => MEDIA_DIR . '/' . $m['filename']];
    }
    return $out;
}
