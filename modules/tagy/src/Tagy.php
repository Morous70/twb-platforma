<?php
declare(strict_types=1);
namespace TWB\Tagy;

use TWB\Jadro\Db;

/**
 * Tagy položek. Uložené jako JSON seznam ve sloupci položky (NULL = bez tagů).
 * Velikost písmen se při porovnání nerozlišuje a použije se už existující zápis.
 * Tabulka i sloupce jsou nastavitelné, aby šlo navázat na datový model aplikace
 * (Plánovač médií: media / id / original_name / tags, Social Planner: library / id / name / tags).
 */
final class Tagy
{
    public const MAX_DELKA = 30;
    public const MAX_POCET = 10;

    private string $t;
    private string $id;
    private string $jmeno;
    private string $tagy;

    public function __construct(
        private Db $db,
        string $tabulka = 'media',
        string $sloupecId = 'id',
        string $sloupecJmeno = 'original_name',
        string $sloupecTagy = 'tags',
        private string $pojem = 'Médium',
    ) {
        $this->t = Db::identifikator($tabulka);
        $this->id = Db::identifikator($sloupecId);
        $this->jmeno = Db::identifikator($sloupecJmeno);
        $this->tagy = Db::identifikator($sloupecTagy);
    }

    public static function klic(string $tag): string { return mb_strtolower($tag, 'UTF-8'); }

    /** Klíč řazení: písmena s diakritikou následují hned za základním písmenem (česky, nezávisle na locale serveru). */
    public static function klicRazeni(string $s): string
    {
        return strtr(mb_strtolower($s, 'UTF-8'), ['á' => 'a~', 'č' => 'c~', 'ď' => 'd~', 'é' => 'e~', 'ě' => 'e~', 'í' => 'i~', 'ň' => 'n~', 'ó' => 'o~',
            'ř' => 'r~', 'š' => 's~', 'ť' => 't~', 'ú' => 'u~', 'ů' => 'u~', 'ý' => 'y~', 'ž' => 'z~']);
    }

    /** Tagy jedné položky (řádek z databáze se sloupcem tags). @param array<string, mixed> $radek @return list<string> */
    public function tagyPolozky(array $radek): array
    {
        $t = json_decode((string)($radek[trim($this->tagy, '`')] ?? $radek['tags'] ?? ''), true);
        return is_array($t) ? array_values(array_map('strval', $t)) : [];
    }

    /** Všechny tagy (i z archivu): [klíč => ['name', 'count']], seřazené česky podle názvu. @return array<string, array{name: string, count: int}> */
    public function vsechny(): array
    {
        $out = [];
        foreach ($this->db->q("SELECT {$this->tagy} FROM {$this->t} WHERE {$this->tagy} IS NOT NULL")->fetchAll(\PDO::FETCH_COLUMN) as $j) {
            foreach (json_decode((string)$j, true) ?: [] as $t) {
                $k = self::klic((string)$t);
                $out[$k] ??= ['name' => (string)$t, 'count' => 0];
                $out[$k]['count']++;
            }
        }
        uasort($out, fn($a, $b) => strcmp(self::klicRazeni($a['name']), self::klicRazeni($b['name'])) ?: strcmp($a['name'], $b['name']));
        return $out;
    }

    /** Jeden tag: mezery a čárky sloučit, nejvýš 30 znaků. Prázdný → null. */
    public function vycisti(string $tag): ?string
    {
        $tag = trim((string)preg_replace('/[\s,;]+/u', ' ', $tag));
        if ($tag === '') return null;
        if (mb_strlen($tag) > self::MAX_DELKA) {
            throw new \InvalidArgumentException('Tag „' . mb_substr($tag, 0, self::MAX_DELKA) . '…“ je delší než ' . self::MAX_DELKA . ' znaků.');
        }
        return $tag;
    }

