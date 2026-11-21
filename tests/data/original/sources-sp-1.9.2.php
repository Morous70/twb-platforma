<?php
declare(strict_types=1);
// Zmrazená kopie app/lib/sources.php ze Social Planneru 1.9.2 (source_http jen pokud ji harness nedefinuje). Needitovat.
// Vzdálené zdroje dat (JSON nebo CSV), převzato z TWB Plánovače médií. Cron je stahuje podle intervalu, texty z nich čtou hodnoty:
//   {{Allwyn:sportka.superjackpot}}  hra se najde podle game / code / label, mezilehlé úrovně (draw, data) lze vynechat
//   {{Allwyn:sportka.tah1}}          seznam čísel → „22 14 27 42 9 5“;  {{Allwyn:sportka.tah1.2}} = druhé číslo
//   CSV: {{Ceník:Káva.Cena}}         řádek podle hodnoty v 1. sloupci, sloupec podle záhlaví; {{Ceník:3.Cena}} = 3. řádek
// Při výpadku zůstávají poslední stažená data; do logu jde jedno varování a po obnovení zpráva o obnovení.

if (!function_exists('array_is_list')) { // PHP 8.0
    function array_is_list(array $a): bool { $i = 0; foreach ($a as $k => $_) if ($k !== $i++) return false; return true; }
}

/** Všechny zdroje včetně dekódovaných dat. */
function sources_all(bool $reload = false): array {
    static $list = null;
    if ($list === null || $reload) {
        $list = db()->query('SELECT * FROM sources ORDER BY name COLLATE NOCASE')->fetchAll();
        foreach ($list as &$s) $s['json'] = $s['payload'] !== null && $s['payload'] !== '' ? json_decode($s['payload'], true) : null;
        unset($s);
    }
    return $list;
}

function source_by_name(string $name): ?array {
    $k = norm_key($name);
    foreach (sources_all() as $s) if (norm_key($s['name']) === $k) return $s;
    return null;
}

/** Adresa bez hodnot parametrů, které vypadají jako klíč (do logu a chybových hlášek). */
function source_safe_url(string $url): string {
    return preg_replace('/([?&](?:key|apikey|api_key|appid|token|access_token|secret)=)[^&#]+/i', '$1…', $url);
}

/** Stáhne zdroj a uloží data. Vrací ['ok' => bool, 'changed' => bool, 'error' => ?string]. */
function source_fetch(array $s): array {
    $pdo = db();
    $now = time();
    $error = null; $data = null;
    try {
        $csv = ($s['format'] ?? 'json') === 'csv';
        [$code, $body] = source_http($csv ? csv_url($s['url']) : $s['url'], $csv);
        if ($csv) {
            if ($code >= 400) throw new RuntimeException("HTTP $code");
            $data = csv_to_rows($body, (bool)($s['csv_header'] ?? 1));
        } else {
            $data = json_decode($body, true);
            if ($code >= 400) {
                $msg = is_array($data) && isset($data['error']) && is_string($data['error']) ? $data['error'] : mb_substr(trim(strip_tags($body)), 0, 150);
                throw new RuntimeException("HTTP $code" . ($msg !== '' ? ": $msg" : ''));
            }
            if (!is_array($data)) throw new RuntimeException('Odpověď není platný JSON.');
            if (array_key_exists('success', $data) && $data['success'] === false) {
                throw new RuntimeException('Zdroj hlásí chybu: ' . (is_string($data['error'] ?? null) ? $data['error'] : 'bez popisu'));
            }
        }
    } catch (Throwable $e) {
        $error = mb_substr($e->getMessage(), 0, 500);
    }
    if ($error !== null) {
        $pdo->prepare('UPDATE sources SET fetched_at = ?, error = ? WHERE id = ?')->execute([$now, $error, (int)$s['id']]);
        // do logu jen při přechodu do chyby (ne při každém pokusu)
        if (empty($s['error'])) log_line("Zdroj „{$s['name']}“ se nepodařilo stáhnout: $error. Příspěvky používají poslední stažená data.");
        sources_all(true);
        return ['ok' => false, 'changed' => false, 'error' => $error];
    }
    $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $hash = md5($payload);
    $changed = $hash !== (string)$s['hash'];
    $pdo->prepare('UPDATE sources SET fetched_at = ?, ok_at = ?, error = NULL, payload = ?, hash = ? WHERE id = ?')->execute([$now, $now, $payload, $hash, (int)$s['id']]);
    if (!empty($s['error'])) log_line("Zdroj „{$s['name']}“ je opět dostupný.");
    sources_all(true);
    return ['ok' => true, 'changed' => $changed, 'error' => null];
}

