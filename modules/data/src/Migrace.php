<?php
declare(strict_types=1);
namespace TWB\Data;

use TWB\Jadro\{Db, ModulMigrace};

/** Tabulky `data_rows` (Skupina | Parametr | Hodnota), `sources` (vzdálené zdroje) a `weather` (mezipaměť počasí) ve tvaru Social Planneru, pro nové aplikace. */
final class Migrace implements ModulMigrace
{
    public static function verze(): int { return 3; }

    public static function proved(Db $db, int $zVerze): void
    {
        $sqlite = $db->jeSqlite();
        $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci';
        $db->q("CREATE TABLE IF NOT EXISTS data_rows (
            id $pk, grp VARCHAR(100) NOT NULL, param VARCHAR(200) NOT NULL, value TEXT NOT NULL,
            updated_at INT NOT NULL DEFAULT 0
        )$engine");
        if ($zVerze < 2) {
            $velky = $sqlite ? 'TEXT' : 'MEDIUMTEXT';
            $db->q("CREATE TABLE IF NOT EXISTS sources (
                id $pk, name VARCHAR(60) NOT NULL, url TEXT NOT NULL, format VARCHAR(8) NOT NULL DEFAULT 'json',
                csv_header INT NOT NULL DEFAULT 1, interval_min INT NOT NULL DEFAULT 15,
                payload $velky NULL, hash CHAR(32) NULL, fetched_at INT NULL, ok_at INT NULL, error TEXT NULL,
                created_at INT NOT NULL DEFAULT 0
            )$engine");
        }
        if ($zVerze < 3) {
            $db->q("CREATE TABLE IF NOT EXISTS weather (
                place_key VARCHAR(100) NOT NULL PRIMARY KEY, place VARCHAR(100) NOT NULL, payload TEXT NULL,
                fetched_at INT NULL, ok_at INT NULL, error TEXT NULL
            )$engine");
        }
    }
}
