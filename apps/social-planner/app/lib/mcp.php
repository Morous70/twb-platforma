<?php
declare(strict_types=1);
// MCP server (Model Context Protocol) – nástroje pro Claude / ChatGPT.
// Nástroje jen čtou, nebo vytvářejí KONCEPTY. Nic nezveřejní, nenaplánuje, neupraví ani nesmaže.

// Moderní (bezstavová) verze: server/discover, _meta v každém požadavku, hlavičky MCP-Protocol-Version / Mcp-Method.
const MCP_MODERN    = ['2026-07-28'];
// Starší verze s handshakem initialize (používá např. ChatGPT).
const MCP_PROTOCOLS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

function mcp_instructions(): string {
    return "Social Planner je soukromý plánovač příspěvků uživatele pro Facebook stránky, Instagram a Threads. "
         . "Čas je v pásmu " . date_default_timezone_get() . ". Před vytvořením konceptu zjisti ID účtů nástrojem seznam_uctu. "
         . "Koncept se nikam neodešle – uživatel ho zkontroluje a naplánuje v aplikaci (odkaz odkaz_upravit). "
         . "Instagram vyžaduje obrázek a povoluje nejvýše 30 hashtagů, Threads nejvýše 500 znaků textu. "
         . "Obrázky a slidy z Knihovny médií najdeš nástrojem knihovna_medii a do konceptu je dáš přes media_z_knihovny. "
         . "Text může obsahovat proměnné {{…}} (datum, svátek, data, Allwyn, počasí – přehled dá promenne_a_data); dosadí se až při zveřejnění, "
         . "výsledek pro zvolený čas ověříš nástrojem nahled_textu. Odpovídej česky.";
}

