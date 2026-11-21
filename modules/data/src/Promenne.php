<?php
declare(strict_types=1);
namespace TWB\Data;

use DateTimeImmutable;

/**
 * Vyhodnocení proměnných v textu: {{datum}}, {{Sportka.nejbližší}}, {{Allwyn:sportka.tah1.2|mil}}, a podmínek „zobrazit jen když“.
 * Ve slidech se píše {x} i {{x}} (parametr $slide). Neznámé proměnné zůstávají v textu a vrací se ve výsledku.
 */
final class Promenne
{
    private const RE = '/\{\{\s*([^{}|:]+?)\s*(?::([^{}|]*))?((?:\|[^{}|]+)*)\}\}/u';
    private const RE_SLIDE = '/\{(?:\{(?=[^{}]*\}\}))?\s*([^{}|:]+?)\s*(?::([^{}|]*))?((?:\|[^{}|]+)*)\}\}?/u';

    /** @var array<string, string>|null */
    private static ?array $svatky = null;

    /**
     * @param callable(): array<string, array{type: string, value: string}> $uzivatelske vlastní proměnné (klíč = název malými písmeny)
     */
    public function __construct(
        private Tabulka $tabulka,
        private $uzivatelske,
        private ?Zdroje $zdroje = null,
        private ?Pocasi $pocasi = null,
    ) {}

    /** Hodnota jedné proměnné, nebo null, pokud ji neznáme. $index = pořadí výskytu opakování (0 = první). */
    public function hodnota(string $nazev, string $arg, DateTimeImmutable $t, int $index = 0, ?array $uzivatelske = null): ?string
    {
        $k = Format::klic($nazev);
        switch ($k) {
            case 'datum': return Format::datumZArgumentu($arg, $t)->format('j. n. Y');
            case 'den':
                $v = Format::DNY[(int)Format::datumZArgumentu($arg, $t)->format('N')];
                return $nazev === 'Den' ? mb_strtoupper(mb_substr($v, 0, 1)) . mb_substr($v, 1) : $v;
            case 'mesic': return Format::MESICE[(int)Format::datumZArgumentu($arg, $t)->format('n')];
            case 'rok': return Format::datumZArgumentu($arg, $t)->format('Y');
            case 'tyden': return (string)(int)Format::datumZArgumentu($arg, $t)->format('W');
            case 'cas': return $t->format('G:i');
            case 'hodina': return $t->format('G');
            case 'cislodne': return $t->format('N');
            case 'cislo': return (string)($index + 1);
            case 'svatek':
                self::$svatky ??= require dirname(__DIR__) . '/data/svatky.php';
                return self::$svatky[Format::datumZArgumentu($arg, $t)->format('m-d')] ?? '';
            case 'odpocet':
                $cil = Format::datumZTextu($arg);
                return $cil ? Format::odpocet(Format::dnuDo($t, $cil)) : null;
            case 'dnu':
                $cil = Format::datumZTextu($arg);
                return $cil ? (string)max(0, Format::dnuDo($t, $cil)) : null;
        }
        $user = $uzivatelske ?? ($this->uzivatelske)();
        if (trim($arg) === '' && !str_contains($nazev, '.') && isset($user[mb_strtolower($nazev)])) {
            $u = $user[mb_strtolower($nazev)];
            if ($u['type'] === 'list') {
                $polozky = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)$u['value'])), fn($x) => $x !== ''));
                return $polozky ? $polozky[$index % count($polozky)] : '';
            }
            return (string)$u['value'];
        }
        if (trim($arg) !== '' && $this->zdroje && $this->zdroje->existuje($nazev)) return $this->zdroje->hodnota($nazev, $arg);
        if ($k === 'pocasi') return $this->pocasi?->hodnota($arg, $t);
        return $this->tabulka->hodnota($nazev, $arg, $t);
    }

    /** @return array{0: string, 1: list<string>} [text, neznámé proměnné (celý zápis)] */
    public function vyres(string $text, DateTimeImmutable $t, int $index = 0, bool $slide = false): array
    {
        if (!str_contains($text, $slide ? '{' : '{{')) return [$text, []];
        $user = ($this->uzivatelske)();
        $neznama = [];
        $out = preg_replace_callback($slide ? self::RE_SLIDE : self::RE, function ($m) use ($t, $index, $user, &$neznama) {
            $filtry = array_values(array_filter(explode('|', $m[3] ?? ''), fn($x) => $x !== ''));
            $v = $this->hodnota(trim($m[1]), trim($m[2] ?? ''), $t, $index, $user);
            if ($v === null) {
                foreach ($filtry as $f) if (Format::klic(explode(':', $f, 2)[0]) === 'vychozi') return explode(':', $f, 2)[1] ?? '';
                $neznama[] = $m[0];
                return $m[0];
            }
            return Format::filtry($v, $filtry, $t);
        }, $text);
        return [$out, array_values(array_unique($neznama))];
    }

    // ---------- podmínky ----------
    /** Strana podmínky: text s proměnnými, nebo samotný název proměnné (den, Sportka.nejbližší, Allwyn:sportka.jackpot). */
    private function operand(string $s, DateTimeImmutable $t): string
    {
        $s = trim($s);
        if ($s === '') return '';
        if (str_contains($s, '{')) return $this->vyres($s, $t, 0, true)[0];
        if (preg_match('/^([^:|]+)(?::([^|]*))?$/u', $s, $m)) {
            $v = $this->hodnota(trim($m[1]), $m[2] ?? '', $t);
            if ($v !== null) return $v;
        }
        return $s;
    }

    public static function porovnej(string $a, string $op, string $b): bool
    {
        if ($op === 'empty') return trim($a) === '';
        if ($op === 'notempty') return trim($a) !== '';
        if ($op === 'contains') return mb_stripos($a, $b) !== false;
        $na = Format::naCislo($a); $nb = Format::naCislo($b);
        $da = Format::datumZTextu($a); $db = Format::datumZTextu($b);
        if ($na !== null && $nb !== null) { $x = $na; $y = $nb; }
        elseif ($da && $db) { $x = $da->getTimestamp(); $y = $db->getTimestamp(); }
        else { $x = mb_strtolower(trim($a)); $y = mb_strtolower(trim($b)); }
        return match ($op) {
            '=' => $x == $y, '!=' => $x != $y, '>' => $x > $y, '<' => $x < $y, '>=' => $x >= $y, '<=' => $x <= $y,
            default => false,
        };
    }

    /** Platí všechny podmínky ({l, op, r})? Prázdný seznam = ano. */
    public function splneno(array $podminky, DateTimeImmutable $t): bool
    {
        foreach ($podminky as $c) {
            if (trim($c['l'] ?? '') === '') continue;
            if (!self::porovnej($this->operand($c['l'], $t), $c['op'] ?? '=', $this->operand($c['r'] ?? '', $t))) return false;
        }
        return true;
    }
}
