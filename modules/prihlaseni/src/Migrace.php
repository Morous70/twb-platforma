<?php
declare(strict_types=1);
namespace TWB\Prihlaseni;

use TWB\Jadro\{Db, ModulMigrace};

/** Tabulky přihlášení. `IF NOT EXISTS` – stávající tabulky aplikací (Plánovač, Social Planner) se nemění. */
final class Migrace implements ModulMigrace
{
    public static function verze(): int { return 1; }

    public static function proved(Db $db, int $zVerze): void
    {
        $sqlite = $db->jeSqlite();
        $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci';
        $db->q("CREATE TABLE IF NOT EXISTS auth_tokens (
            id $pk, purpose VARCHAR(20) NOT NULL, token_hash CHAR(64) NOT NULL, expires BIGINT NOT NULL, used TINYINT NOT NULL DEFAULT 0,
            UNIQUE (token_hash)
        )$engine");
        $db->q("CREATE TABLE IF NOT EXISTS passkeys (
            id $pk, name VARCHAR(100) NOT NULL, cred_hash CHAR(64) NOT NULL, cred_id TEXT NOT NULL,
            public_key TEXT NOT NULL, alg INT NOT NULL, sign_count BIGINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL, last_used DATETIME NULL,
            UNIQUE (cred_hash)
        )$engine");
    }
}
