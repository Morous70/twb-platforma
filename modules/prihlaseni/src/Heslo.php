<?php
declare(strict_types=1);
namespace TWB\Prihlaseni;

use TWB\Jadro\Nastaveni;

/** Heslo správce. Otisk je v nastavení `password_hash`; volitelný záložní otisk (např. z konfigurace) platí, dokud heslo není nastavené. */
final class Heslo
{
    public const MIN_DELKA = 8;

    public function __construct(private Nastaveni $nastaveni, private string $zalozniHash = '') {}

    public function otisk(): string
    {
        $h = (string)$this->nastaveni->dej('password_hash', '');
        return $h !== '' ? $h : $this->zalozniHash;
    }

    public function jeNastavene(): bool { return $this->otisk() !== ''; }

    public function over(string $heslo): bool
    {
        $o = $this->otisk();
        return $o !== '' && password_verify($heslo, $o);
    }

    public function nastav(string $nove): void
    {
        if (mb_strlen($nove) < self::MIN_DELKA) throw new \RuntimeException('Nové heslo musí mít aspoň ' . self::MIN_DELKA . ' znaků.');
        $this->nastaveni->uloz('password_hash', password_hash($nove, PASSWORD_DEFAULT));
    }
}
