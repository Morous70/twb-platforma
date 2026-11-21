<?php
declare(strict_types=1);
namespace TWB\Data;

use TWB\Jadro\{Db, ModulMigrace};

/** Tabulka `data_rows` (Skupina | Parametr | Hodnota) ve tvaru Social Planneru, pro nové aplikace. */
final class Migrace implements ModulMigrace
{
    public static function verze(): int { return 1; }

    public static function proved(Db $db, int $zVerze): void
    {
        $sqlite = $db->jeSqlite();
        $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci';
        $db->q("CREATE TABLE IF NOT EXISTS data_rows (
            id $pk, grp VARCHAR(100) NOT NULL, param VARCHAR(200) NOT NULL, value TEXT NOT NULL,
            updated_at INT NOT NULL DEFAULT 0
        )$engine");
    }
}