if (!function_exists('source_http')) { function source_http(string $url, bool $csv): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [$csv ? 'Accept: text/csv, text/plain, */*' : 'Accept: application/json'],
        CURLOPT_USERAGENT => 'SocialPlanner/' . APP_VERSION,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    if ($body === false) throw new RuntimeException('Zdroj neodpovídá: ' . source_safe_url($cerr));
    return [$code, (string)$body];
} }

/** Stáhne zdroje, kterým uplynul interval. Volá cron. Vrací true, když se některá data změnila. */
function sources_refresh_due(): bool {
    $changed = false;
    foreach (sources_all(true) as $s) {
        if (time() - (int)$s['fetched_at'] < max(1, (int)$s['interval_min']) * 60 - 30) continue;
        $r = source_fetch($s);
        $changed = $changed || $r['changed'];
    }
    return $changed;
}

// ---------- CSV ----------
/** Adresa tabulky Google (…/spreadsheets/d/ID/edit#gid=N) → adresa exportu do CSV. Ostatní adresy beze změny. */
function csv_url(string $url): string {
    if (preg_match('~^https://docs\.google\.com/spreadsheets/d/([A-Za-z0-9_-]+)~', $url, $m) && !preg_match('~/(export|pub)\b|output=csv|format=csv~', $url)) {
        $gid = preg_match('~[#&?]gid=(\d+)~', $url, $g) ? $g[1] : '0';
        return "https://docs.google.com/spreadsheets/d/{$m[1]}/export?format=csv&gid=$gid";
    }
    return $url;
}

/**
 * CSV → seznam řádků [_row => hodnota 1. sloupce, záhlaví => hodnota, …].
 * Kódování UTF-8 nebo Windows-1250 (Excel), oddělovač ; , tabulátor nebo | se pozná sám.
 */
function csv_to_rows(string $body, bool $header): array {
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
    if (!mb_check_encoding($body, 'UTF-8')) {
        $conv = function_exists('iconv') ? @iconv('Windows-1250', 'UTF-8//TRANSLIT', $body) : false;
        $body = $conv !== false ? $conv : mb_convert_encoding($body, 'UTF-8', 'ISO-8859-2');
    }
    if (trim($body) === '') throw new RuntimeException('Soubor CSV je prázdný.');
    if (preg_match('/^\s*(<!doctype|<html)/i', $body)) throw new RuntimeException('Adresa nevrací CSV, ale webovou stránku (u tabulky Google zkontrolujte sdílení „Kdokoli s odkazem“).');
    // oddělovač: nejčastější znak v prvním řádku (mimo uvozovky)
    $first = preg_replace('/"[^"]*"/', '', strtok($body, "\n"));
    $delim = ';'; $best = -1;
    foreach ([';', ',', "\t", '|'] as $d) { $n = substr_count($first, $d); if ($n > $best) { $best = $n; $delim = $d; } }

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $body);
    rewind($fh);
    $lines = [];
    while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        if ($r === [null] || !array_filter($r, fn($x) => trim((string)$x) !== '')) continue; // prázdný řádek
        $lines[] = array_map(fn($x) => trim((string)$x), $r);
        if (count($lines) > 5001) break;
    }
    fclose($fh);
    if (!$lines) throw new RuntimeException('Soubor CSV neobsahuje žádná data.');

    $width = min(100, max(array_map('count', $lines)));
    if ($header) {
        $names = [];
        foreach (array_pad(array_slice(array_shift($lines), 0, $width), $width, '') as $i => $h) {
            $h = preg_replace('/[.{}|:]+/u', ' ', $h);
            $h = trim($h) !== '' ? trim($h) : 'Sloupec ' . ($i + 1);
            $base = $h; $n = 2;
            while (in_array(norm_key($h), array_map('norm_key', $names), true)) $h = $base . ' ' . $n++;
            $names[] = $h;
        }
    } else {
        $names = array_map('strval', range(1, $width));
    }
    $rows = [];
    foreach (array_slice($lines, 0, 5000) as $line) {
        $row = ['_row' => $line[0] ?? ''];
        foreach ($names as $i => $n) $row[$n] = $line[$i] ?? '';
        $rows[] = $row;
    }
    if (!$rows) throw new RuntimeException('Soubor CSV obsahuje jen záhlaví, žádné řádky s daty.');
    return $rows;
}

