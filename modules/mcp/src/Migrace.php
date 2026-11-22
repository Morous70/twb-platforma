<?php
declare(strict_types=1);
namespace TWB\Mcp;

use TWB\Jadro\{Db, ModulMigrace};

/**
 * Tabulky OAuth: klienti (dynamická registrace), připojení (souhlas uživatele), jednorázové kódy, tokeny (jen SHA-256).
 * Do záloh nepatří – obnova by vrátila odebrané připojení.
 */
final class Migrace implements ModulMigrace
{
    /** Tabulky modulu (aplikace je nedává do záloh). */
    public const TABULKY = ['oauth_clients', 'oauth_grants', 'oauth_codes', 'oauth_tokens'];

    public static function verze(): int { return 1; }

    public static function proved(Db $db, int $zVerze): void
    {
        $sqlite = $db->jeSqlite();
        $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci';
        $db->q("CREATE TABLE IF NOT EXISTS oauth_clients (
            client_id VARCHAR(64) PRIMARY KEY, name VARCHAR(100) NOT NULL, redirect_uris TEXT NOT NULL, created_at BIGINT NOT NULL
        )$engine");
        $db->q("CREATE TABLE IF NOT EXISTS oauth_grants (
            id $pk, client_id VARCHAR(64) NOT NULL, client_name VARCHAR(100) NOT NULL, redirect_host VARCHAR(255) NOT NULL,
            scope VARCHAR(255) NOT NULL, created_at BIGINT NOT NULL, last_used BIGINT NULL, uses INT NOT NULL DEFAULT 0, revoked_at BIGINT NULL
        )$engine");
        $db->q("CREATE TABLE IF NOT EXISTS oauth_codes (
            code_hash CHAR(64) PRIMARY KEY, grant_id INT NOT NULL, client_id VARCHAR(64) NOT NULL, redirect_uri TEXT NOT NULL,
            challenge VARCHAR(128) NOT NULL, expires BIGINT NOT NULL
        )$engine");
        $db->q("CREATE TABLE IF NOT EXISTS oauth_tokens (
            token_hash CHAR(64) PRIMARY KEY, kind VARCHAR(10) NOT NULL, grant_id INT NOT NULL, client_id VARCHAR(64) NOT NULL, expires BIGINT NOT NULL
            " . ($sqlite ? '' : ', INDEX oauth_tokens_grant (grant_id)') . "
        )$engine");
        if ($sqlite) $db->q('CREATE INDEX IF NOT EXISTS oauth_tokens_grant ON oauth_tokens (grant_id)');
    }
}
