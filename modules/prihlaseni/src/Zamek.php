<?php
declare(strict_types=1);
namespace TWB\Prihlaseni;

use TWB\Jadro\{Nastaveni, Protokol};

/** Omezení pokusů o přihlášení, společné pro heslo, obnovu, Google i Passkey (stav je v nastavení, ne v relaci). */
final class Zamek
{
    public const MAX_POKUSU = 5;
    public const ZAMEK_SEKUND = 300;

    public function __construct(private Nastaveni $nastaveni, private Protokol $protokol) {}

    /** Kolik sekund ještě trvá zámek (0 = přihlašovat lze). */
    public function zbyva(): int
    {
        $fails = (int)$this->nastaveni->dej('login_fails', 0);
        $last = (int)$this->nastaveni->dej('login_last_fail', 0);
        if ($fails >= self::MAX_POKUSU && time() - $last < self::ZAMEK_SEKUND) return self::ZAMEK_SEKUND - (time() - $last);
        return 0;
    }

    public function zprava(): string
    {
        return 'Příliš mnoho pokusů. Zkuste to za ' . max(1, (int)ceil($this->zbyva() / 60)) . ' min.';
    }

    public function neuspech(string $jak): void
    {
        $fails = (int)$this->nastaveni->dej('login_fails', 0);
        if ($fails >= self::MAX_POKUSU && time() - (int)$this->nastaveni->dej('login_last_fail', 0) >= self::ZAMEK_SEKUND) $fails = 0;   // zámek vypršel
        $fails++;
        $this->nastaveni->uloz('login_fails', (string)$fails);
        $this->nastaveni->uloz('login_last_fail', (string)time());
        if ($fails === self::MAX_POKUSU) $this->protokol->zapis('warning', "Přihlášení dočasně zablokováno na 5 minut po $fails neúspěšných pokusech ($jak).");
    }

    public function vynuluj(): void { $this->nastaveni->uloz('login_fails', '0'); }
}