// ---------- hledání hodnoty podle cesty ----------
const SRC_ID_KEYS = ['_row', 'game', 'code', 'key', 'slug', 'name', 'label', 'title', 'id'];

function src_is_list($v): bool { return is_array($v) && array_is_list($v); }

/** Uzly, na které ukazuje segment cesty po přeskočení přesně $skip úrovní objektu. */
function src_step($node, string $seg, int $skip, array $path): array {
    if (!is_array($node)) return [];
    $out = [];
    if ($skip === 0) {
        if (src_is_list($node)) {
            if (ctype_digit($seg) && (int)$seg >= 1 && (int)$seg <= count($node)) {
                $out[] = [$node[(int)$seg - 1], [...$path, (int)$seg - 1]];
            } else {
                $k = norm_key($seg);
                foreach ($node as $i => $el) {
                    if (!is_array($el) || src_is_list($el)) continue;
                    foreach (SRC_ID_KEYS as $f) {
                        if (isset($el[$f]) && is_scalar($el[$f]) && norm_key((string)$el[$f]) === $k) { $out[] = [$el, [...$path, $i]]; break; }
                    }
                }
            }
        } else {
            // klíč v podobě, v jaké ho PHP uložilo („2“ → 2), aby cesta odpovídala cestě ve stromu
            if (array_key_exists($seg, $node)) $out[] = [$node[$seg], [...$path, array_key_first([$seg => 0])]];
            else {
                $k = norm_key($seg);
                foreach ($node as $key => $v) if (norm_key((string)$key) === $k) { $out[] = [$v, [...$path, $key]]; break; }
            }
        }
        return $out;
    }
    if (src_is_list($node)) return []; // seznamem se neprochází bez výběru prvku
    foreach ($node as $key => $child) {
        if (is_array($child)) array_push($out, ...src_step($child, $seg, $skip - 1, [...$path, $key]));
    }
    return $out;
}

/** Najde uzel podle segmentů. Vrací [hodnota, úplná cesta] nebo null. Přednost má nejmělčí shoda. */
function src_find($node, array $segs, int $i = 0, array $path = []): ?array {
    if ($i === count($segs)) return [$node, $path];
    for ($skip = 0; $skip <= 3; $skip++) {
        foreach (src_step($node, $segs[$i], $skip, $path) as [$child, $cpath]) {
            $r = src_find($child, $segs, $i + 1, $cpath);
            if ($r) return $r;
        }
    }
    return null;
}

function src_scalar($v): string {
    if ($v === null) return '';
    if (is_bool($v)) return $v ? 'ano' : 'ne';
    if (is_float($v)) return floor($v) == $v && abs($v) < 1e15 ? number_format($v, 0, '.', '') : rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
    return (string)$v;
}

/** Hodnota jako text: číslo/text, seznam hodnot spojený mezerou; objekt → null. */
function src_to_string($v): ?string {
    if (!is_array($v)) return src_scalar($v);
    if (src_is_list($v) && !array_filter($v, 'is_array')) return implode(' ', array_map('src_scalar', $v));
    return null;
}

/** Hodnota ze zdroje pro proměnnou {Zdroj:cesta}, nebo null. */
function source_value(string $name, string $path): ?string {
    $s = source_by_name($name);
    if (!$s) return null;
    if ($s['json'] === null) return ''; // zatím nestaženo
    $segs = array_values(array_filter(array_map('trim', explode('.', $path)), fn($x) => $x !== ''));
    if (!$segs) return null;
    $r = src_find($s['json'], $segs);
    return $r ? src_to_string($r[0]) : null;
}

// ---------- strom pro výběr v editoru ----------
const SRC_LABELS = [
    'tah1' => '1. tah', 'tah1_d' => 'dodatkové číslo 1. tahu', 'tah2' => '2. tah', 'tah2_d' => 'dodatkové číslo 2. tahu',
    'sance' => 'Šance', 'superjackpot' => 'superjackpot – příští losování', 'jackpot' => 'jackpot – příští losování',
    'osudi1' => '1. osudí', 'osudi2' => '2. osudí', 'extra6' => 'Extra 6', 'cisla' => 'tažená čísla', 'extra_cisla' => 'extra čísla',
    'dodatkove_cislo' => 'dodatkové číslo', 'eurosance' => 'Eurošance', 'sance_milion' => 'Šance milion',
    'den_cislo' => 'den', 'mesic_cislo' => 'měsíc', 'rok_cislo' => 'rok', 'cas' => 'čas losování',
    'datum' => 'datum losování', 'draw_label' => 'popisek losování (polední / večerní)', 'draw_id' => 'číslo losování',
    'tyden' => 'týden', 'rok' => 'rok', 'den' => 'den v týdnu (číslo)', 'label' => 'název hry',
];
const SRC_TECH_KEYS = ['_row', 'id', 'game', 'code', 'draw_order', 'success', 'meta', 'count'];

