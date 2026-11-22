<?php
declare(strict_types=1);
namespace MediaGalerie;

use TWB\Jadro\{Db, ModulMigrace};

/**
 * Databázové schéma aplikace (SQLite). Verze se ukládá v module_versions pod „twb-galerie“
 * a do nastavení schema_version (podle ní moduly zalohy a aktualizace poznají starší data).
 * Tabulku media zakládá modul galerie, tabulky auth_tokens a passkeys modul prihlaseni.
 * Aplikace k media přidává kampaň, poznámku a klíč veřejného odkazu (sloupce, které modul nezná a nečte).
 */
final class Schema implements ModulMigrace
{
    public static function verze(): int { return 3; }

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
        if ($zVerze < 2) {
            // kampaně = složky médií; smazání kampaně média nemaže (campaign_id se vynuluje v aplikaci)
            $db->q('CREATE TABLE IF NOT EXISTS campaigns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(200) NOT NULL,
                note TEXT NULL,
                created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
            )');
            $sloupce = array_column($db->q('PRAGMA table_info(media)')->fetchAll(), 'name');
            if (!in_array('campaign_id', $sloupce, true)) $db->q('ALTER TABLE media ADD COLUMN campaign_id INTEGER NULL');
            if (!in_array('note', $sloupce, true)) $db->q('ALTER TABLE media ADD COLUMN note TEXT NULL');
            if (!in_array('public_key', $sloupce, true)) $db->q('ALTER TABLE media ADD COLUMN public_key VARCHAR(40) NULL');
            $db->q('CREATE INDEX IF NOT EXISTS media_campaign ON media (campaign_id)');
            $db->q('CREATE UNIQUE INDEX IF NOT EXISTS media_public_key ON media (public_key)');
            foreach ($db->q('SELECT id FROM media WHERE public_key IS NULL')->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                $db->q('UPDATE media SET public_key = ? WHERE id = ?', [Media::novyKlic(), $id]);
            }
        }
        if ($zVerze < 3) {
            // API klíče: jen otisk SHA-256; tabulka se nezálohuje (obnova by vrátila zrušený klíč)
            $db->q("CREATE TABLE IF NOT EXISTS api_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(100) NOT NULL,
                prefix VARCHAR(12) NOT NULL,
                key_hash CHAR(64) NOT NULL UNIQUE,
                scope VARCHAR(10) NOT NULL DEFAULT 'read',
                created_at INTEGER NOT NULL, last_used_at INTEGER NULL
            )");
        }
    }
}
