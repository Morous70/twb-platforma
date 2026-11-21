<?php
declare(strict_types=1);
namespace Reklamy;

/** Pomůcky pro stránky administrace: rozvržení, formuláře, hlášky, ochrana CSRF. */
final class Ui
{
    public const MENU = [
        'prehled' => 'Přehled',
        'kampane' => 'Kampaně',
        'bannery' => 'Bannery',
        'weby' => 'Weby a zóny',
        'galerie' => 'Galerie',
        'promenne' => 'Proměnné',
        'nastaveni' => 'Nastavení',
        'protokol' => 'Protokol',
    ];

    /** @var list<string> chyby k zobrazení na této stránce (neplatný formulář) */
    public array $chyby = [];

    public function __construct(private App $app) {}

    public static function h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    /** Odkaz na stránku administrace. @param array<string, scalar|null> $parametry */
    public static function odkaz(string $stranka, array $parametry = []): string
    {
        $parametry = array_filter($parametry, fn($v) => $v !== null && $v !== '');
        return 'index.php?' . http_build_query(['p' => $stranka] + $parametry);
    }

    public function csrf(): string
    {
        return '<input type="hidden" name="csrf" value="' . self::h($this->app->relace()->csrfToken()) . '">';
    }

    public function zprava(string $typ, string $text): void { $_SESSION['flash'][] = [$typ, $text]; }

    public function presmeruj(string $kam): never
    {
        header('Location: ' . $kam, true, 303);
        exit;
    }

    /** Provede akci formuláře. Chyba se uloží k zobrazení (formulář zůstane vyplněný) a vrátí se false. */
    public function zpracuj(callable $akce): bool
    {
        try {
            $akce();
            return true;
        } catch (Chyba | \InvalidArgumentException $e) {
            $this->chyby[] = $e->getMessage();
        } catch (\RuntimeException $e) {
            $this->chyby[] = $e->getMessage();
            $this->app->log('warn', 'Akce se nezdařila: ' . $e->getMessage());
        }
        return false;
    }

    public static function datum(mixed $t, bool $sCasem = true): string
    {
        if ($t === null || $t === '' || (int)$t === 0) return '–';
        return date($sCasem ? 'j. n. Y H:i' : 'j. n. Y', (int)$t);
    }

    public static function cislo(mixed $n): string { return number_format((float)$n, 0, ',', "\u{00A0}"); }

    public static function stav(string $stav, string $text): string
    {
        return '<span class="stav stav-' . self::h($stav) . '">' . self::h($text) . '</span>';
    }

    // ---------- formuláře ----------

    /** @param array<string, string|int|bool|null> $atributy */
    private static function atributy(array $atributy): string
    {
        $s = '';
        foreach ($atributy as $k => $v) {
            if ($v === false || $v === null) continue;
            $s .= ' ' . self::h($k) . ($v === true ? '' : '="' . self::h($v) . '"');
        }
        return $s;
    }

    /** Brát hodnoty polí z odeslaného formuláře? (Vypněte u formuláře, který odeslán nebyl, když je na stránce víc formulářů.) */
    public static bool $zPostu = true;

