<?php
declare(strict_types=1);
namespace MediaGalerie;

use TWB\Jadro\{Db, ModulMigrace};
use TWB\Licence\Licence;

/**
 * Databázové schéma aplikace (SQLite). Verze se ukládá v module_versions pod „twb-galerie“
 * a do nastavení schema_version (podle ní moduly zalohy a aktualizace poznají starší data).
 * Tabulku media zakládá modul galerie, tabulky auth_tokens a passkeys modul prihlaseni.
 * Tabulka web_files (0.8.11) drží soubory veřejného webu (vlastní logo) přímo v databázi – jsou malé a zálohují se s ní.
 * Aplikace k media přidává kategorii, poznámku, autora, licenci (text, kód a verzi), klíč veřejného odkazu, příznak doporučené, popis úprav obrázku edits a ohnisko focus_x/focus_y s volbou show_whole (sloupce, které modul nezná a nečte).
 */
final class Schema implements ModulMigrace
{
    public static function verze(): int { return 11; }

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
            // kategorie = složky médií; smazání kategorie média nemaže (category_id se vynuluje v aplikaci)
            $db->q('CREATE TABLE IF NOT EXISTS categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(200) NOT NULL,
                note TEXT NULL,
                created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
            )');
            $sloupce = array_column($db->q('PRAGMA table_info(media)')->fetchAll(), 'name');
            if (!in_array('category_id', $sloupce, true)) $db->q('ALTER TABLE media ADD COLUMN category_id INTEGER NULL');
            if (!in_array('note', $sloupce, true)) $db->q('ALTER TABLE media ADD COLUMN note TEXT NULL');
            if (!in_array('public_key', $sloupce, true)) $db->q('ALTER TABLE media ADD COLUMN public_key VARCHAR(40) NULL');
            $db->q('CREATE INDEX IF NOT EXISTS media_category ON media (category_id)');
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
        if ($zVerze < 4) {
            // kampaně přejmenované na kategorie (0.4.1): instalace, které už mají tabulku campaigns, se převedou
            $tabulky = $db->q("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN);
            if (in_array('campaigns', $tabulky, true) && !in_array('categories', $tabulky, true)) $db->q('ALTER TABLE campaigns RENAME TO categories');
            $sloupce = array_column($db->q('PRAGMA table_info(media)')->fetchAll(), 'name');
            if (in_array('campaign_id', $sloupce, true) && !in_array('category_id', $sloupce, true)) {
                $db->q('DROP INDEX IF EXISTS media_campaign');
                $db->q('ALTER TABLE media RENAME COLUMN campaign_id TO category_id');
                $db->q('CREATE INDEX IF NOT EXISTS media_category ON media (category_id)');
            }
        }
        if ($zVerze < 5) {
            // autor a licence (0.5): ručně nebo z metadat obrázku (XMP, IPTC, EXIF)
            $sloupce = array_column($db->q('PRAGMA table_info(media)')->fetchAll(), 'name');
            foreach (['author' => 'VARCHAR(200)', 'license' => 'TEXT', 'license_url' => 'VARCHAR(500)'] as $sl => $typ) {
                if (!in_array($sl, $sloupce, true)) $db->q("ALTER TABLE media ADD COLUMN $sl $typ NULL");
            }
        }
        if ($zVerze < 6) {
            // 0.6: licence jako kód ze společného číselníku (modul licence) a verze; dosavadní text se převede odhadem
            $sloupce = array_column($db->q('PRAGMA table_info(media)')->fetchAll(), 'name');
            foreach (['license_code' => 'VARCHAR(20)', 'license_version' => 'VARCHAR(120)'] as $sl => $typ) {
                if (!in_array($sl, $sloupce, true)) $db->q("ALTER TABLE media ADD COLUMN $sl $typ NULL");
            }
            foreach ($db->q("SELECT id, license, license_url FROM media WHERE license_code IS NULL AND (license IS NOT NULL OR license_url IS NOT NULL)")->fetchAll() as $r) {
                $o = Licence::odhad($r['license'], $r['license_url']);
                if ($o['kod'] !== null) $db->q('UPDATE media SET license_code = ?, license_version = ? WHERE id = ?', [$o['kod'], $o['verze'], $r['id']]);
            }
            // limit požadavků na API klíč (okno jedné minuty)
            $sloupce = array_column($db->q('PRAGMA table_info(api_keys)')->fetchAll(), 'name');
            if (!in_array('rate_window', $sloupce, true)) $db->q('ALTER TABLE api_keys ADD COLUMN rate_window INTEGER NULL');
            if (!in_array('rate_count', $sloupce, true)) $db->q('ALTER TABLE api_keys ADD COLUMN rate_count INTEGER NOT NULL DEFAULT 0');
        }
        if ($zVerze < 7) {
            // 0.8: veřejný web – kategorie zobrazená na webu, popis, obálka a pořadí (výchozí: nic není veřejné)
            $sloupce = array_column($db->q('PRAGMA table_info(categories)')->fetchAll(), 'name');
            foreach (['web_public' => 'INTEGER NOT NULL DEFAULT 0', 'web_description' => 'TEXT NULL', 'web_cover_id' => 'INTEGER NULL', 'web_order' => 'INTEGER NOT NULL DEFAULT 0'] as $sl => $typ) {
                if (!in_array($sl, $sloupce, true)) $db->q("ALTER TABLE categories ADD COLUMN $sl $typ");
            }
        }
        if ($zVerze < 8) {
            // 0.8.4: doporučená fotografie – veřejný web z doporučených ukazuje náhodný výběr (výchozí: nic není doporučené)
            $sloupce = array_column($db->q('PRAGMA table_info(media)')->fetchAll(), 'name');
            if (!in_array('featured', $sloupce, true)) $db->q('ALTER TABLE media ADD COLUMN featured INTEGER NOT NULL DEFAULT 0');
            $db->q('CREATE INDEX IF NOT EXISTS media_featured ON media (featured)');
        }
        if ($zVerze < 9) {
            // 0.8.11: soubory veřejného webu (vlastní logo) – malé, proto v databázi (base64); obsah se načte jen při vydání souboru (logo.php)
            $db->q('CREATE TABLE IF NOT EXISTS web_files (
                name VARCHAR(40) PRIMARY KEY,
                mime VARCHAR(40) NOT NULL,
                data TEXT NOT NULL,          -- obsah v base64 (záloha je JSON, binární data by neprošla)
                md5 CHAR(32) NOT NULL,
                width INTEGER NULL, height INTEGER NULL,
                updated_at INTEGER NOT NULL
            )');
        }
        if ($zVerze < 10) {
            // 0.8.13: úprava obrázku (ořez, otočení) – popis úprav; podle něj komprese vychází z upravené verze, ne z originálu
            $sloupce = array_column($db->q('PRAGMA table_info(media)')->fetchAll(), 'name');
            if (!in_array('edits', $sloupce, true)) $db->q('ALTER TABLE media ADD COLUMN edits TEXT NULL');
        }
        if ($zVerze < 11) {
            // 0.8.15: ohnisko fotky (0–100 % šířky a výšky; NULL = výchozí střed) a volba „zobrazit celou fotku“ pro výřezy na veřejném webu
            $sloupce = array_column($db->q('PRAGMA table_info(media)')->fetchAll(), 'name');
            if (!in_array('focus_x', $sloupce, true)) $db->q('ALTER TABLE media ADD COLUMN focus_x INTEGER NULL');
            if (!in_array('focus_y', $sloupce, true)) $db->q('ALTER TABLE media ADD COLUMN focus_y INTEGER NULL');
            if (!in_array('show_whole', $sloupce, true)) $db->q('ALTER TABLE media ADD COLUMN show_whole INTEGER NOT NULL DEFAULT 0');
        }
    }
}
