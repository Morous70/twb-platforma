<?php
declare(strict_types=1);
namespace TWB\Prihlaseni;

use TWB\Jadro\{Nastaveni, Protokol};

/** Obnova hesla odkazem poslaným e-mailem. Odpověď uživateli je vždy stejná, aby nešlo zjistit, jaká adresa je nastavená. */
final class Obnova
{
    public const PLATNOST_SEKUND = 1800;
    public const MEZERA_SEKUND = 120;

    /**
     * @param callable(string, string, string): bool $posli       odešle e-mail (komu, předmět, text)
     * @param callable(): string $adresa                          adresa, na kterou smí odkaz přijít
     * @param string $odkazSablona                                {zaklad} a {token} se nahradí (např. „{zaklad}/?reset={token}“)
     */
    public function __construct(
        private Nastaveni $nastaveni,
        private Tokeny $tokeny,
        private Heslo $heslo,
        private Zamek $zamek,
        private Protokol $protokol,
        private $posli,
        private $adresa,
        private string $zakladniUrl,
        private string $nazevAplikace,
        private string $odkazSablona = '{zaklad}/?reset={token}',
    ) {}

    public function zadost(string $email): void
    {
        $komu = trim((string)($this->adresa)());
        if (time() - (int)$this->nastaveni->dej('reset_last_sent', 0) < self::MEZERA_SEKUND) return;
        $this->nastaveni->uloz('reset_last_sent', (string)time());
        if ($komu === '') { $this->protokol->zapis('warning', 'Žádost o obnovu hesla: není vyplněný e-mail pro upozornění, odkaz nebyl odeslán.'); return; }
        if (strcasecmp($komu, trim($email)) !== 0) { $this->protokol->zapis('warning', 'Žádost o obnovu hesla: zadaná adresa nesouhlasí s adresou pro upozornění, odkaz nebyl odeslán.'); return; }
        $this->tokeny->zneplatni('reset');
        $url = strtr($this->odkazSablona, ['{zaklad}' => rtrim($this->zakladniUrl, '/'), '{token}' => $this->tokeny->vytvor('reset', self::PLATNOST_SEKUND)]);
        $ok = ($this->posli)($komu, 'Obnovení hesla – ' . $this->nazevAplikace,
            "Dobrý den,\n\nbyla podána žádost o obnovení hesla do aplikace {$this->nazevAplikace}.\nNové heslo nastavíte na tomto odkazu (platí 30 minut a jde použít jen jednou):\n\n$url\n\n"
            . "Pokud jste o obnovení nežádali, tento e-mail ignorujte. Heslo se nezměnilo.\n");
        $this->protokol->zapis($ok ? 'info' : 'error', $ok ? 'Žádost o obnovu hesla: odkaz odeslán e-mailem.' : 'Žádost o obnovu hesla: e-mail se nepodařilo odeslat.');
    }

    /** Nastaví nové heslo podle odkazu z e-mailu. Vyhodí RuntimeException s textem pro uživatele. */
    public function pouzij(string $token, string $noveHeslo): void
    {
        if (mb_strlen($noveHeslo) < Heslo::MIN_DELKA) throw new \RuntimeException('Nové heslo musí mít aspoň ' . Heslo::MIN_DELKA . ' znaků.');
        if (!$this->tokeny->pouzij('reset', $token)) throw new \RuntimeException('Odkaz už neplatí. Požádejte o nový.');
        $this->heslo->nastav($noveHeslo);
        $this->zamek->vynuluj();
        $this->tokeny->zneplatni('reset');
        $this->protokol->zapis('info', 'Heslo bylo změněno odkazem pro obnovu hesla.');
    }
}
