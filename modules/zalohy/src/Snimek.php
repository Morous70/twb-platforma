<?php
declare(strict_types=1);
namespace TWB\Zalohy;

use TWB\Jadro\Db;

/** Konzistentní snímek SQLite databáze do jednoho souboru (VACUUM INTO). */
final class Snimek
{
    public static function vytvor(Db $db, string $cil): void
    {
        if (!$db->jeSqlite()) throw new \RuntimeException('Snímek jde vytvořit jen z databáze SQLite.');
        @unlink($cil);
        $db->pdo()->exec('VACUUM INTO ' . $db->pdo()->quote($cil));
    }

    /** Uloží snímek do složky a ponechá $uchovat nejnovějších. Vrací cestu k souboru. */
    public static function automaticky(Db $db, string $slozka, string $prefix, int $uchovat = 4, ?int $cas = null): string
    {
        if (!is_dir($slozka)) @mkdir($slozka, 0775, true);
        if (!preg_match('/^[a-z0-9-]+$/', $prefix)) throw new \InvalidArgumentException('Neplatná předpona záloh.');
        $soubor = $slozka . '/' . $prefix . date('Ymd-His', $cas ?? time()) . '.sqlite';
        for ($i = 2; is_file($soubor); $i++) $soubor = $slozka . '/' . $prefix . date('Ymd-His', $cas ?? time()) . "-v$i.sqlite";
        self::vytvor($db, $soubor);
        $vse = glob($slozka . '/' . $prefix . '*.sqlite') ?: [];
        usort($vse, fn($a, $b) => strcmp(basename($b, '.sqlite'), basename($a, '.sqlite')));   // „…-v2“ je novější než „…“
        foreach (array_slice($vse, $uchovat) as $stary) @unlink($stary);
        return $soubor;
    }
}
