<?php
declare(strict_types=1);
namespace TWB\Prihlaseni;

use TWB\Jadro\Db;

/** Jednorázové tokeny (obnova hesla, výzvy Passkey). V databázi je jen jejich SHA-256. */
final class Tokeny
{
    public function __construct(private Db $db) {}

    public function vytvor(string $ucel, int $platnostSekund, ?string $surovy = null): string
    {
        $this->db->q('DELETE FROM auth_tokens WHERE expires < ?', [time() - 3600]);
        $surovy ??= bin2hex(random_bytes(32));
        $this->db->q('INSERT INTO auth_tokens (purpose, token_hash, expires, used) VALUES (?, ?, ?, 0)', [$ucel, hash('sha256', $surovy), time() + $platnostSekund]);
        return $surovy;
    }

    public function platny(string $ucel, string $surovy): bool
    {
        if ($surovy === '' || strlen($surovy) > 512) return false;
        return (bool)$this->db->q('SELECT 1 FROM auth_tokens WHERE purpose = ? AND token_hash = ? AND used = 0 AND expires >= ?', [$ucel, hash('sha256', $surovy), time()])->fetchColumn();
    }

    /** Spotřebuje token (jen jednou). */
    public function pouzij(string $ucel, string $surovy): bool
    {
        if ($surovy === '' || strlen($surovy) > 512) return false;
        return $this->db->q('UPDATE auth_tokens SET used = 1 WHERE purpose = ? AND token_hash = ? AND used = 0 AND expires >= ?', [$ucel, hash('sha256', $surovy), time()])->rowCount() === 1;
    }

    /** Zneplatní všechny nepoužité tokeny daného účelu. */
    public function zneplatni(string $ucel): void { $this->db->q('UPDATE auth_tokens SET used = 1 WHERE purpose = ?', [$ucel]); }

    public function pocetPlatnych(string $ucel): int
    {
        return (int)$this->db->q('SELECT COUNT(*) FROM auth_tokens WHERE purpose = ? AND used = 0 AND expires >= ?', [$ucel, time()])->fetchColumn();
    }
}
