<?php
declare(strict_types=1);
namespace TWB\Data;

use RuntimeException;

/**
 * Čtení hodnot z dat vzdálených zdrojů: CSV → řádky, hledání podle cesty (Zdroj:hra.tah1.2), strom pro výběr v editoru.
 * Čistá logika bez databáze a sítě.
 */
final class Hledani
{
    // ---------- CSV ----------
    /** Adresa tabulky Google (…/spreadsheets/d/ID/edit#gid=N) → adresa exportu do CSV. Ostatní adresy beze změny. */
    public static function csvUrl(string $url): string {
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
    public static function csvNaRadky(string $body, bool $header): array {
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
                while (in_array(Format::klic($h), array_map([Format::class, 'klic'], $names), true)) $h = $base . ' ' . $n++;
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
    private const ID_KLICE = ['_row', 'game', 'code', 'key', 'slug', 'name', 'label', 'title', 'id'];

    public static function jeSeznam($v): bool { return is_array($v) && array_is_list($v); }

    /** Uzly, na které ukazuje segment cesty po přeskočení přesně $skip úrovní objektu. */
    public static function krok($node, string $seg, int $skip, array $path): array {
        if (!is_array($node)) return [];
        $out = [];
        if ($skip === 0) {
            if (self::jeSeznam($node)) {
                if (ctype_digit($seg) && (int)$seg >= 1 && (int)$seg <= count($node)) {
                    $out[] = [$node[(int)$seg - 1], [...$path, (int)$seg - 1]];
                } else {
                    $k = Format::klic($seg);
                    foreach ($node as $i => $el) {
                        if (!is_array($el) || self::jeSeznam($el)) continue;
                        foreach (self::ID_KLICE as $f) {
                            if (isset($el[$f]) && is_scalar($el[$f]) && Format::klic((string)$el[$f]) === $k) { $out[] = [$el, [...$path, $i]]; break; }
                        }
                    }
                }
            } else {
                // klíč v podobě, v jaké ho PHP uložilo („2“ → 2), aby cesta odpovídala cestě ve stromu
                if (array_key_exists($seg, $node)) $out[] = [$node[$seg], [...$path, array_key_first([$seg => 0])]];
                else {
                    $k = Format::klic($seg);
                    foreach ($node as $key => $v) if (Format::klic((string)$key) === $k) { $out[] = [$v, [...$path, $key]]; break; }
                }
            }
            return $out;
        }
        if (self::jeSeznam($node)) return []; // seznamem se neprochází bez výběru prvku
        foreach ($node as $key => $child) {
            if (is_array($child)) array_push($out, ...self::krok($child, $seg, $skip - 1, [...$path, $key]));
        }
        return $out;
    }

    /** Najde uzel podle segmentů. Vrací [hodnota, úplná cesta] nebo null. Přednost má nejmělčí shoda. */
    public static function najdi($node, array $segs, int $i = 0, array $path = []): ?array {
        if ($i === count($segs)) return [$node, $path];
        for ($skip = 0; $skip <= 3; $skip++) {
            foreach (self::krok($node, $segs[$i], $skip, $path) as [$child, $cpath]) {
                $r = self::najdi($child, $segs, $i + 1, $cpath);
                if ($r) return $r;
            }
        }
        return null;
    }

    public static function skalar($v): string {
        if ($v === null) return '';
        if (is_bool($v)) return $v ? 'ano' : 'ne';
        if (is_float($v)) return floor($v) == $v && abs($v) < 1e15 ? number_format($v, 0, '.', '') : rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
        return (string)$v;
    }

    /** Hodnota jako text: číslo/text, seznam hodnot spojený mezerou; objekt → null. */
    public static function naText($v): ?string {
        if (!is_array($v)) return self::skalar($v);
        if (self::jeSeznam($v) && !array_filter($v, 'is_array')) return implode(' ', array_map([self::class,'skalar'], $v));
        return null;
    }

    // ---------- strom pro výběr v editoru ----------
    private const POPISKY = [
        'tah1' => '1. tah', 'tah1_d' => 'dodatkové číslo 1. tahu', 'tah2' => '2. tah', 'tah2_d' => 'dodatkové číslo 2. tahu',
        'sance' => 'Šance', 'superjackpot' => 'superjackpot – příští losování', 'jackpot' => 'jackpot – příští losování',
        'osudi1' => '1. osudí', 'osudi2' => '2. osudí', 'extra6' => 'Extra 6', 'cisla' => 'tažená čísla', 'extra_cisla' => 'extra čísla',
        'dodatkove_cislo' => 'dodatkové číslo', 'eurosance' => 'Eurošance', 'sance_milion' => 'Šance milion',
        'den_cislo' => 'den', 'mesic_cislo' => 'měsíc', 'rok_cislo' => 'rok', 'cas' => 'čas losování',
        'datum' => 'datum losování', 'draw_label' => 'popisek losování (polední / večerní)', 'draw_id' => 'číslo losování',
        'tyden' => 'týden', 'rok' => 'rok', 'den' => 'den v týdnu (číslo)', 'label' => 'název hry',
    ];
    private const TECHNICKE = ['_row', 'id', 'game', 'code', 'draw_order', 'success', 'meta', 'count'];

    /** Krátká cesta: prvky seznamu podle identifikátoru, vynechané mezilehlé úrovně (když vedou na totéž). */
    public static function kratkaCesta($root, array $full): array {
        $segs = []; $node = $root;
        foreach ($full as $k) {
            if (self::jeSeznam($node)) {
                $el = $node[$k];
                $id = null;
                if (is_array($el) && !self::jeSeznam($el)) {
                    foreach (self::ID_KLICE as $f) {
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
            $r = $try ? self::najdi($root, $pick($try)) : null;
            if ($r && $r[1] === $full) $keep = $try;
        }
        $cur = $pick($keep);
        $r = self::najdi($root, $cur);
        return ($r && $r[1] === $full) ? $cur : array_map('strval', array_map(fn($k) => is_int($k) ? $k + 1 : $k, $full));
    }

    /** Listy stromu (hodnoty a seznamy hodnot) seskupené podle první úrovně. */
    public static function strom(mixed $root): array {
        if (!is_array($root)) return [];
        $leaves = [];
        $walk = function ($node, array $path, string $group) use (&$walk, &$leaves, $root) {
            $str = self::naText($node);
            if ($str !== null && $path) {
                $last = (string)end($path);
                $leaves[] = ['group' => $group, 'full' => $path, 'key' => $last, 'value' => $str, 'list' => is_array($node)];
                return; // seznam čísel se nabízí celý
            }
            if (!is_array($node)) return;
            foreach ($node as $k => $v) {
                $g = $group;
                if (self::jeSeznam($node) && is_array($v) && !self::jeSeznam($v)) {
                    $g = (string)(($v['_row'] ?? '') !== '' ? $v['_row'] : ($v['label'] ?? $v['name'] ?? $v['title'] ?? $v['game'] ?? ($k + 1)));
                    if (isset($v['_row'])) $g = ($k + 1) . '. ' . $g; // CSV: číslo řádku + 1. sloupec
                }
                $walk($v, [...$path, $k], $g);
            }
        };
        $walk($root, [], '');
        $groups = [];
        foreach ($leaves as $l) {
            $short = implode('.', self::kratkaCesta($root, $l['full']));
            $tech = in_array($l['key'], self::TECHNICKE, true) || in_array('meta', array_map('strval', $l['full']), true);
            $kind = $l['list'] ? 'list' : (Format::datumZTextu($l['value']) ? 'date' : (Format::naCislo($l['value']) !== null && preg_match('/^-?\d+(?:[.,]\d+)?$/', $l['value']) ? 'number' : 'text'));
            $groups[$l['group']][] = ['path' => $short, 'key' => $l['key'], 'label' => self::POPISKY[$l['key']] ?? $l['key'],
                'value' => mb_substr($l['value'], 0, 300), 'kind' => $kind, 'tech' => $tech];
        }
        $out = [];
        foreach ($groups as $g => $items) $out[] = ['title' => $g === '' ? 'Hodnoty' : $g, 'items' => $items];
        return $out;
    }
}
