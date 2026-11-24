<?php
declare(strict_types=1);
namespace MediaGalerie;

/**
 * Společné části veřejného vzhledu (0.8.9): ikony, záhlaví s logem a menu, patička. Používá je veřejný web (verejne.php)
 * i přihlášení do administrace (Ui::prihlasovaci), aby přihlašovací stránka vypadala jako hlavní stránka webu.
 */
final class VerejneRozvrzeni
{
    /** Ikony ve stylu Feather Icons (MIT, viz assets/LICENCE.txt): obsah prvku <svg viewBox="0 0 24 24">. */
    private const IKONY = [
        'logo' => '<circle cx="12" cy="12" r="10"/><line x1="14.31" y1="8" x2="20.05" y2="17.94"/><line x1="9.69" y1="8" x2="21.17" y2="8"/><line x1="7.38" y1="12" x2="13.12" y2="2.06"/><line x1="9.69" y1="16" x2="3.95" y2="6.06"/><line x1="14.31" y1="16" x2="2.83" y2="16"/><line x1="16.62" y1="12" x2="10.88" y2="21.94"/>',
        'prihlasit' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>',
        'obrazek' => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
        'foto' => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
        'dalsi' => '<polyline points="9 18 15 12 9 6"/>',
        'zpet' => '<polyline points="15 18 9 12 15 6"/>',
        'zavrit' => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'menu' => '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'hledat' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'zamek' => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    ];

    public static function ikona(string $nazev, int $v = 20): string
    {
        return '<svg class="ik" width="' . $v . '" height="' . $v . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . (self::IKONY[$nazev] ?? '') . '</svg>';
    }

    /** Obsah odkazu loga: vlastní obrázek (s názvem nebo bez), jinak znak a název. @param array<string, mixed> $n */
    private static function znak(array $n, string $logo): string
    {
        if ($logo === '') return '<span class="logo-znak">' . self::ikona('logo', 34) . '</span><span class="logo-nazev">' . self::h($n['nazev']) . '</span>';
        $sNazvem = !empty($n['logo_nazev']);
        return '<img class="logo-obrazek" src="' . self::h($logo) . '" alt="' . ($sNazvem ? '' : self::h($n['nazev'])) . '">'
            . ($sNazvem ? '<span class="logo-nazev">' . self::h($n['nazev']) . '</span>' : '');
    }

    private static function h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    /**
     * Záhlaví: logo a menu Domů / Galerie / O galerii (?s=… funguje i z index.php, ten veřejné stránky předá verejne.php).
     * $menu false = jen logo (vypnutý web). $hledani (0.8.14) = vyhledávací pole na konci menu – nahradilo tlačítko Přihlášení do administrace
     * (odkaz na přihlášení zůstává v patičce a administrace na adrese …/index.php); $q = hledaný text, který se v poli předvyplní.
     * $logo (0.8.11) = adresa vlastního loga (Web::logoAdresa); bez něj znak a název. S logem se název ukáže, jen když je zapnutý logo_nazev.
     * @param array<string, mixed> $n nastavení webu (Web::nastaveni)
     */
    public static function zahlavi(array $n, string $aktivni = '', bool $menu = true, bool $hledani = true, string $logo = '', string $q = ''): string
    {
        $nav = '';
        if ($menu) {
            foreach (['domu' => ['./', 'Domů'], 'galerie' => ['?s=galerie', 'Galerie'], 'o-galerii' => ['?s=o-galerii', 'O galerii']] as $k => [$href, $text]) {
                $nav .= '<a href="' . self::h($href) . '"' . ($aktivni === $k ? ' class="aktivni" aria-current="page"' : '') . '>' . self::h($text) . '</a>';
            }
        }
        if ($menu && $hledani) $nav .= self::hledaciPole($q, 'hlavicka-hledani');
        return '<header class="hlavicka"><div class="hlavicka-obal">'
            . '<a class="logo' . ($logo !== '' ? ' logo-vlastni' : '') . '" href="' . ($menu ? './' : 'index.php') . '">' . self::znak($n, $logo) . '</a>'
            . ($menu && $hledani ? '<a class="hledat-mobil" href="verejne.php?s=hledat" aria-label="Hledat fotografie">' . self::ikona('hledat', 22) . '</a>' : '')
            . ($nav !== '' ? '<button type="button" class="menu-tlacitko" aria-label="Menu" aria-expanded="false" aria-controls="hlavni-menu" data-menu>' . self::ikona('menu', 24) . '</button>'
                . '<nav id="hlavni-menu" class="menu" aria-label="Hlavní menu">' . $nav . '</nav>' : '')
            . '</div></header>';
    }

    /**
     * 0.8.14: formulář vyhledávání (GET ?s=hledat&q=…, bez JavaScriptu, CSP form-action 'self'). Odesílá se na verejne.php,
     * aby fungoval i ze stránky přihlášení (index.php). $trida = třída formuláře (záhlaví / stránka výsledků).
     */
    public static function hledaciPole(string $q, string $trida, string $id = 'hledat-q'): string
    {
        return '<form class="hledani ' . self::h($trida) . '" role="search" action="verejne.php" method="get">'
            . '<input type="hidden" name="s" value="hledat">'
            . '<label class="sr-only" for="' . self::h($id) . '">Hledat fotografie</label>'
            . '<input type="search" id="' . self::h($id) . '" name="q" value="' . self::h($q) . '" placeholder="Hledat fotografie…" maxlength="200" autocomplete="off" enterkeyhint="search">'
            . '<button type="submit" aria-label="Hledat">' . self::ikona('hledat', 18) . '</button></form>';
    }

    /**
     * Patička: text z nastavení, odkaz na zásady, nastavení cookies a přihlášení do administrace.
     * @param array<string, mixed> $n
     */
    public static function paticka(array $n, string $zasadyOdkaz = '', bool $cookies = false, bool $admin = true): string
    {
        return '<footer class="paticka"><div class="paticka-obal"><span>' . self::h($n['patka']) . '</span><span class="paticka-odkazy">'
            . ($zasadyOdkaz !== '' ? '<a href="' . self::h($zasadyOdkaz) . '">Zásady ochrany osobních údajů</a>' : '')
            . ($cookies ? '<button type="button" class="odkaz" data-cookies-nastaveni>Nastavení cookies</button>' : '')
            . ($admin ? '<a href="index.php?p=prehled">' . self::ikona('prihlasit', 16) . '<span>Přihlášení do administrace</span></a>' : '')
            . '</span></div></footer>';
    }
}
