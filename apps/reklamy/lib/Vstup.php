<?php
declare(strict_types=1);
namespace Reklamy;

/** Kontrola a převod vstupů z formulářů. Chyby jsou Chyba s textem pro uživatele. */
final class Vstup
{
    public static function text(array $v, string $klic, string $popis, int $max = 200, bool $povinne = true): ?string
    {
        $s = trim((string)($v[$klic] ?? ''));
        $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s);
        if ($s === '') {
            if ($povinne) throw new Chyba("Vyplňte pole „{$popis}“.");
            return null;
        }
        if (mb_strlen($s) > $max) throw new Chyba("Pole „{$popis}“ může mít nejvýš {$max} znaků.");
        return $s;
    }

    /** Celé číslo v rozsahu; prázdné = null (nebo chyba, když je povinné). */
    public static function cislo(array $v, string $klic, string $popis, int $min, int $max, bool $povinne = false): ?int
    {
        $s = trim((string)($v[$klic] ?? ''));
        $s = str_replace([' ', "\u{00A0}"], '', $s);
        if ($s === '') {
            if ($povinne) throw new Chyba("Vyplňte pole „{$popis}“.");
            return null;
        }
        if (!preg_match('/^-?\d{1,12}$/', $s)) throw new Chyba("Pole „{$popis}“ musí být celé číslo.");
        $n = (int)$s;
        if ($n < $min || $n > $max) throw new Chyba("Pole „{$popis}“ musí být od {$min} do {$max}.");
        return $n;
    }

    /** Hodnota ze seznamu povolených klíčů. @param array<string, string> $povolene */
    public static function volba(array $v, string $klic, string $popis, array $povolene, ?string $vychozi = null): string
    {
        $s = (string)($v[$klic] ?? $vychozi ?? '');
        if (!array_key_exists($s, $povolene)) throw new Chyba("Neplatná hodnota pole „{$popis}“.");
        return $s;
    }

    /** Datum a čas z pole datetime-local (2026-11-21T10:30) nebo „2026-11-21 10:30“; prázdné = null. */
    public static function cas(array $v, string $klic, string $popis): ?int
    {
        $s = trim((string)($v[$klic] ?? ''));
        if ($s === '') return null;
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d', 'j.n.Y H:i', 'j. n. Y H:i', 'j.n.Y', 'j. n. Y'] as $f) {
            $d = \DateTimeImmutable::createFromFormat('!' . $f, $s);
            if ($d && $d->format($f) === $s) return $d->getTimestamp();
        }
        throw new Chyba("Pole „{$popis}“ neobsahuje platné datum a čas.");
    }

    /** Hodnota pro pole datetime-local. */
    public static function casDoPole(?int $t): string { return $t ? date('Y-m-d\TH:i', $t) : ''; }

    /** Adresa http(s); prázdná = null. */
    public static function adresa(array $v, string $klic, string $popis, bool $povinne = false): ?string
    {
        $s = self::text($v, $klic, $popis, 2000, $povinne);
        if ($s === null) return null;
        if (!preg_match('~^https?://[^\s/$.?#][^\s]*$~i', $s) || filter_var(self::ascii($s), FILTER_VALIDATE_URL) === false) {
            throw new Chyba("Pole „{$popis}“ musí být adresa začínající http:// nebo https://.");
        }
        return $s;
    }

    /** Pro kontrolu adresy s diakritikou: znaky mimo ASCII nahradí. */
    private static function ascii(string $s): string
    {
        return (string)preg_replace_callback('/[^\x21-\x7E]/u', fn($m) => rawurlencode($m[0]), $s);
    }
}
