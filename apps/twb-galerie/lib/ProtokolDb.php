<?php
declare(strict_types=1);
namespace MediaGalerie;

use TWB\Jadro\{Db, Protokol};

/** Protokol událostí v tabulce log. Úrovně info, warn, error (warning → warn, success → info). */
final class ProtokolDb implements Protokol
{
    public const UCHOVAT = 5000;

    public function __construct(private Db $db) {}

    public function zapis(string $uroven, string $zprava): void
    {
        $uroven = match ($uroven) { 'warning' => 'warn', 'success' => 'info', 'warn', 'error' => $uroven, default => 'info' };
        try {
            $this->db->q('INSERT INTO log (at, level, message) VALUES (?, ?, ?)', [time(), $uroven, mb_substr($zprava, 0, 2000)]);
        } catch (\Throwable) {
            // protokol nesmí shodit aplikaci
        }
    }

    /** @return list<array<string, mixed>> */
    public function posledni(int $pocet = 200, string $uroven = ''): array
    {
        $kde = $uroven !== '' ? 'WHERE level = ?' : '';
        return $this->db->q("SELECT * FROM log $kde ORDER BY id DESC LIMIT " . max(1, min(2000, $pocet)), $uroven !== '' ? [$uroven] : [])->fetchAll();
    }

    /** Smaže nejstarší záznamy nad limit. */
    public function uklid(int $uchovat = self::UCHOVAT): int
    {
        $hranice = (int)$this->db->q('SELECT id FROM log ORDER BY id DESC LIMIT 1 OFFSET ' . max(0, $uchovat - 1))->fetchColumn();
        return $hranice > 0 ? $this->db->q('DELETE FROM log WHERE id < ?', [$hranice])->rowCount() : 0;
    }
}
