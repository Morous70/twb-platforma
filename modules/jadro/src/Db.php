<?php
declare(strict_types=1);
namespace TWB\Jadro;

use PDO;
use PDOStatement;

/** Tenký obal nad PDO (SQLite i MySQL). */
final class Db
{
    public function __construct(private PDO $pdo) {}

    public static function pripoj(string $dsn, ?string $uzivatel = null, ?string $heslo = null): self
    {
        $pdo = new PDO($dsn, $uzivatel, $heslo, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        if (str_starts_with($dsn, 'sqlite:')) $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
        return new self($pdo);
    }

    public function pdo(): PDO { return $this->pdo; }

    public function jeSqlite(): bool { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'; }

    /** @param array<int|string, mixed> $parametry */
    public function q(string $sql, array $parametry = []): PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($parametry);
        return $st;
    }

    public function posledniId(): int { return (int)$this->pdo->lastInsertId(); }

    /** Provede funkci v transakci; při výjimce vrátí změny zpět. */
    public function transakce(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) return $fn($this);
        $this->pdo->beginTransaction();
        try {
            $v = $fn($this);
            if ($this->pdo->inTransaction()) $this->pdo->commit();   // MySQL po DDL transakci už ukončil sám
            return $v;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Ověří název tabulky nebo sloupce (do SQL se vkládá přímo, proto jen bezpečné znaky). */
    public static function identifikator(string $nazev): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $nazev)) throw new \InvalidArgumentException("Neplatný název v databázi: „{$nazev}“.");
        return '`' . $nazev . '`';
    }
}
