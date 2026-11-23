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
    public static function verze(): int { return 6; }

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
        if ($zVerze < 4) {
            // cílení (JSON) a frekvence (nejvýš N zobrazení jednomu návštěvníkovi za X hodin)
            // (převod je opakovatelný: přerušený nebo zopakovaný převod nic nezdvojí)
            self::pridejSloupec($db, 'campaigns', 'targeting', 'TEXT NULL');
            self::pridejSloupec($db, 'campaigns', 'cap_impressions', 'INTEGER NULL');
            self::pridejSloupec($db, 'campaigns', 'cap_hours', 'INTEGER NULL');
            // konverze: trackery (kód na webu inzerenta), prokliky a zobrazení návštěvníků pro přiřazení, zaznamenané konverze
            $db->q("CREATE TABLE IF NOT EXISTS trackers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(200) NOT NULL,
                status VARCHAR(10) NOT NULL DEFAULT 'active',      -- active | paused
                kind VARCHAR(10) NOT NULL DEFAULT 'sale',          -- sale | lead | signup | other
                value_default INTEGER NULL,                        -- hodnota v haléřích, když ji kód nepošle
                click_days INTEGER NOT NULL DEFAULT 30,            -- okno přiřazení po prokliku
                view_days INTEGER NOT NULL DEFAULT 0,              -- okno přiřazení po zobrazení (0 = vypnuto)
                once_per_click INTEGER NOT NULL DEFAULT 0,         -- 1 = jedna konverze na proklik/zobrazení
                note TEXT NULL,
                created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
            )");
            $db->q('CREATE TABLE IF NOT EXISTS tracker_campaigns (
                tracker_id INTEGER NOT NULL REFERENCES trackers(id) ON DELETE CASCADE,
                campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
                PRIMARY KEY (tracker_id, campaign_id)
            )');
            $db->q('CREATE TABLE IF NOT EXISTS clicks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                visitor CHAR(32) NULL, banner_id INTEGER NOT NULL, zone_id INTEGER NOT NULL, campaign_id INTEGER NOT NULL, at INTEGER NOT NULL
            )');
            $db->q('CREATE INDEX IF NOT EXISTS clicks_visitor ON clicks (visitor, at)');
            $db->q('CREATE INDEX IF NOT EXISTS clicks_at ON clicks (at)');
            $db->q('CREATE TABLE IF NOT EXISTS views (
                visitor CHAR(32) NOT NULL, banner_id INTEGER NOT NULL, zone_id INTEGER NOT NULL, campaign_id INTEGER NOT NULL, at INTEGER NOT NULL
            )');
            $db->q('CREATE INDEX IF NOT EXISTS views_visitor ON views (visitor, at)');
            $db->q('CREATE INDEX IF NOT EXISTS views_at ON views (at)');
            $db->q("CREATE TABLE IF NOT EXISTS conversions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tracker_id INTEGER NOT NULL REFERENCES trackers(id) ON DELETE CASCADE,
                campaign_id INTEGER NULL, banner_id INTEGER NULL, zone_id INTEGER NULL,
                click_id INTEGER NULL,
                via VARCHAR(5) NOT NULL DEFAULT '',                -- click | view | '' (nepřiřazená)
                value INTEGER NOT NULL DEFAULT 0,                  -- haléře
                ref VARCHAR(100) NULL,                             -- číslo objednávky (kvůli duplicitám)
                at INTEGER NOT NULL
            )");
            $db->q('CREATE UNIQUE INDEX IF NOT EXISTS conversions_ref ON conversions (tracker_id, ref)');
            $db->q('CREATE INDEX IF NOT EXISTS conversions_at ON conversions (at)');
            $db->q('CREATE INDEX IF NOT EXISTS conversions_click ON conversions (tracker_id, click_id)');
        }
        if ($zVerze < 5) self::bannerVeViceKampanich($db);
        if ($zVerze < 6) self::zarizeni($db);
    }

    /**
     * Verze 6 (0.9): reklamy podle zařízení.
     *  - zones.sizes: responzivní zóna = seznam rozměrů (JSON ["970x250", "728x90", …]), vybere se banner, který se vejde do šířky místa;
     *  - banners.devices: banner jen pro některá zařízení (čárkami „pocitac,tablet,mobil“, NULL = všechna);
     *  - banners.media2x_id / image2x_url: obrázek pro displeje s vyšší hustotou (2×);
     *  - stats.device (součást klíče), clicks.device, views.device: statistiky po zařízeních ('' = neznámé, data ze starších verzí).
     * Převod je opakovatelný (obnovená záloha z 0.8 má tabulky už v novém tvaru).
     */
    private static function zarizeni(Db $db): void
    {
        self::pridejSloupec($db, 'zones', 'sizes', 'TEXT NULL');
        self::pridejSloupec($db, 'banners', 'devices', 'VARCHAR(30) NULL');
        self::pridejSloupec($db, 'banners', 'media2x_id', 'INTEGER NULL');
        self::pridejSloupec($db, 'banners', 'image2x_url', 'TEXT NULL');
        self::pridejSloupec($db, 'clicks', 'device', "VARCHAR(8) NOT NULL DEFAULT ''");
        self::pridejSloupec($db, 'views', 'device', "VARCHAR(8) NOT NULL DEFAULT ''");
        if (!self::maSloupec($db, 'stats', 'device')) {
            $db->q("CREATE TABLE stats_nova (
                day CHAR(10) NOT NULL, hour INTEGER NOT NULL,
                banner_id INTEGER NOT NULL, zone_id INTEGER NOT NULL DEFAULT 0, campaign_id INTEGER NOT NULL DEFAULT 0,
                device VARCHAR(8) NOT NULL DEFAULT '',             -- pocitac | tablet | mobil | '' (neznámé, starší data)
                impressions INTEGER NOT NULL DEFAULT 0, clicks INTEGER NOT NULL DEFAULT 0,
                conversions INTEGER NOT NULL DEFAULT 0, revenue INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (day, hour, banner_id, zone_id, campaign_id, device)
            )");
            $db->q('INSERT INTO stats_nova (day, hour, banner_id, zone_id, campaign_id, impressions, clicks, conversions, revenue)
                SELECT day, hour, banner_id, zone_id, campaign_id, impressions, clicks, conversions, revenue FROM stats');
            $db->q('DROP TABLE stats');
            $db->q('ALTER TABLE stats_nova RENAME TO stats');
        }
        $db->q('CREATE INDEX IF NOT EXISTS stats_banner ON stats (banner_id, day)');
        $db->q('CREATE INDEX IF NOT EXISTS stats_campaign ON stats (campaign_id, day)');
        $db->q('CREATE INDEX IF NOT EXISTS banners_media2x ON banners (media2x_id)');
    }

    /**
     * Verze 5 (0.8): banner může být v libovolně mnoha kampaních (tabulka campaign_banners).
     * Sloupec banners.campaign_id zůstává jen jako kampaň, pro kterou banner vznikl (bez cizího klíče, smí být NULL);
     * podle něj se převedou i zálohy ze starších verzí (obnova vloží sloupec campaign_id a pak proběhne tento převod).
     * Statistiky, vložení odkazem a prokliky si pamatují kampaň, ve které se banner zobrazil.
     * Převod je opakovatelný. Volá se s vypnutými cizími klíči (App::migruj), jinak by přestavba tabulky banners smazala přiřazení do zón.
     */
    private static function bannerVeViceKampanich(Db $db): void
    {
        $db->q('CREATE TABLE IF NOT EXISTS campaign_banners (
            campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
            banner_id INTEGER NOT NULL REFERENCES banners(id) ON DELETE CASCADE,
            PRIMARY KEY (campaign_id, banner_id)
        )');
        $db->q('CREATE INDEX IF NOT EXISTS campaign_banners_banner ON campaign_banners (banner_id)');
        $fk = array_filter($db->q('PRAGMA foreign_key_list(banners)')->fetchAll(\PDO::FETCH_ASSOC), fn($r) => $r['from'] === 'campaign_id');
        if ($fk) {
            $db->q("CREATE TABLE banners_nova (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id INTEGER NULL,                          -- kampaň, pro kterou banner vznikl (jen informace); přiřazení je v campaign_banners
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
            $sl = 'id, campaign_id, name, status, type, width, height, weight, media_id, image_url, html, content, target_url, target, alt, note, created_at, updated_at';
            $db->q("INSERT INTO banners_nova ($sl) SELECT $sl FROM banners");
            $db->q('DROP TABLE banners');
            $db->q('ALTER TABLE banners_nova RENAME TO banners');
            $db->q('CREATE INDEX IF NOT EXISTS banners_campaign ON banners (campaign_id)');
            $db->q('CREATE INDEX IF NOT EXISTS banners_media ON banners (media_id)');
        }
        $db->q('INSERT OR IGNORE INTO campaign_banners (campaign_id, banner_id)
            SELECT b.campaign_id, b.id FROM banners b WHERE b.campaign_id IN (SELECT id FROM campaigns)');
        // statistiky po kampaních (stejný banner ve dvou kampaních má dva řádky)
        if (!self::maSloupec($db, 'stats', 'campaign_id')) {
            $db->q('CREATE TABLE stats_nova (
                day CHAR(10) NOT NULL, hour INTEGER NOT NULL,
                banner_id INTEGER NOT NULL, zone_id INTEGER NOT NULL DEFAULT 0, campaign_id INTEGER NOT NULL DEFAULT 0,
                impressions INTEGER NOT NULL DEFAULT 0, clicks INTEGER NOT NULL DEFAULT 0,
                conversions INTEGER NOT NULL DEFAULT 0, revenue INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (day, hour, banner_id, zone_id, campaign_id)
            )');
            $db->q('INSERT INTO stats_nova (day, hour, banner_id, zone_id, campaign_id, impressions, clicks, conversions, revenue)
                SELECT s.day, s.hour, s.banner_id, s.zone_id, COALESCE((SELECT b.campaign_id FROM banners b WHERE b.id = s.banner_id), 0),
                    s.impressions, s.clicks, s.conversions, s.revenue FROM stats s');
            $db->q('DROP TABLE stats');
            $db->q('ALTER TABLE stats_nova RENAME TO stats');
            $db->q('CREATE INDEX IF NOT EXISTS stats_banner ON stats (banner_id, day)');
        } else {
            // obnovená záloha ze starší verze: sloupec je, ale kampaň chybí
            $db->q('UPDATE stats SET campaign_id = COALESCE((SELECT b.campaign_id FROM banners b WHERE b.id = stats.banner_id), 0) WHERE campaign_id = 0');
        }
        $db->q('CREATE INDEX IF NOT EXISTS stats_campaign ON stats (campaign_id, day)');
        self::pridejSloupec($db, 'view_map', 'campaign_id', 'INTEGER NOT NULL DEFAULT 0');
        $chyby = $db->q('PRAGMA foreign_key_check')->fetchAll();
        if ($chyby) throw new \RuntimeException('Převod bannerů do více kampaní: porušené vazby v tabulce ' . $chyby[0]['table'] . '.');
    }

    private static function maSloupec(Db $db, string $tabulka, string $sloupec): bool
    {
        foreach ($db->q('PRAGMA table_info(' . $tabulka . ')')->fetchAll(\PDO::FETCH_ASSOC) as $r) if ($r['name'] === $sloupec) return true;
        return false;
    }

    /** ALTER TABLE … ADD COLUMN, jen když sloupec ještě není. */
    private static function pridejSloupec(Db $db, string $tabulka, string $sloupec, string $definice): void
    {
        if (self::maSloupec($db, $tabulka, $sloupec)) return;
        $db->q("ALTER TABLE {$tabulka} ADD COLUMN {$sloupec} {$definice}");
    }
}
