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
    /** @var callable(): int */
    private $cas;

    /** Zápis předpokládá sloupce `id, grp, param, value, updated_at` (UNIX čas) – tabulka z `Migrace` nebo Social Planneru. */
    public function __construct(private Db $db, string $tabulka = 'data_rows', ?callable $cas = null)
    {
        $this->t = Db::identifikator($tabulka);
        $this->cas = $cas ?? 'time';
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

    // ---------- správa řádků ----------
    /** Uloží jeden řádek (id 0 / null = nový). Vrací id. @throws InvalidArgumentException */
    public function uloz(?int $id, mixed $g, mixed $p, mixed $v): int
    {
        [$g, $p, $v] = self::uklidRadek($g, $p, $v);
        if ($id) {
            $this->db->q("UPDATE {$this->t} SET grp = ?, param = ?, value = ?, updated_at = ? WHERE id = ?", [$g, $p, $v, ($this->cas)(), $id]);
        } else {
            $this->db->q("INSERT INTO {$this->t} (grp, param, value, updated_at) VALUES (?,?,?,?)", [$g, $p, $v, ($this->cas)()]);
            $id = $this->db->posledniId();
        }
        $this->cache = null;
        return $id;
    }

    /** @param list<int|string> $ids */
    public function smaz(array $ids): int
    {
        foreach ($ids as $id) $this->db->q("DELETE FROM {$this->t} WHERE id = ?", [(int)$id]);
        $this->cache = null;
        return count($ids);
    }

    /** Smaže řádky, jejichž parametr je datum před dnešním dnem. Vrací počet. */
    public function smazMinule(DateTimeImmutable $dnes): int
    {
        $dnes = new DateTimeImmutable($dnes->format('Y-m-d'));
        $ids = [];
        foreach ($this->radky(true) as $r) { $d = Format::datumZTextu($r['param']); if ($d && $d < $dnes) $ids[] = (int)$r['id']; }
        return $this->smaz($ids);
    }

    /**
     * Rozebere vložený text z Excelu (řádky Skupina⇥Parametr⇥Hodnota, oddělovač tabulátor, ; nebo |; první řádek může být záhlaví).
     * @return array{0: list<array{0: string, 1: string, 2: string}>, 1: list<string>} [řádky, chyby po řádcích]
     */
    public static function rozborImportu(string $text): array
    {
        $rows = []; $chyby = [];
        foreach (preg_split('/\r?\n/', $text) as $i => $line) {
            if (trim($line) === '') continue;
            $sep = str_contains($line, "\t") ? "\t" : (str_contains($line, ';') ? ';' : (str_contains($line, '|') ? '|' : null));
            $cols = $sep ? array_map('trim', explode($sep, $line)) : [];
            if (count($cols) < 3) { $chyby[] = 'řádek ' . ($i + 1) . ': očekávám 3 sloupce (Skupina, Parametr, Hodnota)'; continue; }
            if ($i === 0 && Format::klic($cols[0]) === 'skupina') continue;
            try { $rows[] = self::uklidRadek($cols[0], $cols[1], implode(' ', array_slice($cols, 2))); }
            catch (\Throwable $e) { $chyby[] = 'řádek ' . ($i + 1) . ': ' . $e->getMessage(); }
        }
        return [$rows, $chyby];
    }

    /**
     * Vloží řádky z textu; $nahradit = nejdřív smaže všechny řádky skupin, které import obsahuje.
     * @return array{imported: int, errors: list<string>}
     * @throws InvalidArgumentException když není co vložit
     */
    public function importuj(string $text, bool $nahradit = false): array
    {
        [$rows, $chyby] = self::rozborImportu($text);
        if (!$rows) throw new InvalidArgumentException($chyby ? 'Nic k vložení – ' . implode('; ', array_slice($chyby, 0, 3)) : 'Vložte řádky z Excelu (Skupina, Parametr, Hodnota).');
        $this->db->transakce(function () use ($rows, $nahradit) {
            if ($nahradit) {
                foreach (array_unique(array_map(fn($r) => Format::klic($r[0]), $rows)) as $gk) {
                    foreach ($this->radky(true) as $old) if (Format::klic($old['grp']) === $gk) $this->db->q("DELETE FROM {$this->t} WHERE id = ?", [(int)$old['id']]);
                }
            }
            foreach ($rows as [$g, $p, $v]) $this->db->q("INSERT INTO {$this->t} (grp, param, value, updated_at) VALUES (?,?,?,?)", [$g, $p, $v, ($this->cas)()]);
        });
        $this->cache = null;
        return ['imported' => count($rows), 'errors' => $chyby];
    }
}
