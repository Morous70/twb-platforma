<?php
// Zmrazená kopie app/lib/data.php ze Social Planneru 1.9.2 (bez require_once, svátky z konstanty NAMEDAYS_SOUBOR). Needitovat.
declare(strict_types=1);
// Proměnné a data (od 1.5.0, převzato z TWB Plánovače médií): jeden společný modul pro text příspěvků (a později slidy).
//
// Zápis v textu příspěvku: {{nazev}}, {{nazev:argument}}, {{nazev|filtr|filtr:arg}}
//   kalendář   {{datum}} {{datum:+1}} {{den}} {{Den}} {{mesic}} {{rok}} {{tyden}} {{cas}} {{hodina}} {{svatek}} {{svatek:+1}}
//              {{odpocet:24.12.2026}} {{dnu:24.12.2026}}  (vše podle času zveřejnění příspěvku)
//   vlastní    {{tip_tydne}} (Proměnné: text nebo seznam), {{cislo}} = pořadí výskytu opakování
//   data       {{Sportka.nejbližší}} {{Sportka.nejbližší.parametr}} {{Sportka.další}} {{Sportka.poslední}} {{Sportka:26.11.2026}} {{Ceník:Káva}}
//   zdroje     {{Allwyn:sportka.superjackpot|mil}} {{Allwyn:sportka.tah1.2}} {{Ceník:Káva.Cena}} (CSV)
//   počasí     {{Počasí:teplota}} {{Počasí:zítra.max}} {{Počasí:Brno.popis}}
//   filtry     |mil |mld |tis |milionu |miliard |cislo[:0|:0,00] |kc |bezmezer |pripona:… |datum[:formát] |odpocet
//              |velka |mala |prvni |vychozi:… |cisla:…


const DT_DAYS = [1 => 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota', 'neděle'];
const DT_DAYS_SHORT = [1 => 'po', 'út', 'st', 'čt', 'pá', 'so', 'ne'];
const DT_MONTHS = [1 => 'leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];
const DT_MONTHS_GEN = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června', 'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];
/** Názvy vyhrazené pro kalendář (nesmí je mít vlastní proměnná, skupina dat ani zdroj). */
const CALENDAR_VARS = ['datum', 'den', 'mesic', 'rok', 'tyden', 'cas', 'hodina', 'cislodne', 'svatek', 'odpocet', 'dnu', 'cislo', 'pocasi'];

/** Malá písmena bez diakritiky (porovnávání názvů). */
function norm_key(string $s): string {
    $s = mb_strtolower(trim($s));
    return strtr($s, ['á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z']);
}

/** Čas zveřejnění jako DateTimeImmutable v časovém pásmu aplikace. */
function dt_from_ts(int $ts): DateTimeImmutable {
    return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone(date_default_timezone_get()));
}

function parse_any_date(string $s): ?DateTimeImmutable {
    $s = trim($s);
    // volitelný čas za datem (2026-11-26 20:00, 2026-11-26T20:00:00+01:00, 26. 11. 2026 20:00) se ignoruje
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T ]\d{1,2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/', $s, $m)
        || preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})(?:,?\s+\d{1,2}:\d{2}(?::\d{2})?)?$/', $s, $m)) {
        [$y, $mo, $d] = strlen($m[1]) === 4 ? [$m[1], $m[2], $m[3]] : [$m[3], $m[2], $m[1]];
        if (checkdate((int)$mo, (int)$d, (int)$y)) return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $mo, $d));
    }
    return null;
}
function cz_plural(int $n, string $one, string $few, string $many): string {
    return $n === 1 ? $one : (($n >= 2 && $n <= 4) ? $few : $many);
}
/** Datum z argumentu: „+1“, „-2“ (posun ve dnech) nebo prázdné = den zveřejnění. */
function var_date(string $arg, DateTimeImmutable $t): DateTimeImmutable {
    $arg = trim($arg);
    if ($arg !== '' && preg_match('/^[+-]\d+$/', $arg)) return $t->modify("$arg day");
    return $t;
}
function days_until(DateTimeImmutable $t, DateTimeImmutable $d): int {
    return (int)$t->setTime(0, 0)->diff(new DateTimeImmutable($d->format('Y-m-d'), $t->getTimezone()))->format('%r%a');
}
function countdown_text(int $days): string {
    return $days === 0 ? 'dnes' : ($days === 1 ? 'zítra' : ($days < 0 ? 'proběhlo' : "za $days " . cz_plural($days, 'den', 'dny', 'dní')));
}

