<?php
declare(strict_types=1);
namespace TWB\Galerie;

use TWB\Jadro\{Db, ModulMigrace};

/** Tabulka `media` ve výchozím tvaru (pro nové aplikace, např. TWB Reklamy). Stávající tabulky aplikací se nemění. */
final class Migrace implements ModulMigrace
{
    public static function verze(): int { return 1; }

    public static function proved(Db $db, int $zVerze): void
    {
        $sqlite = $db->jeSqlite();
        $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci';
        $db->q("CREATE TABLE IF NOT EXISTS media (
            id $pk,
            filename VARCHAR(80) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            upload_name VARCHAR(255) NULL,
            mime VARCHAR(100) NOT NULL,
            kind VARCHAR(10) NOT NULL,
            size BIGINT NOT NULL,
            md5 CHAR(32) NOT NULL,
            width INT NULL, height INT NULL,
            orig_filename VARCHAR(80) NULL, orig_mime VARCHAR(100) NULL, orig_size BIGINT NULL, orig_w INT NULL, orig_h INT NULL,
            tags TEXT NULL,
            archived_at DATETIME NULL,
            created_at DATETIME NOT NULL
        )$engine");
    }
}