/** Krátká cesta: prvky seznamu podle identifikátoru, vynechané mezilehlé úrovně (když vedou na totéž). */
function src_short_path($root, array $full): array {
    $segs = []; $node = $root;
    foreach ($full as $k) {
        if (src_is_list($node)) {
            $el = $node[$k];
            $id = null;
            if (is_array($el) && !src_is_list($el)) {
                foreach (SRC_ID_KEYS as $f) {
                    if (isset($el[$f]) && is_scalar($el[$f]) && !preg_match('/[.{}|:]/u', (string)$el[$f]) && !ctype_digit((string)$el[$f]) && trim((string)$el[$f]) !== '') { $id = (string)$el[$f]; break; }
                }
            }
            $segs[] = ['s' => $id ?? (string)($k + 1), 'opt' => false];
        } else {
            $segs[] = ['s' => (string)$k, 'opt' => true];
        }
        $node = $node[$k];
    }
    $segs[count($segs) - 1]['opt'] = false;
    $keep = array_keys($segs);
    $pick = fn(array $idx) => array_map(fn($i) => $segs[$i]['s'], $idx);
    // zkusit vynechat úrovně objektů, pokud cesta pořád vede na stejné místo
    foreach ($segs as $idx => $sg) {
        if (!$sg['opt']) continue;
        $try = array_values(array_diff($keep, [$idx]));
        $r = $try ? src_find($root, $pick($try)) : null;
        if ($r && $r[1] === $full) $keep = $try;
    }
    $cur = $pick($keep);
    $r = src_find($root, $cur);
    return ($r && $r[1] === $full) ? $cur : array_map('strval', array_map(fn($k) => is_int($k) ? $k + 1 : $k, $full));
}

/** Listy stromu (hodnoty a seznamy hodnot) seskupené podle první úrovně. */
function source_tree(array $s): array {
    $root = $s['json'];
    if (!is_array($root)) return [];
    $leaves = [];
    $walk = function ($node, array $path, string $group) use (&$walk, &$leaves, $root) {
        $str = src_to_string($node);
        if ($str !== null && $path) {
            $last = (string)end($path);
            $leaves[] = ['group' => $group, 'full' => $path, 'key' => $last, 'value' => $str, 'list' => is_array($node)];
            return; // seznam čísel se nabízí celý
        }
        if (!is_array($node)) return;
        foreach ($node as $k => $v) {
            $g = $group;
            if (src_is_list($node) && is_array($v) && !src_is_list($v)) {
                $g = (string)(($v['_row'] ?? '') !== '' ? $v['_row'] : ($v['label'] ?? $v['name'] ?? $v['title'] ?? $v['game'] ?? ($k + 1)));
                if (isset($v['_row'])) $g = ($k + 1) . '. ' . $g; // CSV: číslo řádku + 1. sloupec
            }
            $walk($v, [...$path, $k], $g);
        }
    };
    $walk($root, [], '');
    $groups = [];
    foreach ($leaves as $l) {
        $short = implode('.', src_short_path($root, $l['full']));
        $tech = in_array($l['key'], SRC_TECH_KEYS, true) || in_array('meta', array_map('strval', $l['full']), true);
        $kind = $l['list'] ? 'list' : (parse_any_date($l['value']) ? 'date' : (to_number($l['value']) !== null && preg_match('/^-?\d+(?:[.,]\d+)?$/', $l['value']) ? 'number' : 'text'));
        $groups[$l['group']][] = ['path' => $short, 'key' => $l['key'], 'label' => SRC_LABELS[$l['key']] ?? $l['key'],
            'value' => mb_substr($l['value'], 0, 300), 'kind' => $kind, 'tech' => $tech];
    }
    $out = [];
    foreach ($groups as $g => $items) $out[] = ['title' => $g === '' ? 'Hodnoty' : $g, 'items' => $items];
    return $out;
}