// ---------- čísla ----------
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
/** Číslo s mezerami. $arg: prázdné = výchozí počet míst, „N“ = nejvýš N míst, „0,00“ = vždy přesně tolik míst. */
function num_fmt(float $n, string $arg, int $def): string {
    $arg = trim($arg);
    if (preg_match('/^0[,.](0+)$/', $arg, $m)) return number_format($n, strlen($m[1]), ',', ' ');
    $dec = preg_match('/^\d$/', $arg) ? (int)$arg : $def;
    $s = cz_num($n, $dec);
    return $s === '-0' ? '0' : $s;
}
/** Číslo se slovem ve správném tvaru: 1 milion, 2 miliony, 5 milionů, 1,5 milionu. */
function num_words(float $n, string $arg, int $def, array $forms): string {
    $s = num_fmt($n, $arg, $def);
    if (str_contains($s, ',')) return "$s {$forms[3]}";
    $i = (int)abs((float)str_replace(' ', '', $s));
    return "$s " . ($i === 1 ? $forms[0] : ($i >= 2 && $i <= 4 ? $forms[1] : $forms[2]));
}

// ---------- formát data ----------
const DATE_TOKEN_RE = '/RRRR|RR|MĚSÍCE|MESICE|MĚSÍC|MESIC|MM|M|DEN|DN|DD|D/u';
/**
 * Pojmenované formáty: kratke, bezmezer, nuly, den, den-plne, slovem, dlouhe, den-slovem, den-dlouhe, dentydne, mesic-rok.
 * Jinak vlastní formát ze zástupných znaků (velkými písmeny): D DD M MM RR RRRR MĚSÍC MĚSÍCE DEN DN.
 */
function fmt_date(DateTimeImmutable $d, string $fmt): string {
    $day = DT_DAYS[(int)$d->format('N')];
    $gen = DT_MONTHS_GEN[(int)$d->format('n')];
    $preset = $fmt !== '' && $fmt === mb_strtoupper($fmt) && preg_match(DATE_TOKEN_RE, $fmt) ? '-' : str_replace(['-', ' ', '_'], '', norm_key($fmt));
    switch ($preset) {
        case '': return $d->format('j. n. Y');
        case 'kratke': return $d->format('j. n.');
        case 'bezmezer': return $d->format('j.n.Y');
        case 'nuly': return $d->format('d.m.Y');
        case 'den': return $day . ' ' . $d->format('j. n.');
        case 'denplne': return $day . ' ' . $d->format('j. n. Y');
        case 'slovem': return $d->format('j. ') . $gen;
        case 'dlouhe': return $d->format('j. ') . $gen . $d->format(' Y');
        case 'denslovem': return $day . ' ' . $d->format('j. ') . $gen;
        case 'dendlouhe': return $day . ' ' . $d->format('j. ') . $gen . $d->format(' Y');
        case 'jendenvtydnu': case 'dentydne': return $day;
        case 'mesicrok': return DT_MONTHS[(int)$d->format('n')] . $d->format(' Y');
    }
    if (!preg_match(DATE_TOKEN_RE, $fmt)) return $d->format('j. n. Y');
    return preg_replace_callback(DATE_TOKEN_RE, fn($m) => match ($m[0]) {
        'RRRR' => $d->format('Y'), 'RR' => $d->format('y'),
        'MĚSÍCE', 'MESICE' => $gen, 'MĚSÍC', 'MESIC' => DT_MONTHS[(int)$d->format('n')],
        'MM' => $d->format('m'), 'M' => $d->format('n'),
        'DEN' => $day, 'DN' => DT_DAYS_SHORT[(int)$d->format('N')],
        'DD' => $d->format('d'), 'D' => $d->format('j'),
    }, $fmt);
}