/** Definice nástrojů. 'scope' = potřebné oprávnění, 'write' = mění data (jen koncepty). */
function mcp_tool_defs(): array {
    $date = ['type' => 'string', 'description' => 'Datum RRRR-MM-DD'];
    return [
        'seznam_uctu' => [
            'title' => 'Seznam účtů', 'scope' => 'read', 'write' => false,
            'description' => 'Vrátí povolené účty (ID, síť, název) a skupiny účtů. ID potřebuješ pro vytvor_koncept a filtry ostatních nástrojů.',
            'schema' => ['type' => 'object', 'properties' => new stdClass()],
        ],
        'prispevky' => [
            'title' => 'Příspěvky v období', 'scope' => 'read', 'write' => false,
            'description' => 'Příspěvky (koncepty, naplánované, odeslané, selhané) v zadaném období podle času publikování. Výchozí období: dnes a 14 dní dopředu.',
            'schema' => ['type' => 'object', 'properties' => [
                'od' => $date + ['description' => 'Začátek období RRRR-MM-DD (výchozí dnes)'],
                'do' => $date + ['description' => 'Konec období RRRR-MM-DD včetně (výchozí od + 14 dní)'],
                'stav' => ['type' => 'string', 'enum' => ['vse', 'koncept', 'naplanovano', 'odeslano', 'selhalo'], 'description' => 'Filtr stavu (výchozí vse)'],
                'ucet_id' => ['type' => 'integer', 'description' => 'Jen příspěvky pro tento účet'],
            ]],
        ],
        'statistiky' => [
            'title' => 'Statistiky', 'scope' => 'read', 'write' => false,
            'description' => 'Výsledky publikovaných příspěvků (dosah, reakce, komentáře, sdílení, uložení) za posledních N dní, se součty. Čísla se ze sítí obnovují jednou denně.',
            'schema' => ['type' => 'object', 'properties' => [
                'dny' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'description' => 'Počet dní zpět (výchozí 30)'],
                'ucet_id' => ['type' => 'integer', 'description' => 'Jen tento účet'],
            ]],
        ],
        'nejlepsi_cas' => [
            'title' => 'Nejlepší čas k publikování', 'scope' => 'read', 'write' => false,
            'description' => 'Doporučené dny a hodiny publikování podle vlastních statistik (porovnání s průměrem účtu, posledních 120 dní). Vrací i nejbližší termíny. Bez dostatku dat (enough=false) nic nedoporučuj.',
            'schema' => ['type' => 'object', 'properties' => [
                'ucty' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'ID účtů (výchozí všechny)'],
            ]],
        ],
        'sady_hashtagu' => [
            'title' => 'Sady hashtagů', 'scope' => 'read', 'write' => false,
            'description' => 'Uložené sady hashtagů (název a štítky). Název sady lze použít ve vytvor_koncept.',
            'schema' => ['type' => 'object', 'properties' => new stdClass()],
        ],
        'sablony' => [
            'title' => 'Šablony příspěvků', 'scope' => 'read', 'write' => false,
            'description' => 'Uložené šablony příspěvků (text, odkaz, štítky, účty) jako vzor stylu a struktury.',
            'schema' => ['type' => 'object', 'properties' => new stdClass()],
        ],
        'knihovna_medii' => [
            'title' => 'Knihovna médií', 'scope' => 'read', 'write' => false,
            'description' => 'Obrázky, videa a slidy v Knihovně médií (ID, název, tagy, rozměr, náhled). ID použij ve vytvor_koncept → media_z_knihovny. Slidy jsou obrázky s daty (jackpot, datum, svátek…), které se před zveřejněním samy aktualizují. Soubory v archivu se ve výchozím stavu nevrací. Když je uvest_autora true, připiš do textu (nebo prvního komentáře) „Foto: “ + autor_licence.',
            'schema' => ['type' => 'object', 'properties' => [
                'hledat' => ['type' => 'string', 'description' => 'Část názvu nebo tagu'],
                'tag' => ['type' => 'string', 'description' => 'Jen soubory s tímto tagem'],
                'typ' => ['type' => 'string', 'enum' => ['vse', 'obrazek', 'slide', 'video'], 'description' => 'Výchozí vse'],
                'archiv' => ['type' => 'boolean', 'description' => 'true = jen soubory v archivu'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Výchozí 40'],
            ]],
        ],
        'promenne_a_data' => [
            'title' => 'Proměnné a data', 'scope' => 'read', 'write' => false,
            'description' => 'Proměnné použitelné v textu příspěvku jako {{…}}: kalendář (datum, svátek…), vlastní proměnné, tabulka dat (aktuální hodnoty skupin), vzdálené zdroje (Allwyn…) s ukázkovými zápisy a hodnotami, počasí a formáty (|mil, |datum:den…). S parametrem zdroj vrátí všechny hodnoty jednoho zdroje.',
            'schema' => ['type' => 'object', 'properties' => [
                'zdroj' => ['type' => 'string', 'description' => 'Název vzdáleného zdroje pro úplný výpis hodnot (např. Allwyn)'],
            ]],
        ],
        'nahled_textu' => [
            'title' => 'Náhled textu s proměnnými', 'scope' => 'read', 'write' => false,
            'description' => 'Dosadí proměnné {{…}} do textu tak, jak to udělá zveřejnění v zadaném čase. Vrátí výsledný text a neznámé proměnné. Nic neukládá.',
            'schema' => ['type' => 'object', 'required' => ['text'], 'properties' => [
                'text' => ['type' => 'string', 'description' => 'Text s proměnnými, např. Jackpot {{Allwyn:sportka.superjackpot|mil}} Kč'],
                'cas' => ['type' => 'string', 'description' => 'Čas zveřejnění, např. 2026-12-01 18:00 (výchozí teď)'],
            ]],
        ],
        'vytvor_koncept' => [
            'title' => 'Vytvořit koncept', 'scope' => 'drafts', 'write' => true,
            'description' => 'Uloží KONCEPT příspěvku do plánovače. Nic se nezveřejní ani nenaplánuje – uživatel koncept zkontroluje a naplánuje v aplikaci. Před voláním ukaž uživateli text a ověř účty. Text smí obsahovat proměnné {{…}} (dosadí se při zveřejnění).',
            'schema' => ['type' => 'object', 'required' => ['text'], 'properties' => [
                'text' => ['type' => 'string', 'description' => 'Text příspěvku (bez hashtagů, ty dej do stitky). Max. 5000 znaků.'],
                'ucty' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'ID účtů ze seznam_uctu'],
                'skupina' => ['type' => 'string', 'description' => 'Název skupiny účtů (místo nebo navíc k ucty)'],
                'cas' => ['type' => 'string', 'description' => 'Navržený čas publikování, např. 2026-12-01 18:00 (místní čas). Výchozí teď.'],
                'odkaz' => ['type' => 'string', 'description' => 'Odkaz k příspěvku (https://…)'],
                'stitky' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Hashtagy bez # (max. 30)'],
                'sady_hashtagu' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Názvy uložených sad hashtagů'],
                'obrazky' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Veřejné adresy obrázků https://… (max. 10, JPEG/PNG/WebP/GIF)'],
                'media_z_knihovny' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'ID obrázků / slidů z knihovna_medii (dají se před obrazky; video jen samotné). Celkem max. 10.'],
                'prvni_komentar' => ['type' => 'string', 'description' => 'První komentář pod příspěvkem (volitelné)'],
            ]],
        ],
    ];
}

