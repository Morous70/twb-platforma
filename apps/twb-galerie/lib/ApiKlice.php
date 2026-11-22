<?php
declare(strict_types=1);
namespace MediaGalerie;

/**
 * API klíče: v databázi jen SHA-256 otisk a prvních 8 znaků pro rozpoznání. Celý klíč se ukáže jednou při vytvoření.
 * Oprávnění: read (čtení) nebo write (čtení i zápis). Tabulka api_keys se nezálohuje – obnova zálohy by vrátila zrušený klíč.
 */
final class ApiKlice
{
    public const PREDPONA = 'tgk_';
    public const OPRAVNENI = ['read' => 'Čtení', 'write' => 'Čtení i zápis'];
    /** Po tolika neplatných klíčích za 5 minut API odpovídá 429 (ochrana proti zkoušení klíčů). */
    public const MAX_CHYB = 20;
    public const OKNO_SEKUND = 300;

    public function __construct(private App $app) {}

    /** Vytvoří klíč. Vrací celý klíč (ukázat jen jednou). */
    public function vytvor(string $nazev, string $opravneni): string
    {
        $nazev = trim($nazev);
        if ($nazev === '' || mb_strlen($nazev) > 100) throw new Chyba('Zadejte název klíče (nejvýš 100 znaků), např. podle webu nebo skriptu, který ho použije.');
        if (!isset(self::OPRAVNENI[$opravneni])) throw new Chyba('Neplatné oprávnění.');
        $klic = self::PREDPONA . bin2hex(random_bytes(20));
        $this->app->db->q('INSERT INTO api_keys (name, prefix, key_hash, scope, created_at) VALUES (?, ?, ?, ?, ?)',
            [$nazev, substr($klic, 0, 12), hash('sha256', $klic), $opravneni, time()]);
        $this->app->log('info', "Vytvořen API klíč „{$nazev}“ (" . self::OPRAVNENI[$opravneni] . ').');
        return $klic;
    }

    /** @return list<array<string, mixed>> */
    public function seznam(): array
    {
        return $this->app->db->q('SELECT id, name, prefix, scope, created_at, last_used_at FROM api_keys ORDER BY id')->fetchAll();
    }

    public function smaz(int $id): void
    {
        $r = $this->app->db->q('SELECT name FROM api_keys WHERE id = ?', [$id])->fetch() ?: throw new Chyba('Klíč nenalezen.');
        $this->app->db->q('DELETE FROM api_keys WHERE id = ?', [$id]);
        $this->app->log('info', "Zrušen API klíč „{$r['name']}“.");
    }

    /** Ověří klíč. Vrací řádek klíče, nebo null. Čas posledního použití se zapíše nejvýš jednou za minutu. */
    public function over(string $klic): ?array
    {
        if (!preg_match('/^' . self::PREDPONA . '[0-9a-f]{40}$/', $klic)) return null;
        $r = $this->app->db->q('SELECT * FROM api_keys WHERE key_hash = ?', [hash('sha256', $klic)])->fetch();
        if (!$r) return null;
        if ((int)$r['last_used_at'] < time() - 60) $this->app->db->q('UPDATE api_keys SET last_used_at = ? WHERE id = ?', [time(), $r['id']]);
        return $r;
    }

    /** Zbývající sekundy zámku po mnoha neplatných klíčích (0 = volno). */
    public function zamceno(): int
    {
        $n = $this->app->nastaveni();
        $od = (int)$n->dej('api_fail_since', 0);
        if ($od < time() - self::OKNO_SEKUND) return 0;
        return (int)$n->dej('api_fails', 0) >= self::MAX_CHYB ? $od + self::OKNO_SEKUND - time() : 0;
    }

    /** Zapíše neplatný pokus; do protokolu jen první pokus v okně a dosažení zámku. */
    public function neuspech(): void
    {
        $n = $this->app->nastaveni();
        $od = (int)$n->dej('api_fail_since', 0);
        if ($od < time() - self::OKNO_SEKUND) {
            $n->uloz('api_fail_since', (string)time());
            $n->uloz('api_fails', '1');
            $this->app->log('warn', 'API: požadavek s neplatným klíčem.');
            return;
        }
        $pocet = (int)$n->dej('api_fails', 0) + 1;
        $n->uloz('api_fails', (string)$pocet);
        if ($pocet === self::MAX_CHYB) $this->app->log('warn', 'API: příliš mnoho neplatných klíčů, API je na chvíli zamčené.');
    }
}