function apply_filters(string $v, array $filters, ?DateTimeImmutable $t = null): string {
    foreach ($filters as $f) {
        [$name, $arg] = array_pad(explode(':', $f, 2), 2, '');
        $n = to_number($v);
        switch (norm_key($name)) {
            case 'velka': $v = mb_strtoupper($v); break;
            case 'mala': $v = mb_strtolower($v); break;
            case 'prvni': $v = mb_strtoupper(mb_substr($v, 0, 1)) . mb_substr($v, 1); break;
            case 'tis': if ($n !== null) $v = num_fmt($n / 1e3, $arg, 0) . ' tis.'; break;
            case 'mil': if ($n !== null) $v = num_fmt($n / 1e6, $arg, 1) . ' mil.'; break;
            case 'mld': if ($n !== null) $v = num_fmt($n / 1e9, $arg, 2) . ' mld.'; break;
            case 'milionu': if ($n !== null) $v = num_words($n / 1e6, $arg, 1, ['milion', 'miliony', 'milionů', 'milionu']); break;
            case 'miliard': if ($n !== null) $v = num_words($n / 1e9, $arg, 2, ['miliarda', 'miliardy', 'miliard', 'miliardy']); break;
            case 'cislo': if ($n !== null) $v = num_fmt($n, $arg, 2); break;
            case 'kc': if ($n !== null) $v = num_fmt($n, $arg, 2) . ' Kč'; break;
            case 'bezmezer': $v = preg_replace('/(?<=\d)[ \x{00A0}\x{202F}](?=\d)/u', '', $v); break;
            case 'pripona': $v .= $arg; break;
            case 'datum': if ($d = parse_any_date($v)) $v = fmt_date($d, $arg); break;
            case 'odpocet': if (($d = parse_any_date($v)) && $t) $v = countdown_text(days_until($t, $d)); break;
            case 'vychozi': if (trim($v) === '') $v = $arg; break;
            case 'cisla': $v = implode($arg !== '' ? $arg : ' ', preg_split('/\s+/u', trim($v), -1, PREG_SPLIT_NO_EMPTY)); break;
        }
    }
    return $v;
}

// ---------- tabulka dat: Skupina | Parametr | Hodnota ----------
function data_rows(bool $reload = false): array {
    static $rows = null;
    if ($rows === null || $reload) $rows = db()->query('SELECT id, grp, param, value FROM data_rows ORDER BY grp, id')->fetchAll();
    return $rows;
}
function data_group(string $grp): array {
    $k = norm_key($grp);
    return array_values(array_filter(data_rows(), fn($r) => norm_key($r['grp']) === $k));
}
function data_groups(): array {
    $g = [];
    foreach (data_rows() as $r) $g[$r['grp']] = true;
    return array_keys($g);
}
/** Hodnota z dat podle názvu („Skupina“, „Skupina.nejbližší[.parametr]“, „Skupina.další“, „Skupina.poslední“) a přesného parametru. */
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
        $today = new DateTimeImmutable($t->format('Y-m-d'));
        $best = null;
        foreach ($rows as $r) {
            $d = parse_any_date($r['param']);
            if (!$d) continue;
            if ($sel === 'posledni' ? $d < $today : $d >= $today) {
                if (!$best || ($sel === 'posledni' ? $d > $best[0] : $d < $best[0])) $best = [$d, $r];
            }
        }
        if ($sel === 'dalsi' && $best) {
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
/** Řádek k uložení: [skupina, parametr, hodnota]; čísla se uloží bez mezer. */
function data_clean_row($g, $p, $v): array {
    $g = mb_substr(trim((string)$g), 0, 100);
    $p = mb_substr(trim((string)$p), 0, 200);
    $v = trim((string)$v);
    if ($g === '') throw new InvalidArgumentException('chybí skupina');
    if ($p === '') throw new InvalidArgumentException('chybí parametr');
    if (preg_match('/[{}|:]/u', $g) || str_contains($g, '.')) throw new InvalidArgumentException('skupina nesmí obsahovat znaky { } | : a tečku');
    if (in_array(norm_key($g), CALENDAR_VARS, true)) throw new InvalidArgumentException("název „{$g}“ je vyhrazený pro kalendář");
    if (preg_match('/^-?[\d\s\x{00A0}]+([.,]\d+)?$/u', $v) && ($n = to_number($v)) !== null) {
        $v = rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
    return [$g, $p, mb_substr($v, 0, 2000)];
}

// ---------- vyhodnocení proměnné ----------
/**
 * Hodnota jedné proměnné, nebo null, pokud ji neznáme.
 * $ctx: ['index' => pořadí výskytu opakování (0 = první), 'user' => vlastní proměnné (user_vars)]
 */
function var_value(string $name, string $arg, DateTimeImmutable $t, array $ctx = []): ?string {
    static $names = null;
    $k = norm_key($name);
    switch ($k) {
        case 'datum': return var_date($arg, $t)->format('j. n. Y');
        case 'den': $v = DT_DAYS[(int)var_date($arg, $t)->format('N')]; return $name === 'Den' ? mb_strtoupper(mb_substr($v, 0, 1)) . mb_substr($v, 1) : $v;
        case 'mesic': return DT_MONTHS[(int)var_date($arg, $t)->format('n')];
        case 'rok': return var_date($arg, $t)->format('Y');
        case 'tyden': return (string)(int)var_date($arg, $t)->format('W');
        case 'cas': return $t->format('G:i');
        case 'hodina': return $t->format('G');
        case 'cislodne': return $t->format('N');
        case 'cislo': return (string)((int)($ctx['index'] ?? 0) + 1);
        case 'svatek':
            $names ??= require NAMEDAYS_SOUBOR;
            return $names[var_date($arg, $t)->format('m-d')] ?? '';
        case 'odpocet':
            $target = parse_any_date($arg);
            return $target ? countdown_text(days_until($t, $target)) : null;
        case 'dnu':
            $target = parse_any_date($arg);
            return $target ? (string)max(0, days_until($t, $target)) : null;
    }
    // vlastní proměnná (Proměnné): {{tip_tydne}}
    $user = $ctx['user'] ?? [];
    if (trim($arg) === '' && !str_contains($name, '.') && isset($user[mb_strtolower($name)])) {
        $u = $user[mb_strtolower($name)];
        if ($u['type'] === 'list') {
            $items = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)$u['value'])), fn($x) => $x !== ''));
            return $items ? $items[(int)($ctx['index'] ?? 0) % count($items)] : '';
        }
        return (string)$u['value'];
    }
    // vzdálené zdroje {{Zdroj:cesta}}, počasí {{Počasí:…}}, pak tabulka dat
    if (trim($arg) !== '' && source_by_name($name)) return source_value($name, $arg);
    if ($k === 'pocasi') return weather_value($arg, $t);
    return data_value($name, $arg, $t);
}