function mcp_tools_list(array $grant): array {
    $out = [];
    foreach (mcp_tool_defs() as $name => $d) {
        if (!in_array($d['scope'], $grant['scopes'], true)) continue;
        $out[] = [
            'name' => $name, 'title' => $d['title'], 'description' => $d['description'], 'inputSchema' => $d['schema'],
            'annotations' => ['title' => $d['title'], 'readOnlyHint' => !$d['write'], 'destructiveHint' => false, 'idempotentHint' => !$d['write'], 'openWorldHint' => false],
        ];
    }
    return $out;
}

function mcp_dt(?int $ts): ?string { return $ts ? date('Y-m-d H:i', $ts) : null; }
function mcp_base(): string { return rtrim((string)cfg('base_url'), '/'); }

const MCP_STATUS = ['draft' => 'koncept', 'scheduled' => 'naplanovano', 'publishing' => 'odesila_se', 'done' => 'odeslano', 'partial' => 'castecne', 'failed' => 'selhalo'];

/** Spustí nástroj; vrací data (pole). Chyby uživatele = InvalidArgumentException. */
function mcp_call(PDO $pdo, array $grant, string $name, array $a): array {
    $defs = mcp_tool_defs();
    if (!isset($defs[$name])) throw new InvalidArgumentException("Neznámý nástroj $name.");
    if (!in_array($defs[$name]['scope'], $grant['scopes'], true)) throw new InvalidArgumentException('Tento nástroj není v povoleném přístupu (oprávnění „' . $defs[$name]['scope'] . '“). Připojte aplikaci znovu a povolte ho.');
    switch ($name) {
    case 'seznam_uctu':
        $acc = $pdo->query("SELECT a.id, a.platform AS sit, a.name AS nazev, p.name AS facebook_stranka, a.health, a.health_msg, a.health_at, a.token_expires
                            FROM accounts a LEFT JOIN accounts p ON p.platform = 'facebook' AND p.remote_id = a.page_id AND a.platform = 'instagram'
                            WHERE a.enabled = 1 ORDER BY a.platform, a.name COLLATE NOCASE")->fetchAll();
        foreach ($acc as &$r) {
            $r['id'] = (int)$r['id'];
            if ($r['facebook_stranka'] === null) unset($r['facebook_stranka']);
            $r['stav_pripojeni'] = $r['health'] === 'ok' ? 'v pořádku' : ($r['health'] === 'error' ? 'chyba – propojit znovu: ' . $r['health_msg'] : 'neověřeno');
            if ($r['health_at']) $r['overeno'] = mcp_dt((int)$r['health_at']);
            if ($r['sit'] === 'threads' && $r['token_expires']) $r['token_plati_do'] = mcp_dt((int)$r['token_expires']);
            unset($r['health'], $r['health_msg'], $r['health_at'], $r['token_expires']);
        }
        unset($r);
        $groups = array_map(fn($g) => ['nazev' => $g['name'], 'ucty' => array_map('intval', array_filter(explode(',', $g['account_ids'])))],
                            $pdo->query("SELECT name, account_ids FROM account_groups ORDER BY name")->fetchAll());
        return ['ucty' => $acc, 'skupiny' => $groups];

    case 'prispevky':
        $tz = new DateTimeZone(date_default_timezone_get());
        $od = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($a['od'] ?? date('Y-m-d')), $tz);
        if (!$od) throw new InvalidArgumentException('od: použijte formát RRRR-MM-DD.');
        $do = isset($a['do']) ? DateTimeImmutable::createFromFormat('!Y-m-d', (string)$a['do'], $tz) : $od->modify('+14 days');
        if (!$do) throw new InvalidArgumentException('do: použijte formát RRRR-MM-DD.');
        if ($do < $od) throw new InvalidArgumentException('do je dřív než od.');
        $from = $od->getTimestamp(); $to = $do->modify('+1 day')->getTimestamp();
        $map = ['koncept' => ['draft'], 'naplanovano' => ['scheduled', 'publishing'], 'odeslano' => ['done', 'partial'], 'selhalo' => ['failed', 'partial']];
        $sql = "SELECT * FROM posts WHERE scheduled_at >= $from AND scheduled_at < $to";
        $args = [];
        if (($st = (string)($a['stav'] ?? 'vse')) !== 'vse') {
            if (!isset($map[$st])) throw new InvalidArgumentException('stav: vse, koncept, naplanovano, odeslano nebo selhalo.');
            $sql .= " AND status IN ('" . implode("','", $map[$st]) . "')";
        }
        if (!empty($a['ucet_id'])) { $sql .= " AND id IN (SELECT post_id FROM targets WHERE account_id = ?)"; $args[] = (int)$a['ucet_id']; }
        $q = $pdo->prepare($sql . " ORDER BY scheduled_at LIMIT 100"); $q->execute($args);
        $tq = $pdo->prepare("SELECT a.id, a.platform, a.name, t.status, t.error FROM targets t JOIN accounts a ON a.id = t.account_id WHERE t.post_id = ?");
        $mq = $pdo->prepare("SELECT COUNT(*) FROM post_media WHERE post_id = ?");
        $out = [];
        foreach ($q->fetchAll() as $p) {
            $tq->execute([$p['id']]); $mq->execute([$p['id']]);
            $out[] = [
                'id' => (int)$p['id'], 'stav' => MCP_STATUS[$p['status']] ?? $p['status'], 'cas' => mcp_dt((int)$p['scheduled_at']),
                'typ' => $p['kind'] ?? 'feed', 'text' => mb_strimwidth((string)$p['message'], 0, 600, '…'),
                'odkaz' => $p['link'] ?: null, 'stitky' => $p['tags'] ? explode(' ', $p['tags']) : [],
                'medii' => $p['media_file'] ? 1 + (int)$mq->fetchColumn() : 0,
                'opakovani' => ($p['repeat_mode'] ?? 'none') !== 'none' ? $p['repeat_mode'] : null,
                'ucty' => array_map(fn($t) => ['id' => (int)$t['id'], 'sit' => $t['platform'], 'nazev' => $t['name'], 'stav' => $t['status']] + ($t['error'] ? ['chyba' => $t['error']] : []), $tq->fetchAll()),
                'odkaz_upravit' => mcp_base() . '/novy.php?edit=' . $p['id'],
            ];
        }
        return ['obdobi' => ['od' => $od->format('Y-m-d'), 'do' => $do->format('Y-m-d')], 'pocet' => count($out), 'prispevky' => $out];

    case 'statistiky':
        $days = max(1, min(365, (int)($a['dny'] ?? 30)));
        $sql = "SELECT t.post_id, t.published_at, t.stat_reach, t.stat_likes, t.stat_comments, t.stat_shares, t.stat_saves, a.id AS ucet_id, a.platform, a.name, p.message
                FROM targets t JOIN accounts a ON a.id = t.account_id JOIN posts p ON p.id = t.post_id
                WHERE t.status = 'done' AND t.published_at >= " . (time() - $days * 86400);
        $args = [];
        if (!empty($a['ucet_id'])) { $sql .= " AND a.id = ?"; $args[] = (int)$a['ucet_id']; }
        $q = $pdo->prepare($sql . " ORDER BY t.published_at DESC LIMIT 200"); $q->execute($args);
        $rows = $q->fetchAll();
        $sum = fn($k) => array_sum(array_map(fn($r) => (int)$r[$k], $rows));
        $n = fn($v) => $v === null ? null : (int)$v;
        return [
            'dny' => $days,
            'naposledy_obnoveno' => mcp_dt((int)meta_get('last_stats', '0')),
            'souhrn' => ['prispevku' => count($rows), 'dosah' => $sum('stat_reach'), 'reakce' => $sum('stat_likes'), 'komentare' => $sum('stat_comments'), 'sdileni' => $sum('stat_shares'), 'ulozeni' => $sum('stat_saves')],
            'prispevky' => array_map(fn($r) => [
                'id' => (int)$r['post_id'], 'cas' => mcp_dt((int)$r['published_at']), 'ucet' => $r['name'], 'sit' => $r['platform'],
                'text' => mb_strimwidth((string)$r['message'], 0, 160, '…'),
                'dosah' => $n($r['stat_reach']), 'reakce' => $n($r['stat_likes']), 'komentare' => $n($r['stat_comments']), 'sdileni' => $n($r['stat_shares']), 'ulozeni' => $n($r['stat_saves']),
            ], array_slice($rows, 0, 60)),
            'poznamka' => 'null = síť číslo neposkytla (chybí oprávnění) nebo ještě nebylo načteno.',
        ];

    case 'nejlepsi_cas':
        $ids = array_map('intval', (array)($a['ucty'] ?? []));
        $r = best_times($pdo, $ids);
        $dny = [1 => 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota', 'neděle'];
        return [
            'dost_dat' => $r['enough'], 'zmereno_prispevku' => $r['samples'], 'potreba' => $r['need'],
            'doporuceni' => array_map(fn($t) => ['den' => $dny[$t['d']], 'hodina' => sprintf('%d:00', $t['h']), 'lepsi_nez_prumer_o_procent' => $t['lift'], 'vyslo_prispevku' => $t['n'], 'nejblizsi_termin' => mcp_dt($t['next'])], $r['top']),
            'podle_dnu' => array_map(fn($d) => ['den' => $dny[$d['d']], 'prispevku' => $d['n'], 'index' => $d['s']], $r['days']),
            'vysvetleni' => 'index 1,0 = běžný příspěvek daného účtu; 1,3 = o 30 % lepší.',
        ];

    case 'sady_hashtagu':
        return ['sady' => array_map(fn($s) => ['nazev' => $s['name'], 'stitky' => preg_split('/\s+/', trim($s['tags']), -1, PREG_SPLIT_NO_EMPTY)],
                                    $pdo->query("SELECT name, tags FROM hashtag_sets ORDER BY name COLLATE NOCASE")->fetchAll())];

    case 'sablony':
        return ['sablony' => array_map(fn($t) => ['nazev' => $t['name'], 'text' => $t['message'], 'odkaz' => $t['link'] ?: null,
                                                   'stitky' => $t['tags'] ? explode(' ', $t['tags']) : [], 'ucty' => array_map('intval', array_filter(explode(',', $t['account_ids'])))],
                                        $pdo->query("SELECT * FROM templates ORDER BY name COLLATE NOCASE")->fetchAll())];

    case 'vytvor_koncept':
        $in = [
            'message' => (string)($a['text'] ?? ''),
            'account_ids' => array_map('intval', (array)($a['ucty'] ?? [])),
            'link' => (string)($a['odkaz'] ?? ''),
            'tags' => (array)($a['stitky'] ?? []),
            'tag_sets' => (array)($a['sady_hashtagu'] ?? []),
            'images' => (array)($a['obrazky'] ?? []),
            'library_ids' => array_map('intval', (array)($a['media_z_knihovny'] ?? [])),
            'first_comment' => (string)($a['prvni_komentar'] ?? ''),
        ];
        if (!empty($a['skupina'])) $in['group'] = (string)$a['skupina'];
        if (!empty($a['cas'])) $in['suggested_at'] = (string)$a['cas'];
        if (!$in['account_ids'] && empty($in['group'])) throw new InvalidArgumentException('Zadejte ucty (ID ze seznam_uctu) nebo skupina.');
        set_time_limit(180);
        require_once __DIR__ . '/data.php';
        $unk = array_values(array_unique(array_merge(unknown_vars($in['message'], user_vars($pdo)), unknown_vars($in['first_comment'], user_vars($pdo)))));
        [$id] = inbox_create($pdo, ['name' => 'MCP: ' . $grant['client_name']], $in);
        $p = $pdo->query("SELECT scheduled_at FROM posts WHERE id = " . (int)$id)->fetch();
        $out = ['id' => $id, 'stav' => 'koncept', 'navrzeny_cas' => mcp_dt((int)$p['scheduled_at']), 'odkaz_upravit' => mcp_base() . '/novy.php?edit=' . $id,
                'zprava' => 'Koncept je uložen. Uživatel ho zkontroluje a naplánuje v aplikaci (Příspěvky → Koncepty).'];
        if (str_contains($in['message'], '{{')) $out['nahled_textu'] = resolve_vars($in['message'], dt_from_ts((int)$p['scheduled_at']), ['user' => user_vars($pdo)])[0];
        if ($unk) $out['varovani'] = 'Neznámé proměnné ' . implode(', ', $unk) . ' – aplikace koncept nenaplánuje, dokud je uživatel neopraví. Zkontroluj zápis v promenne_a_data.';
        return $out;

    case 'knihovna_medii':
        $typ = (string)($a['typ'] ?? 'vse');
        if (!in_array($typ, ['vse', 'obrazek', 'slide', 'video'], true)) throw new InvalidArgumentException('typ: vse, obrazek, slide nebo video.');
        $limit = max(1, min(100, (int)($a['limit'] ?? 40)));
        $q = mb_strtolower(trim((string)($a['hledat'] ?? '')));
        $tag = mb_strtolower(trim((string)($a['tag'] ?? '')));
        $arch = !empty($a['archiv']);
        $use = library_usage_map($pdo);
        require_once __DIR__ . '/stockimg.php';
        $rows = $pdo->query("SELECT id, file, name, type, w, h, size, created_at, tags, archived_at, design IS NOT NULL AS slide, credit FROM library ORDER BY created_at DESC, id DESC")->fetchAll();
        $out = []; $total = 0;
        foreach ($rows as $r) {
            if ((bool)$r['archived_at'] !== $arch) continue;
            $tags = item_tags($r);
            $kind = $r['slide'] ? 'slide' : ($r['type'] === 'video' ? 'video' : 'obrazek');
            if ($typ !== 'vse' && $typ !== $kind) continue;
            if ($tag !== '' && !in_array($tag, array_map('mb_strtolower', $tags), true)) continue;
            if ($q !== '' && !str_contains(mb_strtolower($r['name'] . ' ' . implode(' ', $tags)), $q)) continue;
            $total++;
            if (count($out) >= $limit) continue;
            $path = PUBLIC_DIR . '/uploads/' . basename($r['file']);
            $out[] = ['id' => (int)$r['id'], 'nazev' => $r['name'], 'typ' => $kind, 'tagy' => $tags,
                      'rozmer' => $r['w'] ? "{$r['w']}×{$r['h']}" : null, 'nahrano' => mcp_dt((int)$r['created_at']),
                      'v_prispevcich' => $use[$r['file']]['all'] ?? 0,
                      'autor_licence' => $r['credit'] ? img_credit_text($r['credit']) : null,
                      'uvest_autora' => $r['credit'] && in_array(img_credit_decode($r['credit'])['license'], ['cc-by', 'cc-by-sa', 'jine'], true),
                      'url' => mcp_base() . '/uploads/' . rawurlencode($r['file']) . (is_file($path) ? '?v=' . filemtime($path) : '')];
        }
        return ['pocet' => $total, 'vraceno' => count($out), 'tagy' => array_values(array_map(fn($t) => $t['name'], tags_all($pdo))), 'soubory' => $out];

    case 'promenne_a_data':
        require_once __DIR__ . '/data.php';
        $t = new DateTimeImmutable();
        if (!empty($a['zdroj'])) {
            $src = source_by_name((string)$a['zdroj']);
            if (!$src) throw new InvalidArgumentException('Zdroj „' . $a['zdroj'] . '“ neexistuje. Názvy zdrojů vrátí promenne_a_data bez parametru.');
            $items = [];
            foreach ($src['json'] !== null ? source_tree($src) : [] as $g) foreach ($g['items'] as $i) {
                $items[] = ['zapis' => '{{' . $src['name'] . ':' . $i['path'] . '}}', 'popis' => trim($g['title'] . ' – ' . $i['label'], ' –'), 'hodnota' => $i['value'], 'druh' => $i['kind']] + (!empty($i['tech']) ? ['technicke' => true] : []);
            }
            return ['zdroj' => $src['name'], 'stazeno' => mcp_dt($src['ok_at'] ? (int)$src['ok_at'] : null), 'chyba' => $src['error'], 'hodnoty' => $items];
        }
        $user = user_vars($pdo);
        $groups = [];
        foreach (data_groups() as $g) {
            $rows = data_group($g);
            $dated = (bool)array_filter($rows, fn($r) => parse_any_date($r['param']));
            $groups[] = $dated
                ? ['skupina' => $g, 'radku' => count($rows), 'zapisy' => ["{{{$g}.nejbližší}}", "{{{$g}.nejbližší.parametr}}", "{{{$g}.další}}", "{{{$g}.poslední}}"],
                   'nejblizsi' => data_value("$g.nejbližší", '', $t), 'nejblizsi_datum' => data_value("$g.nejbližší.parametr", '', $t)]
                : ['skupina' => $g, 'radku' => count($rows), 'polozky' => array_map(fn($r) => ['zapis' => "{{{$g}:{$r['param']}}}", 'hodnota' => $r['value']], array_slice($rows, 0, 20))];
        }
        $sources = [];
        foreach (sources_all() as $src) {
            $ex = [];
            foreach ($src['json'] !== null ? source_tree($src) : [] as $g) foreach ($g['items'] as $i) {
                if (!empty($i['tech']) || count($ex) >= 12) continue;
                $ex[] = ['zapis' => '{{' . $src['name'] . ':' . $i['path'] . '}}', 'popis' => trim($g['title'] . ' – ' . $i['label'], ' –'), 'hodnota' => $i['value']];
            }
            $sources[] = ['nazev' => $src['name'], 'stazeno' => mcp_dt($src['ok_at'] ? (int)$src['ok_at'] : null), 'chyba' => $src['error'], 'ukazky' => $ex,
                          'poznamka' => 'Úplný výpis: promenne_a_data se zdroj="' . $src['name'] . '".'];
        }
        return [
            'zapis' => 'Proměnná se píše do textu jako {{nazev}}, formát za svislou čarou: {{Allwyn:sportka.superjackpot|mil}}. Dosazuje se při zveřejnění podle jeho data a času.',
            'kalendar' => array_map(fn($k, $v) => ['zapis' => '{{' . $k . '}}', 'popis' => $v], array_keys(BUILTIN_VARS), BUILTIN_VARS),
            'vlastni' => array_map(fn($u) => ['zapis' => '{{' . $u['name'] . '}}', 'druh' => $u['type'] === 'list' ? 'seznam (střídá se při opakování)' : 'text', 'hodnota' => mb_strimwidth((string)$u['value'], 0, 200, '…')], array_values($user)),
            'tabulka_dat' => $groups,
            'zdroje' => $sources,
            'pocasi' => weather_configured()
                ? ['vychozi_misto' => weather_default_place(), 'zapisy' => ['{{Počasí:teplota}}', '{{Počasí:popis}}', '{{Počasí:zítra.max}}', '{{Počasí:zítra.min}}', '{{Počasí:pozítří.den}}', '{{Počasí:Brno.teplota}}']]
                : ['poznamka' => 'Počasí není nastavené (chybí API klíč).'],
            'formaty' => ['|mil' => '164 mil.', '|milionu' => '164 milionů', '|mld' => '2,93 mld.', '|cislo' => '164 000 000', '|kc' => '45 Kč', '|datum' => '26. 11. 2026',
                          '|datum:den' => 'čtvrtek 26. 11.', '|datum:dlouhe' => '26. listopadu 2026', '|odpocet' => 'za 5 dní / zítra / dnes', '|cisla:, ' => '22, 14, 27', '|velka' => 'VELKÁ', '|vychozi:—' => 'když hodnota chybí'],
        ];

    case 'nahled_textu':
        require_once __DIR__ . '/data.php';
        $txt = (string)($a['text'] ?? '');
        if (mb_strlen($txt) > 5000) throw new InvalidArgumentException('text: nejvýše 5 000 znaků.');
        $ts = time();
        if (!empty($a['cas'])) { $ts = strtotime((string)$a['cas']); if ($ts === false) throw new InvalidArgumentException('cas: neplatné datum (např. 2026-12-01 18:00).'); }
        [$res, $unk] = resolve_vars($txt, dt_from_ts($ts), ['index' => 0, 'user' => user_vars($pdo)]);
        return ['cas' => mcp_dt($ts), 'text' => $res, 'znaku' => mb_strlen($res)] + ($unk ? ['nezname_promenne' => $unk] : []);
    }
    throw new InvalidArgumentException("Neznámý nástroj $name.");
}

/**
 * Zpracuje jednu JSON-RPC zprávu. Vrací [HTTP status, odpověď] – odpověď null u notifikace.
 * $hdrVersion = hodnota hlavičky MCP-Protocol-Version (může být prázdná).
 */
function mcp_handle(PDO $pdo, array $grant, array $msg, string $hdrVersion = ''): array {
    $id = $msg['id'] ?? null;
    $method = (string)($msg['method'] ?? '');
    $isNotification = !array_key_exists('id', $msg);
    $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
    $metaVersion = (string)(($params['_meta'] ?? [])['io.modelcontextprotocol/protocolVersion'] ?? '');

    // Která generace protokolu: moderní 2026-07-28+ (bez initialize), nebo starší s handshakem.
    $modern = $method !== 'initialize' && ($metaVersion !== '' || $method === 'server/discover' || ($hdrVersion !== '' && $hdrVersion >= MCP_MODERN[0]));
    $complete = fn(array $r) => $modern ? ['resultType' => 'complete'] + $r : $r;
    $ok = fn($result) => [200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]];
    $fail = fn(int $http, int $code, string $m, $data = null) => [$http, ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $m] + ($data !== null ? ['data' => $data] : [])]];

    if (($msg['jsonrpc'] ?? '') !== '2.0' || $method === '') return $isNotification ? [202, null] : $fail(400, -32600, 'Invalid JSON-RPC request');

    if ($modern) {
        $ver = $metaVersion !== '' ? $metaVersion : $hdrVersion;
        if ($hdrVersion !== '' && $metaVersion !== '' && $hdrVersion !== $metaVersion)
            return $isNotification ? [400, null] : $fail(400, -32020, 'Header mismatch: MCP-Protocol-Version does not match _meta protocolVersion', ['header' => $hdrVersion, 'body' => $metaVersion]);
        if ($ver !== '' && !in_array($ver, MCP_MODERN, true))
            return $isNotification ? [400, null] : $fail(400, -32022, 'Unsupported protocol version', ['supported' => array_merge(MCP_MODERN, MCP_PROTOCOLS), 'requested' => $ver]);
    }
    if ($isNotification) return [202, null];   // notifications/initialized, cancelled …

    switch ($method) {
    case 'server/discover':
        return $ok([
            'resultType' => 'complete',
            'supportedVersions' => array_merge(MCP_MODERN, MCP_PROTOCOLS),
            'capabilities' => ['tools' => new stdClass()],
            'instructions' => mcp_instructions(),
            'ttlMs' => 3600000,
            'cacheScope' => 'private',
            '_meta' => ['io.modelcontextprotocol/serverInfo' => ['name' => 'social-planner', 'title' => 'Social Planner', 'version' => APP_VERSION]],
        ]);
    case 'initialize':
        $want = (string)($params['protocolVersion'] ?? '');
        return $ok([
            'protocolVersion' => in_array($want, MCP_PROTOCOLS, true) ? $want : MCP_PROTOCOLS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'social-planner', 'title' => 'Social Planner', 'version' => APP_VERSION],
            'instructions' => mcp_instructions(),
        ]);
    case 'ping':
        return $ok($modern ? ['resultType' => 'complete'] : new stdClass());
    case 'tools/list':
        return $ok($complete(['tools' => mcp_tools_list($grant)] + ($modern ? ['ttlMs' => 300000, 'cacheScope' => 'private'] : [])));
    case 'tools/call':
        $name = (string)($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        if (!isset(mcp_tool_defs()[$name])) return $fail($modern ? 400 : 200, -32602, "Unknown tool: $name");
        try {
            $data = mcp_call($pdo, $grant, $name, $args);
            if (mcp_tool_defs()[$name]['write']) log_line("MCP {$grant['client_name']}: $name");
            return $ok($complete(['content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]], 'structuredContent' => $data, 'isError' => false]));
        } catch (InvalidArgumentException $e) {
            return $ok($complete(['content' => [['type' => 'text', 'text' => 'Chyba: ' . $e->getMessage()]], 'isError' => true]));
        } catch (Throwable $e) {
            log_line("MCP chyba v $name: " . $e->getMessage());
            return $ok($complete(['content' => [['type' => 'text', 'text' => 'Chyba serveru při provádění nástroje.']], 'isError' => true]));
        }
    case 'resources/list':           return $ok($complete(['resources' => []]));
    case 'resources/templates/list': return $ok($complete(['resourceTemplates' => []]));
    case 'prompts/list':             return $ok($complete(['prompts' => []]));
    }
    // moderní klient pozná nepodporovanou metodu podle 404 (starý server by vracel jinak); starší klienti dostanou 200
    return $fail($modern ? 404 : 200, -32601, "Method not found: $method");
}
