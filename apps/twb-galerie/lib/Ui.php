<?php
declare(strict_types=1);
namespace MediaGalerie;

/** Pomůcky pro stránky administrace: rozvržení, formuláře, hlášky, ochrana CSRF. */
final class Ui
{
    public const MENU = [
        'prehled' => 'Přehled',
        'galerie' => 'Galerie',
        'kategorie' => 'Kategorie',
        'stitky' => 'Štítky',
        'nastaveni' => 'Nastavení',
        'web' => 'Veřejný web',
        'api' => 'API a MCP',
    ];

    /** Skupiny postranního menu (nadpis => stránky). Nápověda je v menu navíc, nemá vlastní titulek v MENU. */
    public const SKUPINY = [
        'Média' => ['prehled', 'galerie', 'kategorie', 'stitky'],
        'Správa' => ['web', 'nastaveni', 'api', 'napoveda'],
    ];

    /** Ikony ve stylu Feather Icons (MIT, viz assets/LICENCE.txt): obsah prvku <svg viewBox="0 0 24 24">. */
    private const IKONY = [
        'prehled' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'galerie' => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        'kategorie' => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>',
        'stitky' => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
        'api' => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
        'web' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        'nastaveni' => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
        'napoveda' => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'odhlasit' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'zavrit' => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'obrazky' => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
        'videa' => '<polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>',
        'velikost' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
        'zobrazeni' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
    ];

    /** Ikona (SVG v textu stránky, barva podle textu). Neznámá ikona = prázdný řetězec. */
    public static function ikona(string $nazev, int $velikost = 18): string
    {
        $obsah = self::IKONY[$nazev] ?? '';
        if ($obsah === '') return '';
        return '<svg class="ikona" width="' . $velikost . '" height="' . $velikost . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $obsah . '</svg>';
    }

    /** Dlaždice s číslem (přehled, statistiky). $popis je HTML. */
    public static function dlazdice(string $popis, string $cislo, string $ikona = ''): string
    {
        return '<div class="dlazdice"><div class="popis">' . $popis . '</div>' . ($ikona !== '' ? '<span class="dlazdice-ikona">' . self::ikona($ikona) . '</span>' : '')
            . '<div class="cislo">' . $cislo . '</div></div>';
    }

    /** @var list<string> chyby k zobrazení na této stránce (neplatný formulář) */
    public array $chyby = [];

    public function __construct(private App $app) {}

    public static function h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    /**
     * Atributy položky okna náhledu (assets/app.js): klik na prvek s data-otevrit-okno uvnitř skupiny data-okno-skupina
     * otevře médium v modálním okně a šipky listují položkami skupiny. Obrázek se ukáže jako náhled 1920 px, video a PDF celé.
     * $verejna = veřejný odkaz (tlačítko Kopírovat odkaz), $src = jiný soubor (originál před kompresí), $detail = tlačítko Detail v okně.
     */
    public static function okno(array $m, ?string $verejna = null, ?string $src = null, bool $detail = true, string $popis = ''): string
    {
        $id = (int)$m['id'];
        $v = substr((string)$m['md5'], 0, 8);
        $soubor = "media.php?id={$id}&v={$v}";
        $src ??= $m['kind'] === 'image' ? "media.php?id={$id}&nahled=" . Media::NAHLED_VELKY_PX . "&v={$v}" : $soubor;
        $a = ['data-okno' => (string)$m['kind'], 'data-okno-src' => $src, 'data-okno-nazev' => (string)$m['original_name'],
            'data-okno-popis' => $popis !== '' ? $popis : Media::popis($m), 'data-okno-stahnout' => $soubor . '&stahnout=1'];
        if ($detail) $a['data-okno-detail'] = self::odkaz('medium', ['id' => $id]);
        if ($verejna) $a['data-okno-odkaz'] = $verejna;
        $out = '';
        foreach ($a as $k => $h) $out .= ' ' . $k . '="' . self::h($h) . '"';
        return $out;
    }

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
        // skryté postranní menu (počítač) se nastaví dřív, než se stránka vykreslí – bez probliknutí
        return "<!doctype html>\n<html lang=\"cs\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
            . '<meta name="robots" content="noindex, nofollow"><title>' . self::h($titulek) . ' – ' . App::NAZEV . '</title>'
            . "<link rel=\"stylesheet\" href=\"assets/style.css?v=$v\"><link rel=\"icon\" href=\"assets/ikona.svg\" type=\"image/svg+xml\">"
            . '<script>try{if(localStorage.getItem("twbg-menu")==="skryte")document.documentElement.classList.add("menu-skryte")}catch(e){}</script>'
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