const VAR_RE = '/\{\{\s*([^{}|:]+?)\s*(?::([^{}|]*))?((?:\|[^{}|]+)*)\}\}/u';
/** Ve slidech: {x} jako v Plánovači médií i {{x}} jako v textu příspěvku. */
const VAR_RE_SLIDE = '/\{(?:\{(?=[^{}]*\}\}))?\s*([^{}|:]+?)\s*(?::([^{}|]*))?((?:\|[^{}|]+)*)\}\}?/u';

/** Nahradí proměnné v textu. Vrací [text, neznámé proměnné (celý zápis)]. Neznámé zůstanou beze změny. $slide = i {x}. */
function resolve_vars(string $text, DateTimeImmutable $t, array $ctx = [], bool $slide = false): array {
    if (!str_contains($text, $slide ? '{' : '{{')) return [$text, []];
    $unknown = [];
    $out = preg_replace_callback($slide ? VAR_RE_SLIDE : VAR_RE, function ($m) use ($t, $ctx, &$unknown) {
        $filters = array_values(array_filter(explode('|', $m[3] ?? ''), fn($x) => $x !== ''));
        $v = var_value(trim($m[1]), trim($m[2] ?? ''), $t, $ctx);
        if ($v === null) {
            foreach ($filters as $f) if (norm_key(explode(':', $f, 2)[0]) === 'vychozi') return explode(':', $f, 2)[1] ?? '';
            $unknown[] = $m[0];
            return $m[0];
        }
        return apply_filters($v, $filters, $t);
    }, $text);
    return [$out, array_values(array_unique($unknown))];
}

// ---------- podmínky (slidy: „Zobrazit jen když“, varianty textu) ----------
/** Strana podmínky: text s proměnnými, nebo samotný název proměnné (den, Sportka.nejbližší, Allwyn:sportka.jackpot). */
function cond_operand(string $s, DateTimeImmutable $t): string {
    $s = trim($s);
    if ($s === '') return '';
    if (str_contains($s, '{')) return resolve_vars($s, $t, ['user' => user_vars(db())], true)[0];
    if (preg_match('/^([^:|]+)(?::([^|]*))?$/u', $s, $m)) {
        $v = var_value(trim($m[1]), $m[2] ?? '', $t, ['user' => user_vars(db())]);
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
