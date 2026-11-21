<?php
declare(strict_types=1);
namespace TWB\Jadro;

/** Spouští migrace modulů a pamatuje si jejich verze v tabulce module_versions. */
final class Migrace
{
    public function __construct(private Db $db)
    {
        $this->db->q('CREATE TABLE IF NOT EXISTS module_versions (module VARCHAR(64) PRIMARY KEY, version INTEGER NOT NULL)');
    }

    public function verze(string $modul): int
    {
        return (int)$this->db->q('SELECT version FROM module_versions WHERE module = ?', [$modul])->fetchColumn();
    }

    /** @param class-string<ModulMigrace> $trida  Vrací true, když se něco změnilo. */
    public function spust(string $modul, string $trida): bool
    {
        if (!is_subclass_of($trida, ModulMigrace::class)) throw new \InvalidArgumentException("Třída {$trida} neimplementuje ModulMigrace.");
        $z = $this->verze($modul);
        $na = $trida::verze();
        if ($z > $na) throw new \RuntimeException("Modul „{$modul}“ má v databázi novější schéma ({$z}) než kód ({$na}). Vraťte novější verzi modulu.");
        if ($z === $na) return false;
        $this->db->transakce(function (Db $db) use ($modul, $trida, $z, $na) {
            $trida::proved($db, $z);
            if ($z === 0) $db->q('INSERT INTO module_versions (module, version) VALUES (?, ?)', [$modul, $na]);
            else $db->q('UPDATE module_versions SET version = ? WHERE module = ?', [$na, $modul]);
        });
        return true;
    }
}
