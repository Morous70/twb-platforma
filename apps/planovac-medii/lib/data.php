<?php
// Sekce Data: řádky Skupina | Parametr | Hodnota a jejich použití v textech slidů.
//   {Sportka.nejbližší}            hodnota řádku s nejbližším datem ≥ dnes
//   {Sportka.nejbližší.parametr}   jeho parametr (datum)
//   {Sportka.poslední}             řádek s posledním datem < dnes
//   {Sportka:26.11.2026}           hodnota s přesně tímto parametrem
//   {Ceník:Káva}                   totéž pro textový parametr
//   {Ceník}                        jediná hodnota skupiny (má-li skupina jen jeden řádek)

function data_rows(bool $reload = false): array {
    static $rows = null;
    if ($rows === null || $reload) {
        try { $rows = q('SELECT id, grp, param, value FROM data_rows ORDER BY grp, id')->fetchAll(); }
        catch (Throwable $e) { $rows = []; } // před převodem databáze
    }
    return $rows;
}

function norm_key(string $s): string {
    $s = mb_strtolower(trim($s));
    return strtr($s, ['á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z']);
}

/** Řádky skupiny (název bez ohledu na velikost písmen a diakritiku). */
function data_group(string $grp): array {
    $k = norm_key($grp);
    return array_values(array_filter(data_rows(), fn($r) => norm_key($r['grp']) === $k));
}

function data_groups(): array {
    $g = [];
    foreach (data_rows() as $r) $g[$r['grp']] = true;
    return array_keys($g);
}

/**
 * Hodnota z dat podle názvu proměnné, nebo null.
 * $name = "Skupina", "Skupina.nejbližší", "Skupina.nejbližší.parametr", $arg = přesný parametr.
 */
function data_value(string $name, string $arg, DateTimeImmutable $t): ?string {
    $parts = array_map('trim', explode('.', $name));
    $rows = data_group($parts[0]);
    if (!$rows) return null;
    $sel = norm_key($parts[1] ?? '');
    $field = norm_key($parts[2] ?? 'hodnota');
    $pick = null;

    if (trim($arg) !== '') {
        $ad = parse_any_date($arg);
        foreach ($rows as $r) {
            $rd = parse_any_date($r['param']);
            if (($ad && $rd && $ad == $rd) || norm_key($r['param']) === norm_key($arg)) { $pick = $r; break; }
        }
    } elseif ($sel === 'nejblizsi' || $sel === 'posledni' || $sel === 'dalsi') {
        $today = $t->setTime(0, 0);
        $best = null;
        foreach ($rows as $r) {
            $d = parse_any_date($r['param']);
            if (!$d) continue;
            if ($sel === 'posledni' ? $d < $today : $d >= $today) {
                if (!$best || ($sel === 'posledni' ? $d > $best[0] : $d < $best[0])) $best = [$d, $r];
            }
        }
        if ($sel === 'dalsi' && $best) {
            // druhé nejbližší (např. „příští losování po dnešním“)
            $after = null;
            foreach ($rows as $r) {
                $d = parse_any_date($r['param']);
                if ($d && $d > $best[0] && (!$after || $d < $after[0])) $after = [$d, $r];
            }
            $best = $after;
        }
        $pick = $best[1] ?? null;
        if (!$pick) return '';
    } elseif ($sel === '') {
        if (count($rows) === 1) $pick = $rows[0];
        else return null;
    } else {
        return null;
    }
    if (!$pick) return '';
    return in_array($field, ['parametr', 'param', 'datum'], true) ? $pick['param'] : $pick['value'];
}

// ---------- formátování čísel ----------
/** Převede „125 000 000“, „1,5“ apod. na číslo, jinak null. */
function to_number(string $s): ?float {
    $c = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($s));
    $c = str_replace(',', '.', $c);
    return is_numeric($c) ? (float)$c : null;
}
function cz_num(float $n, int $maxDec = 2): string {
    $s = number_format($n, $maxDec, ',', ' ');
    if ($maxDec > 0) $s = rtrim(rtrim($s, '0'), ',');
    return $s;
}

// ---------- podmínky ----------
/** Hodnota jedné strany podmínky: název proměnné (bez závorek), nebo text s {proměnnými}, nebo přímo hodnota. */
function cond_operand(string $s, DateTimeImmutable $t): string {
    $s = trim($s);
    if ($s === '') return '';
    if (str_contains($s, '{')) return resolve_text($s, $t)[0];
    if (preg_match('/^([^:|]+)(?::([^|]*))?$/u', $s, $m)) {
        $v = var_value(trim($m[1]), $m[2] ?? '', $t);
        if ($v !== null) return $v;
    }
    return $s;
}

function cond_compare(string $a, string $op, string $b): bool {
    if ($op === 'empty') return trim($a) === '';
    if ($op === 'notempty') return trim($a) !== '';
    if ($op === 'contains') return mb_stripos($a, $b) !== false;
    $na = to_number($a); $nb = to_number($b);
    $da = parse_any_date($a); $db = parse_any_date($b);
    if ($na !== null && $nb !== null) { $x = $na; $y = $nb; }
    elseif ($da && $db) { $x = $da->getTimestamp(); $y = $db->getTimestamp(); }
    else { $x = mb_strtolower(trim($a)); $y = mb_strtolower(trim($b)); }
    return match ($op) {
        '=' => $x == $y, '!=' => $x != $y, '>' => $x > $y, '<' => $x < $y, '>=' => $x >= $y, '<=' => $x <= $y,
        default => false,
    };
}

/** Platí všechny podmínky? (prázdný seznam = ano) */
function conds_ok(array $clauses, DateTimeImmutable $t): bool {
    foreach ($clauses as $c) {
        if (trim($c['l'] ?? '') === '') continue;
        if (!cond_compare(cond_operand($c['l'], $t), $c['op'] ?? '=', cond_operand($c['r'] ?? '', $t))) return false;
    }
    return true;
}