    /** Celá stránka administrace: postranní menu, horní lišta, obsah a patička. */
    /** @param string $kontext stránka administrace pro tlačítko „?“ (kontext nápovědy, viz help/_obsah.json) */
    public function stranka(string $titulek, string $aktivni, string $obsah, string $kontext = ''): void
    {
        $pomoc = $kontext !== '' ? ($this->app->napoveda()->obsah()['context'][$kontext] ?? null) : null;
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        $menu = '';
        foreach (self::SKUPINY as $skupina => $stranky) {
            $menu .= '<li class="menu-skupina">' . self::h($skupina) . '</li>';
            foreach ($stranky as $p) {
                $t = self::MENU[$p] ?? ($p === 'napoveda' ? 'Nápověda' : $p);
                $menu .= '<li><a href="' . self::h(self::odkaz($p)) . '"' . ($p === $aktivni ? ' class="aktivni" aria-current="page"' : '') . '>'
                    . self::ikona($p) . '<span>' . self::h($t) . '</span></a></li>';
            }
        }
        $limit = $this->app->limitNecinnosti();
        $verze = self::h($this->app->verze);
        echo $this->hlavicka($titulek, 'administrace')
            . '<div class="obal"><nav id="menu" class="postranni" aria-label="Hlavní menu">'
            . '<a class="logo" href="index.php"><img src="assets/ikona.svg" alt="" width="28" height="28"><span>' . App::NAZEV . '</span></a>'
            . '<ul>' . $menu . '</ul>'
            . '<div class="postranni-pata">Verze ' . $verze . '</div></nav>'
            . '<div class="zaclona" data-prepni="menu" hidden></div>'
            . '<div class="hlavni-cast"><header class="lista">'
            . '<button type="button" class="menu-prepinac" aria-label="Zobrazit nebo skrýt menu" aria-controls="menu" data-prepni="menu"><i></i></button>'
            . '<span class="mezera"></span>'
            . '<a class="lista-odkaz' . ($aktivni === 'napoveda' ? ' aktivni' : '') . '" href="' . self::h(self::odkaz('napoveda')) . '" title="Nápověda">' . self::ikona('napoveda') . '<span>Nápověda</span></a>'
            . '<form method="post" action="index.php?p=odhlasit" class="odhlasit">' . $this->csrf() . '<button type="submit" class="lista-odkaz" title="Odhlásit">' . self::ikona('odhlasit') . '<span>Odhlásit</span></button></form></header>'
            . '<main' . ($limit > 0 ? ' data-necinnost="' . $limit . '"' : '') . '><h1>' . self::h($titulek)
            . ($pomoc ? ' <a class="pomoc" href="' . self::h(self::odkaz('napoveda', ['s' => $pomoc])) . '" title="Nápověda k této stránce" aria-label="Nápověda k této stránce">?</a>' : '') . '</h1>' . $this->hlasky() . $obsah . '</main>'
            . '<footer><span>' . App::NAZEV . ' ' . $verze . '</span><a href="' . self::h(self::odkaz('napoveda')) . '">Nápověda</a></footer></div></div></body></html>';
    }

    /** Jednoduchá stránka bez menu (přihlášení, instalace). */
    /**
     * Přihlášení do administrace (0.8.9) ve vzhledu veřejného webu: stejné záhlaví a patička jako hlavní stránka (assets/verejne.css),
     * úvodní fotka webu jako pozadí a formulář v kartě uprostřed. Vypnutý web: jen logo bez menu. Měřicí ani reklamní kód se sem nevkládá.
     */
    public function prihlasovaci(string $titulek, string $obsah, string $podnadpis = ''): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        $web = $this->app->web();
        $n = $web->nastaveni();
        $uvod = $n['zapnuto'] ? $web->uvodni() : null;
        $foto = $uvod ? $web->obrazek($uvod, Web::PX_VELKY) : '';
        $v = self::h($this->app->verze);
        echo "<!doctype html>\n<html lang=\"cs\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
            . '<meta name="robots" content="noindex, nofollow"><title>' . self::h($titulek) . ' – ' . self::h($n['nazev']) . '</title>'
            . "<link rel=\"stylesheet\" href=\"assets/verejne.css?v=$v\"><link rel=\"icon\" href=\"assets/ikona.svg\" type=\"image/svg+xml\">"
            . "<script src=\"assets/verejne.js?v=$v\" defer></script><script src=\"assets/app.js?v=$v\" defer></script></head><body class=\"stranka-prihlaseni\">"
            . VerejneRozvrzeni::zahlavi($n, '', (bool)$n['zapnuto'], false)
            . '<main class="prihlaseni' . ($foto === '' ? ' prihlaseni-bez-fotky' : '') . '">'
            . ($foto !== '' ? '<img class="prihlaseni-fotka" src="' . self::h($foto) . '" alt="">' : '')
            . '<section class="prihlaseni-karta" aria-labelledby="prihlaseni-nadpis">'
            . '<p class="nadtitulek">Administrace</p><h1 id="prihlaseni-nadpis">' . self::h($titulek) . '</h1>'
            . ($podnadpis !== '' ? '<p class="prihlaseni-pod">' . self::h($podnadpis) . '</p>' : '')
            . $this->hlasky() . $obsah . '</section></main>'
            . VerejneRozvrzeni::paticka($n, $n['zasady_zapnuto'] ? '?s=' . Web::ZASADY : '', false, false)
            . '</body></html>';
    }

    public function samostatna(string $titulek, string $obsah): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        echo $this->hlavicka($titulek, 'samostatna') . '<div class="samostatna-obal"><div class="logo velke"><img src="assets/ikona.svg" alt="" width="40" height="40">'
            . App::NAZEV . '</div><main class="karta-prihlaseni"><h1>' . self::h($titulek) . '</h1>' . $this->hlasky() . $obsah . '</main></div></body></html>';
    }
}
