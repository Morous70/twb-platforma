<?php
declare(strict_types=1);
namespace TWB\Data;

use DateTimeImmutable;

/** Čísla, data a filtry proměnných (česky). Čistá logika bez databáze. */
final class Format
{
    public const DNY = [1 => 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota', 'neděle'];
    public const DNY_KRATKE = [1 => 'po', 'út', 'st', 'čt', 'pá', 'so', 'ne'];
    public const MESICE = [1 => 'leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];
    public const MESICE_2P = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června', 'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];
    private const DATUM_ZNAKY = '/RRRR|RR|MĚSÍCE|MESICE|MĚSÍC|MESIC|MM|M|DEN|DN|DD|D/u';

    /** Malá písmena bez diakritiky (porovnávání názvů). */
    public static function klic(string $s): string
    {
        return strtr(mb_strtolower(trim($s)), ['á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z']);
    }

    /** Datum ve tvaru 2026-11-26 nebo 26. 11. 2026 (případný čas za ním se ignoruje), jinak null. */
    public static function datumZTextu(string $s): ?DateTimeImmutable
    {
        $s = trim($s);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T ]\d{1,2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/', $s, $m)
            || preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})(?:,?\s+\d{1,2}:\d{2}(?::\d{2})?)?$/', $s, $m)) {
            [$y, $mo, $d] = strlen($m[1]) === 4 ? [$m[1], $m[2], $m[3]] : [$m[3], $m[2], $m[1]];
            if (checkdate((int)$mo, (int)$d, (int)$y)) return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $mo, $d));
        }
        return null;
    }

    public static function mnozne(int $n, string $jedno, string $few, string $many): string
    {
        return $n === 1 ? $jedno : (($n >= 2 && $n <= 4) ? $few : $many);
    }

    /** Datum z argumentu: „+1“, „-2“ (posun ve dnech) nebo prázdné = zadaný den. */
    public static function datumZArgumentu(string $arg, DateTimeImmutable $t): DateTimeImmutable
    {
        $arg = trim($arg);
        if ($arg !== '' && preg_match('/^[+-]\d+$/', $arg)) return $t->modify("$arg day");
        return $t;
    }

    public static function dnuDo(DateTimeImmutable $t, DateTimeImmutable $d): int
    {
        return (int)$t->setTime(0, 0)->diff(new DateTimeImmutable($d->format('Y-m-d'), $t->getTimezone()))->format('%r%a');
    }

    public static function odpocet(int $dny): string
    {
        return $dny === 0 ? 'dnes' : ($dny === 1 ? 'zítra' : ($dny < 0 ? 'proběhlo' : "za $dny " . self::mnozne($dny, 'den', 'dny', 'dní')));
    }

    // ---------- čísla ----------
    /** Převede „125 000 000“, „1,5“ apod. na číslo, jinak null. */
    public static function naCislo(string $s): ?float
    {
        $c = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($s));
        $c = str_replace(',', '.', $c);
        return is_numeric($c) ? (float)$c : null;
    }

    public static function cislo(float $n, int $maxDesetin = 2): string
    {
        $s = number_format($n, $maxDesetin, ',', ' ');
        if ($maxDesetin > 0) $s = rtrim(rtrim($s, '0'), ',');
        return $s;
    }

    /** Číslo s mezerami. $arg: prázdné = výchozí počet míst, „N“ = nejvýš N míst, „0,00“ = vždy přesně tolik míst. */
    public static function cisloFormat(float $n, string $arg, int $vychozi): string
    {
        $arg = trim($arg);
        if (preg_match('/^0[,.](0+)$/', $arg, $m)) return number_format($n, strlen($m[1]), ',', ' ');
        $dec = preg_match('/^\d$/', $arg) ? (int)$arg : $vychozi;
        $s = self::cislo($n, $dec);
        return $s === '-0' ? '0' : $s;
    }

    /** Číslo se slovem ve správném tvaru: 1 milion, 2 miliony, 5 milionů, 1,5 milionu. */
    public static function cisloSlovem(float $n, string $arg, int $vychozi, array $tvary): string
    {
        $s = self::cisloFormat($n, $arg, $vychozi);
        if (str_contains($s, ',')) return "$s {$tvary[3]}";
        $i = (int)abs((float)str_replace(' ', '', $s));
        return "$s " . ($i === 1 ? $tvary[0] : ($i >= 2 && $i <= 4 ? $tvary[1] : $tvary[2]));
    }

    /**
     * Pojmenované formáty: kratke, bezmezer, nuly, den, den-plne, slovem, dlouhe, den-slovem, den-dlouhe, dentydne, mesic-rok.
     * Jinak vlastní formát ze zástupných znaků (velkými písmeny): D DD M MM RR RRRR MĚSÍC MĚSÍCE DEN DN.
     */
    public static function datum(DateTimeImmutable $d, string $fmt): string
    {
        $den = self::DNY[(int)$d->format('N')];
        $gen = self::MESICE_2P[(int)$d->format('n')];
        $preset = $fmt !== '' && $fmt === mb_strtoupper($fmt) && preg_match(self::DATUM_ZNAKY, $fmt) ? '-' : str_replace(['-', ' ', '_'], '', self::klic($fmt));
        switch ($preset) {
            case '': return $d->format('j. n. Y');
            case 'kratke': return $d->format('j. n.');
            case 'bezmezer': return $d->format('j.n.Y');
            case 'nuly': return $d->format('d.m.Y');
            case 'den': return $den . ' ' . $d->format('j. n.');
            case 'denplne': return $den . ' ' . $d->format('j. n. Y');
            case 'slovem': return $d->format('j. ') . $gen;
            case 'dlouhe': return $d->format('j. ') . $gen . $d->format(' Y');
            case 'denslovem': return $den . ' ' . $d->format('j. ') . $gen;
            case 'dendlouhe': return $den . ' ' . $d->format('j. ') . $gen . $d->format(' Y');
            case 'jendenvtydnu': case 'dentydne': return $den;
            case 'mesicrok': return self::MESICE[(int)$d->format('n')] . $d->format(' Y');
        }
        if (!preg_match(self::DATUM_ZNAKY, $fmt)) return $d->format('j. n. Y');
        return preg_replace_callback(self::DATUM_ZNAKY, fn($m) => match ($m[0]) {
            'RRRR' => $d->format('Y'), 'RR' => $d->format('y'),
            'MĚSÍCE', 'MESICE' => $gen, 'MĚSÍC', 'MESIC' => self::MESICE[(int)$d->format('n')],
            'MM' => $d->format('m'), 'M' => $d->format('n'),
            'DEN' => $den, 'DN' => self::DNY_KRATKE[(int)$d->format('N')],
            'DD' => $d->format('d'), 'D' => $d->format('j'),
        }, $fmt);
    }

    /** @param list<string> $filtry např. ['mil:2', 'velka'] */
    public static function filtry(string $v, array $filtry, ?DateTimeImmutable $t = null): string
    {
        foreach ($filtry as $f) {
            [$nazev, $arg] = array_pad(explode(':', $f, 2), 2, '');
            $n = self::naCislo($v);
            switch (self::klic($nazev)) {
                case 'velka': $v = mb_strtoupper($v); break;
                case 'mala': $v = mb_strtolower($v); break;
                case 'prvni': $v = mb_strtoupper(mb_substr($v, 0, 1)) . mb_substr($v, 1); break;
                case 'tis': if ($n !== null) $v = self::cisloFormat($n / 1e3, $arg, 0) . ' tis.'; break;
                case 'mil': if ($n !== null) $v = self::cisloFormat($n / 1e6, $arg, 1) . ' mil.'; break;
                case 'mld': if ($n !== null) $v = self::cisloFormat($n / 1e9, $arg, 2) . ' mld.'; break;
                case 'milionu': if ($n !== null) $v = self::cisloSlovem($n / 1e6, $arg, 1, ['milion', 'miliony', 'milionů', 'milionu']); break;
                case 'miliard': if ($n !== null) $v = self::cisloSlovem($n / 1e9, $arg, 2, ['miliarda', 'miliardy', 'miliard', 'miliardy']); break;
                case 'cislo': if ($n !== null) $v = self::cisloFormat($n, $arg, 2); break;
                case 'kc': if ($n !== null) $v = self::cisloFormat($n, $arg, 2) . ' Kč'; break;
                case 'bezmezer': $v = preg_replace('/(?<=\d)[ \x{00A0}\x{202F}](?=\d)/u', '', $v); break;
                case 'pripona': $v .= $arg; break;
                case 'datum': if ($d = self::datumZTextu($v)) $v = self::datum($d, $arg); break;
                case 'odpocet': if (($d = self::datumZTextu($v)) && $t) $v = self::odpocet(self::dnuDo($t, $d)); break;
                case 'vychozi': if (trim($v) === '') $v = $arg; break;
                case 'cisla': $v = implode($arg !== '' ? $arg : ' ', preg_split('/\s+/u', trim($v), -1, PREG_SPLIT_NO_EMPTY)); break;
            }
        }
        return $v;
    }
}
