<?php
declare(strict_types=1);
namespace TWB\Data;

use DateTimeImmutable;
use InvalidArgumentException;
use TWB\Jadro\Db;

/** Tabulka dat: řádky Skupina | Parametr | Hodnota a jejich čtení v textech. */
final class Tabulka
{
    /** Názvy vyhrazené pro kalendář (nesmí je mít vlastní proměnná, skupina dat ani zdroj). */
    public const VYHRAZENE = ['datum', 'den', 'mesic', 'rok', 'tyden', 'cas', 'hodina', 'cislodne', 'svatek', 'odpocet', 'dnu', 'cislo', 'pocasi'];

    /** @var list<array{id: int|string, grp: string, param: string, value: string}>|null */
    private ?array $cache = null;
    private string $t;

    public function __construct(private Db $db, string $tabulka = 'data_rows')
    {
        $this->t = Db::identifikator($tabulka);
    }

    /** @return list<array{id: int|string, grp: string, param: string, value: string}> */
    public function radky(bool $znovu = false): array
    {
        if ($this->cache === null || $znovu) $this->cache = $this->db->q("SELECT id, grp, param, value FROM {$this->t} ORDER BY grp, id")->fetchAll();
        return $this->cache;
    }

    public function skupina(string $grp): array
    {
        $k = Format::klic($grp);
        return array_values(array_filter($this->radky(), fn($r) => Format::klic($r['grp']) === $k));
    }

    /** @return list<string> */
    public function skupiny(): array
    {
        $g = [];
        foreach ($this->radky() as $r) $g[$r['grp']] = true;
        return array_keys($g);
    }

    /** Hodnota podle názvu („Skupina“, „Skupina.nejbližší[.parametr]“, „Skupina.další“, „Skupina.poslední“) a přesného parametru; null = neznámé. */
    public function hodnota(string $nazev, string $arg, DateTimeImmutable $t): ?string
    {
        $casti = array_map('trim', explode('.', $nazev));
        $radky = $this->skupina($casti[0]);
        if (!$radky) return null;
        $vyber = Format::klic($casti[1] ?? '');
        $pole = Format::klic($casti[2] ?? 'hodnota');
        $pick = null;
        if (trim($arg) !== '') {
            $ad = Format::datumZTextu($arg);
            foreach ($radky as $r) {
                $rd = Format::datumZTextu($r['param']);
                if (($ad && $rd && $ad == $rd) || Format::klic($r['param']) === Format::klic($arg)) { $pick = $r; break; }
            }
        } elseif ($vyber === 'nejblizsi' || $vyber === 'posledni' || $vyber === 'dalsi') {
            $dnes = new DateTimeImmutable($t->format('Y-m-d'));
            $best = null;
            foreach ($radky as $r) {
                $d = Format::datumZTextu($r['param']);
                if (!$d) continue;
                if ($vyber === 'posledni' ? $d < $dnes : $d >= $dnes) {
                    if (!$best || ($vyber === 'posledni' ? $d > $best[0] : $d < $best[0])) $best = [$d, $r];
                }
            }
            if ($vyber === 'dalsi' && $best) {
                $po = null;
                foreach ($radky as $r) {
                    $d = Format::datumZTextu($r['param']);
                    if ($d && $d > $best[0] && (!$po || $d < $po[0])) $po = [$d, $r];
                }
                $best = $po;
            }
            $pick = $best[1] ?? null;
            if (!$pick) return '';
        } elseif ($vyber === '') {
            if (count($radky) === 1) $pick = $radky[0];
            else return null;
        } else {
            return null;
        }
        if (!$pick) return '';
        return in_array($pole, ['parametr', 'param', 'datum'], true) ? $pick['param'] : $pick['value'];
    }

    /** Řádek k uložení: [skupina, parametr, hodnota]; čísla se uloží bez mezer. @throws InvalidArgumentException */
    public static function uklidRadek(mixed $g, mixed $p, mixed $v): array
    {
        $g = mb_substr(trim((string)$g), 0, 100);
        $p = mb_substr(trim((string)$p), 0, 200);
        $v = trim((string)$v);
        if ($g === '') throw new InvalidArgumentException('chybí skupina');
        if ($p === '') throw new InvalidArgumentException('chybí parametr');
        if (preg_match('/[{}|:]/u', $g) || str_contains($g, '.')) throw new InvalidArgumentException('skupina nesmí obsahovat znaky { } | : a tečku');
        if (in_array(Format::klic($g), self::VYHRAZENE, true)) throw new InvalidArgumentException("název „{$g}“ je vyhrazený pro kalendář");
        if (preg_match('/^-?[\d\s\x{00A0}]+([.,]\d+)?$/u', $v) && ($n = Format::naCislo($v)) !== null) {
            $v = rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
        }
        return [$g, $p, mb_substr($v, 0, 2000)];
    }
}
