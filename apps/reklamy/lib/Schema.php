<?php
declare(strict_types=1);
namespace Reklamy;

use TWB\Jadro\{Db, ModulMigrace};

/**
 * Databázové schéma aplikace (SQLite). Verze se ukládá v module_versions pod „reklamy“
 * a do nastavení schema_version (podle ní moduly zalohy a aktualizace poznají starší data).
 * Časy jsou unixové (celá čísla), peníze v haléřích.
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
            $db->q("CREATE TABLE campaigns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(200) NOT NULL,
                status VARCHAR(10) NOT NULL DEFAULT 'active',      -- active | paused | archived
                kind VARCHAR(10) NOT NULL DEFAULT 'standard',      -- exclusive | standard | remnant
                priority INTEGER NOT NULL DEFAULT 5,               -- 1–10 v rámci druhu
                weight INTEGER NOT NULL DEFAULT 1,
                starts_at INTEGER NULL, ends_at INTEGER NULL,
                limit_impressions INTEGER NULL, limit_clicks INTEGER NULL, limit_conversions INTEGER NULL,
                daily_impressions INTEGER NULL, daily_clicks INTEGER NULL,
                tags TEXT NULL, note TEXT NULL,
                created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
            )");
            $db->q("CREATE TABLE banners (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
                name VARCHAR(200) NOT NULL,
                status VARCHAR(10) NOT NULL DEFAULT 'active',      -- active | paused
                type VARCHAR(10) NOT NULL,                         -- image | external | html | html5 | slide
                width INTEGER NOT NULL, height INTEGER NOT NULL,
                weight INTEGER NOT NULL DEFAULT 1,
                media_id INTEGER NULL,
                image_url TEXT NULL,
                html TEXT NULL,
                content TEXT NULL,                                 -- JSON pro HTML5 a slidy
                target_url TEXT NULL, target VARCHAR(10) NOT NULL DEFAULT '_blank',
                alt VARCHAR(255) NULL, note TEXT NULL,
                created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
            )");
            $db->q('CREATE INDEX banners_campaign ON banners (campaign_id)');
            $db->q('CREATE INDEX banners_media ON banners (media_id)');
            $db->q("CREATE TABLE sites (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(200) NOT NULL, url TEXT NULL, note TEXT NULL,
                created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
            )");
            $db->q("CREATE TABLE zones (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
                name VARCHAR(200) NOT NULL,
                kind VARCHAR(10) NOT NULL DEFAULT 'banner',        -- banner | popup
                width INTEGER NULL, height INTEGER NULL,           -- NULL = libovolný rozměr
                fallback TEXT NULL,                                -- HTML, když není co zobrazit
                note TEXT NULL,
                created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
            )");
            $db->q('CREATE INDEX zones_site ON zones (site_id)');
            $db->q('CREATE TABLE zone_campaigns (
                zone_id INTEGER NOT NULL REFERENCES zones(id) ON DELETE CASCADE,
                campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
                PRIMARY KEY (zone_id, campaign_id)
            )');
            $db->q('CREATE TABLE zone_banners (
                zone_id INTEGER NOT NULL REFERENCES zones(id) ON DELETE CASCADE,
                banner_id INTEGER NOT NULL REFERENCES banners(id) ON DELETE CASCADE,
                PRIMARY KEY (zone_id, banner_id)
            )');
        }
        if ($zVerze < 2) {
            // statistiky po hodinách: zobrazení, prokliky, konverze, hodnota (v haléřích); zone_id 0 = bez zóny
            $db->q('CREATE TABLE stats (
                day CHAR(10) NOT NULL, hour INTEGER NOT NULL,
                banner_id INTEGER NOT NULL, zone_id INTEGER NOT NULL DEFAULT 0,
                impressions INTEGER NOT NULL DEFAULT 0, clicks INTEGER NOT NULL DEFAULT 0,
                conversions INTEGER NOT NULL DEFAULT 0, revenue INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (day, hour, banner_id, zone_id)
            )');
            $db->q('CREATE INDEX stats_banner ON stats (banner_id, day)');
            // vazba obrázku a prokliku u vložení odkazem (obr.php a klik.php se stejným n), i bez cookies
            $db->q('CREATE TABLE view_map (
                n VARCHAR(64) NOT NULL, zone_id INTEGER NOT NULL, banner_id INTEGER NOT NULL, at INTEGER NOT NULL,
                PRIMARY KEY (n, zone_id)
            )');
            $db->q('CREATE INDEX view_map_at ON view_map (at)');
        }
        // verze 3 (0.3): jen nové tabulky modulu data (data_rows …) a obsah banerů html5 / slide ve sloupci content;
        // vlastní tabulky aplikace se nemění – verze se zvyšuje kvůli zálohám a návratu aktualizace.
    }
}
