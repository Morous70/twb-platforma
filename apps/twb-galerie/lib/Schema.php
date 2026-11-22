<?php
declare(strict_types=1);
namespace MediaGalerie;

use TWB\Jadro\{Db, ModulMigrace};

/**
 * Databázové schéma aplikace (SQLite). Verze se ukládá v module_versions pod „twb-galerie“
 * a do nastavení schema_version (podle ní moduly zalohy a aktualizace poznají starší data).
 * Tabulku media zakládá modul galerie, tabulky auth_tokens a passkeys modul prihlaseni.
 */
final class Schema implements ModulMigrace
{
    public static function verze(): int { return 1; }

    /** Tabulky, které musí existovat dřív než cokoli jiného (nastavení a protokol). */
    public static function zakladniTabulky(Db $db): void
    {
        $db->q('CREATE TABLE IF NOT EXISTS settings (k VARCHAR(64) PRIMARY KEY, v TEXT)');
        $db->q('CREATE TABLE IF NOT EXISTS log (id INTEGER PRIMARY KEY AUTOINCREMENT, at INTEGER NOT NULL, level VARCHAR(10) NOT NULL, message TEXT NOT NULL)');
    }

    public static function proved(Db $db, int $zVerze): void
    {
        if ($zVerze < 1) {
            self::zakladniTabulky($db);
            $db->q('CREATE INDEX IF NOT EXISTS log_level ON log (level, id)');
        }
    }
}