    /**
     * Seznam tagů: vyčistit, bez opakování, zápis podle už existujícího tagu, nejvýš 10.
     * @param list<mixed>|string $seznam
     * @param array<string, array{name: string, count: int}>|null $zname
     * @return list<string>
     */
    public function vycistiSeznam(array|string $seznam, ?array $zname = null): array
    {
        if (is_string($seznam)) $seznam = preg_split('/[,;]/u', $seznam);
        $zname ??= $this->vsechny();
        $out = [];
        foreach ($seznam as $t) {
            if (!is_scalar($t) || ($t = $this->vycisti((string)$t)) === null) continue;
            $k = self::klic($t);
            if (isset($out[$k])) continue;
            $out[$k] = $zname[$k]['name'] ?? $t;
        }
        if (count($out) > self::MAX_POCET) throw new \InvalidArgumentException("{$this->pojem} může mít nejvýš " . self::MAX_POCET . ' tagů.');
        return array_values($out);
    }

    /** @param list<string> $tagy */
    public function nastav(int $id, array $tagy): void
    {
        $this->db->q("UPDATE {$this->t} SET {$this->tagy} = ? WHERE {$this->id} = ?",
            [$tagy ? json_encode(array_values($tagy), JSON_UNESCAPED_UNICODE) : null, $id]);
    }

    /**
     * Hromadně přidat a odebrat tagy. Vrací počet změněných položek.
     * @param list<int|string> $ids @param list<mixed> $pridat @param list<mixed> $odebrat
     */
    public function hromadne(array $ids, array $pridat, array $odebrat): int
    {
        $zname = $this->vsechny();
        $pridat = $this->vycistiSeznam($pridat, $zname);
        $rm = array_flip(array_map([self::class, 'klic'], array_filter(array_map(fn($t) => is_scalar($t) ? trim((string)$t) : '', $odebrat))));
        $n = 0;
        foreach ($ids as $id) {
            $m = $this->db->q("SELECT {$this->id} AS id, {$this->jmeno} AS jmeno, {$this->tagy} AS tags FROM {$this->t} WHERE {$this->id} = ?", [(int)$id])->fetch();
            if (!$m) continue;
            $cur = $this->tagyPolozky($m);
            $new = array_merge(array_values(array_filter($cur, fn($t) => !isset($rm[self::klic($t)]))), $pridat);
            try {
                $new = $this->vycistiSeznam($new, $zname);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException("„{$m['jmeno']}“: " . $e->getMessage());
            }
            if ($new === $cur) continue;
            $this->nastav((int)$m['id'], $new);
            $n++;
        }
        return $n;
    }

    /** Přejmenování tagu u všech položek (když nový název už existuje, tagy se sloučí). Vrací počet položek. */
    public function prejmenuj(string $z, string $na): int
    {
        $na = $this->vycisti($na) ?? throw new \InvalidArgumentException('Zadejte nový název tagu.');
        $fk = self::klic(trim($z));
        $zname = $this->vsechny();
        if (!isset($zname[$fk])) throw new \InvalidArgumentException('Tag nenalezen.');
        $tk = self::klic($na);
        $cil = ($tk !== $fk && isset($zname[$tk])) ? $zname[$tk]['name'] : $na;   // sloučení: zůstane zápis existujícího tagu
        $n = 0;
        foreach ($this->db->q("SELECT {$this->id} AS id, {$this->tagy} AS tags FROM {$this->t} WHERE {$this->tagy} IS NOT NULL")->fetchAll() as $m) {
            $cur = $this->tagyPolozky($m);
            if (!in_array($fk, array_map([self::class, 'klic'], $cur), true)) continue;
            $new = [];
            $videno = [];
            foreach ($cur as $t) {
                $t = self::klic($t) === $fk ? $cil : $t;
                if (isset($videno[self::klic($t)])) continue;
                $videno[self::klic($t)] = true;
                $new[] = $t;
            }
            $this->nastav((int)$m['id'], $new);
            $n++;
        }
        return $n;
    }

    /** Smazání tagu u všech položek. Vrací počet položek. */
    public function smaz(string $nazev): int
    {
        $k = self::klic(trim($nazev));
        $n = 0;
        foreach ($this->db->q("SELECT {$this->id} AS id, {$this->tagy} AS tags FROM {$this->t} WHERE {$this->tagy} IS NOT NULL")->fetchAll() as $m) {
            $cur = $this->tagyPolozky($m);
            $new = array_values(array_filter($cur, fn($t) => self::klic($t) !== $k));
            if (count($new) === count($cur)) continue;
            $this->nastav((int)$m['id'], $new);
            $n++;
        }
        return $n;
    }
}