    /** Hodnota pole: po neúspěšném odeslání z formuláře, jinak z databáze. */
    public static function hodnota(string $nazev, mixed $vychozi): string
    {
        if (self::$zPostu && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST[$nazev]) && is_scalar($_POST[$nazev])) return (string)$_POST[$nazev];
        return (string)($vychozi ?? '');
    }

    /** @param array<string, string|int|bool|null> $atributy */
    public static function pole(string $popis, string $nazev, mixed $hodnota = '', array $atributy = [], string $napoveda = ''): string
    {
        $id = 'f-' . $nazev;
        $atributy += ['type' => 'text'];
        $h = $atributy['type'] === 'password' ? '' : self::hodnota($nazev, $hodnota);   // heslo se do stránky nikdy nevrací
        return '<label class="pole" for="' . $id . '"><span>' . self::h($popis) . '</span>'
            . '<input id="' . $id . '" name="' . self::h($nazev) . '" value="' . self::h($h) . '"' . self::atributy($atributy) . '>'
            . ($napoveda !== '' ? '<small>' . $napoveda . '</small>' : '') . '</label>';
    }

    /** @param array<string|int, string> $volby @param array<string, string|int|bool|null> $atributy */
    public static function vyber(string $popis, string $nazev, array $volby, mixed $hodnota, array $atributy = [], string $napoveda = ''): string
    {
        $h = self::hodnota($nazev, $hodnota);
        $o = '';
        foreach ($volby as $k => $t) $o .= '<option value="' . self::h($k) . '"' . ((string)$k === $h ? ' selected' : '') . '>' . self::h($t) . '</option>';
        return '<label class="pole" for="f-' . $nazev . '"><span>' . self::h($popis) . '</span><select id="f-' . $nazev . '" name="' . self::h($nazev) . '"'
            . self::atributy($atributy) . ">$o</select>" . ($napoveda !== '' ? '<small>' . $napoveda . '</small>' : '') . '</label>';
    }

    /** @param array<string, string|int|bool|null> $atributy */
    public static function oblast(string $popis, string $nazev, mixed $hodnota = '', array $atributy = [], string $napoveda = ''): string
    {
        return '<label class="pole" for="f-' . $nazev . '"><span>' . self::h($popis) . '</span><textarea id="f-' . $nazev . '" name="' . self::h($nazev) . '"'
            . self::atributy($atributy + ['rows' => 4]) . '>' . self::h(self::hodnota($nazev, $hodnota)) . '</textarea>'
            . ($napoveda !== '' ? '<small>' . $napoveda . '</small>' : '') . '</label>';
    }

    /** Tlačítko odesílající malý formulář s akcí (pozastavit, smazat …). @param array<string, scalar> $data */
    public function tlacitko(string $text, string $akce, array $data = [], string $trida = '', string $potvrzeni = ''): string
    {
        $s = '<form method="post" class="inline"' . ($potvrzeni !== '' ? ' data-potvrdit="' . self::h($potvrzeni) . '"' : '') . '>' . $this->csrf()
            . '<input type="hidden" name="akce" value="' . self::h($akce) . '">';
        foreach ($data as $k => $v) $s .= '<input type="hidden" name="' . self::h($k) . '" value="' . self::h($v) . '">';
        return $s . '<button type="submit" class="' . self::h($trida ?: 'maly') . '">' . self::h($text) . '</button></form>';
    }

    // ---------- rozvržení ----------

    private function hlavicka(string $titulek, string $trida = ''): string
    {
        $v = self::h($this->app->verze);
        return "<!doctype html>\n<html lang=\"cs\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
            . '<meta name="robots" content="noindex, nofollow"><title>' . self::h($titulek) . ' – ' . App::NAZEV . '</title>'
            . "<link rel=\"stylesheet\" href=\"assets/style.css?v=$v\"><link rel=\"icon\" href=\"assets/ikona.svg\" type=\"image/svg+xml\">"
            . "<script src=\"assets/app.js?v=$v\" defer></script></head><body class=\"" . self::h($trida) . '">';
    }

    private function hlasky(): string
    {
        $out = '';
        foreach ($_SESSION['flash'] ?? [] as [$typ, $text]) $out .= '<div class="hlaska hlaska-' . self::h($typ) . '">' . self::h($text) . '</div>';
        unset($_SESSION['flash']);
        foreach ($this->chyby as $c) $out .= '<div class="hlaska hlaska-chyba">' . self::h($c) . '</div>';
        return $out;
    }

    /** Celá stránka administrace s menu. */
    public function stranka(string $titulek, string $aktivni, string $obsah): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        $menu = '';
        foreach (self::MENU as $p => $t) $menu .= '<a href="' . self::h(self::odkaz($p)) . '"' . ($p === $aktivni ? ' class="aktivni" aria-current="page"' : '') . '>' . self::h($t) . '</a>';
        $limit = $this->app->limitNecinnosti();
        echo $this->hlavicka($titulek)
            . '<header class="lista"><a class="logo" href="index.php"><img src="assets/ikona.svg" alt="" width="24" height="24">' . App::NAZEV . '</a>'
            . '<button type="button" class="menu-prepinac" aria-label="Menu" data-prepni="menu">☰</button>'
            . '<nav id="menu">' . $menu . '</nav>'
            . '<form method="post" action="index.php?p=odhlasit" class="odhlasit">' . $this->csrf() . '<button type="submit" class="odkaz">Odhlásit</button></form></header>'
            . '<main' . ($limit > 0 ? ' data-necinnost="' . $limit . '"' : '') . '><h1>' . self::h($titulek) . '</h1>' . $this->hlasky() . $obsah . '</main>'
            . '<footer>' . App::NAZEV . ' ' . self::h($this->app->verze) . '</footer></body></html>';
    }

    /** Jednoduchá stránka bez menu (přihlášení, instalace). */
    public function samostatna(string $titulek, string $obsah): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        echo $this->hlavicka($titulek, 'samostatna') . '<main class="karta-prihlaseni"><div class="logo velke"><img src="assets/ikona.svg" alt="" width="40" height="40">'
            . App::NAZEV . '</div><h1>' . self::h($titulek) . '</h1>' . $this->hlasky() . $obsah . '</main></body></html>';
    }
}
