<?php
declare(strict_types=1);
namespace TWB\Jadro;

/** Nastavení aplikace (klíč → hodnota). Tabulka a sloupce jsou volitelné, aby šlo navázat na stávající aplikace. */
final class Nastaveni
{
    /** @var array<string, string|null>|null */
    private ?array $cache = null;
    private string $t;
    private string $k;
    private string $v;

    public function __construct(private Db $db, string $tabulka = 'settings', string $sloupecKlic = 'k', string $sloupecHodnota = 'v')
    {
        $this->t = Db::identifikator($tabulka);
        $this->k = Db::identifikator($sloupecKlic);
        $this->v = Db::identifikator($sloupecHodnota);
    }

    public function dej(string $klic, mixed $vychozi = null): mixed
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach ($this->db->q("SELECT {$this->k} AS k, {$this->v} AS v FROM {$this->t}")->fetchAll() as $r) $this->cache[(string)$r['k']] = $r['v'];
        }
        return array_key_exists($klic, $this->cache) ? $this->cache[$klic] : $vychozi;
    }

    public function uloz(string $klic, mixed $hodnota): void
    {
        $h = $hodnota === null ? null : (string)$hodnota;
        $n = $this->db->q("UPDATE {$this->t} SET {$this->v} = ? WHERE {$this->k} = ?", [$h, $klic])->rowCount();
        if ($n === 0 && !$this->db->q("SELECT 1 FROM {$this->t} WHERE {$this->k} = ?", [$klic])->fetchColumn()) {
            $this->db->q("INSERT INTO {$this->t} ({$this->k}, {$this->v}) VALUES (?, ?)", [$klic, $h]);
        }
        $this->cache = null;
    }

    public function obnov(): void { $this->cache = null; }
}
