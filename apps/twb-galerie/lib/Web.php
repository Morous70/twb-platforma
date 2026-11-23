<?php
declare(strict_types=1);
namespace MediaGalerie;

/**
 * Veřejný web galerie (0.8.0): hlavní stránka, Galerie (veřejné kategorie a jejich fotografie) a O galerii.
 * Veřejné je jen to, co uživatel výslovně zveřejní: kategorie se zaškrtnutým „Zobrazit na veřejném webu“ a v ní obrázky,
 * které nejsou v archivu a mají zapnutý veřejný odkaz (vypnutý odkaz = fotka zmizí i z webu). Obrázky se vydávají
 * přes trvalé adresy náhledů veřejného odkazu (soubor.php/<klíč>/w<px>/…), originál se na webu nenabízí.
 * Texty, úvodní fotka a reklamní plochy jsou v nastavení `web` (JSON, zálohuje se).
 * Reklama (0.8.3): pro každou pozici (POZICE) jen kód z reklamního systému (HTML/JS), vloží se bez úprav. 0.8.9: tři pozice jako dřív –
 * Pod úvodem, Před fotografiemi (klíč pod_galeriemi: hlavní stránka před doporučenými, stránka Galerie před nejnovějšími; v galerii
 * kategorie není) a Před patičkou. Kód, který 0.8.8 uschovala (reklama_odlozena), se vrátí do Před fotografiemi. Měření návštěvnosti:
 * jeden měřicí kód do hlavičky nebo na konec stránky. Se zapnutým souhlasem s cookies se měřicí ani reklamní kód nenačte,
 * dokud ho návštěvník nepovolí (na stránce je jen jako neživý <template>, oživí ho assets/verejne.js). Kód zadává jen
 * přihlášený správce; stránka s kódem dostane volnější CSP. Stránka Zásady ochrany osobních údajů (vlastní Markdown nebo vzor).
 * Doporučené fotografie (0.8.4): na hlavní stránce náhodný výběr z veřejných fotek označených jako doporučená (media.featured),
 * v mřížce s nastaveným počtem sloupců (3–8) a řádků (1–5).
 */
final class Web
{
    public const VYCHOZI = [
        'zapnuto' => true,
        'nazev' => 'TWB Galerie',
        'stitek' => 'Fotografická galerie',
        'nadpis' => 'Objevte svět v obrazech',
        'podnadpis' => 'Fotografie, příběhy a okamžiky zachycené na jednom místě.',
        'tlacitko' => 'Prohlédnout galerii',
        'poznamka' => 'Nové fotografie průběžně přibývají',
        'uvod_id' => null,
        'vybrane_nadpis' => 'Vybrané galerie',
        'vybrane_podnadpis' => 'Vyberte si, co chcete prohlédnout.',
        'vybrane_pocet' => 4,
        // doporučené fotografie (0.8.4): náhodný výběr z médií označených jako doporučená, mřížka sloupce × řádky
        'doporucene_nadpis' => 'Doporučené fotografie',
        'doporucene_podnadpis' => 'Náhodný výběr toho nejlepšího z galerie.',
        'doporucene_sloupce' => 4,
        'doporucene_radky' => 2,
        'o_galerii' => "Fotografie, příběhy a okamžiky zachycené na jednom místě.\n\nNové fotografie průběžně přibývají.",
        'patka' => '© TWB Galerie',
        'odkaz_administrace' => true,
        'skryt_vyhledavacum' => false,
        // reklama (0.8.3): kód pro každou pozici, prázdná pozice se nezobrazí
        'reklama_zapnuto' => true,
        'reklama' => ['pod_uvodem' => '', 'pod_galeriemi' => '', 'pred_patickou' => ''],
        'ads_txt' => '',
        // měření návštěvnosti
        'mereni_zapnuto' => false,
        'mereni_kod' => '',
        'mereni_umisteni' => 'konec',
        // GDPR a cookies
        'cookies_souhlas' => true,
        'zasady_zapnuto' => false,
        'provozovatel' => '',
        'ico' => '',
        'email' => '',
        'adresa' => '',
        'nazev_stranky' => '',
        'zasady_text' => '',
    ];
    /** Reklamní pozice: klíč => [název, kde je a na co se hodí]. */
    public const POZICE = [
        'pod_uvodem' => ['Pod úvodem', 'Hlavní stránka pod úvodní fotkou, stránka Galerie a galerie kategorie pod nadpisem. Nejvíc zobrazení – vhodné pro banner na šířku (např. 970×250 nebo 728×90, na mobilu 320×100).'],
        'pod_galeriemi' => ['Před fotografiemi', 'Hlavní stránka před doporučenými fotografiemi, stránka Galerie před nejnovějšími fotografiemi. V galerii jedné kategorie se nezobrazuje. Vhodné pro banner na šířku nebo obdélník 300×250.'],
        'pred_patickou' => ['Před patičkou', 'Úplně dole na hlavní stránce, v galeriích i na stránce O galerii – dobere návštěvníky, kteří prošli celou stránku.'],
    ];
    /** Kam vložit měřicí kód. */
    public const UMISTENI_MERENI = [
        'konec' => 'Na konci stránky (před </body>) – bezpečné pro jakýkoli kód včetně <noscript>',
        'hlavicka' => 'Do hlavičky (<head>) – když to nástroj výslovně chce',
    ];
    /** Adresa (?s=…) stránky se zásadami; .htaccess vede i /zasady-ochrany-osobnich-udaju. */
    public const ZASADY = 'zasady-ochrany-osobnich-udaju';
    /** Cookie s volbou návštěvníka (assets/verejne.js), platí rok. */
    public const COOKIE_SOUHLAS = 'twbg_souhlas';
    /** Největší délka vloženého kódu (reklamní pozice, měření) a obsahu ads.txt. */
    public const MAX_KOD = 20000;
    /** Fotek na stránku galerie na webu. 0.8.5: dělitelné 2, 3, 4 i 6 – počty sloupců v assets/verejne.css (.fotky-obal). */
    public const NA_STRANKU = 36;
    /** Mřížka doporučených fotografií: počet sloupců a řádků. */
    public const SLOUPCE = [3, 8];
    public const RADKY = [1, 5];
    /** Náhled v kartách a mřížce, velký obrázek v okně a úvodní fotka (delší strana v px, z Media::NAHLEDY). */
    public const PX_NAHLED = 640;
    public const PX_VELKY = 1920;

    /** Podmínka pro fotku na webu (tabulka media jako m, categories jako c). */
    private const VEREJNA = "m.kind = 'image' AND m.archived_at IS NULL AND m.public_key IS NOT NULL AND c.web_public = 1";

    public function __construct(private App $app) {}

    /** Nastavení webu doplněné o výchozí hodnoty. @return array<string, mixed> */
    public function nastaveni(): array
    {
        $n = json_decode((string)$this->app->nastaveni()->dej('web', ''), true);
        $n = is_array($n) ? $n : [];
        $out = array_replace(self::VYCHOZI, array_intersect_key($n, self::VYCHOZI));
        $out['reklama'] = array_replace(self::VYCHOZI['reklama'], is_array($n['reklama'] ?? null) ? array_map('strval', array_intersect_key($n['reklama'], self::VYCHOZI['reklama'])) : []);
        if (!isset($n['reklama'])) {
            // nastavení z 0.8.0–0.8.2: z reklamních ploch zůstane jen kód (textové plochy byly jen ukázka), kód do hlavičky → měření
            foreach (['banner1' => 'pod_uvodem', 'banner2' => 'pod_galeriemi'] as $stary => $pozice) {
                $b = $n[$stary] ?? null;
                if (is_array($b) && ($b['typ'] ?? '') === 'kod' && !empty($b['zobrazit'])) $out['reklama'][$pozice] = (string)($b['kod'] ?? '');
            }
            if (($n['kod_hlavicka'] ?? '') !== '' && !isset($n['mereni_kod'])) {
                $out['mereni_zapnuto'] = true;
                $out['mereni_kod'] = (string)$n['kod_hlavicka'];
                $out['mereni_umisteni'] = 'hlavicka';
            }
        }
        // 0.8.9: kód, který 0.8.8 uschovala ze zrušené pozice Pod galeriemi, se do ní vrátí (zapíše se prvním uložením kterékoli karty)
        $odlozena = trim((string)($n['reklama_odlozena'] ?? ''));
        if ($odlozena !== '' && trim($out['reklama']['pod_galeriemi']) === '') $out['reklama']['pod_galeriemi'] = $odlozena;
        if (!isset(self::UMISTENI_MERENI[$out['mereni_umisteni']])) $out['mereni_umisteni'] = 'konec';
        $out['doporucene_sloupce'] = min(self::SLOUPCE[1], max(self::SLOUPCE[0], (int)$out['doporucene_sloupce']));
        $out['doporucene_radky'] = min(self::RADKY[1], max(self::RADKY[0], (int)$out['doporucene_radky']));
        return $out;
    }

    /** Části stránky Veřejný web (karty), každá se ukládá svým formulářem. */
    public const CASTI = ['zaklad' => 'Základ a texty', 'reklama' => 'Reklama', 'mereni' => 'Měření návštěvnosti', 'gdpr' => 'GDPR a cookies'];

    /** Uloží jednu část nastavení z formuláře (administrace → Veřejný web). @param array<string, mixed> $p */
    public function uloz(string $cast, array $p): void
    {
        $n = $this->nastaveni();
        match ($cast) {
            'zaklad' => $this->zaklad($n, $p),
            'reklama' => $this->reklama($n, $p),
            'mereni' => $this->mereni($n, $p),
            'gdpr' => $this->gdpr($n, $p),
            default => throw new Chyba('Neznámá část nastavení.'),
        };
        $this->app->nastaveni()->uloz('web', (string)json_encode($n, JSON_UNESCAPED_UNICODE));
        $this->app->log('info', 'Nastavení veřejného webu změněno (' . mb_strtolower(self::CASTI[$cast]) . ')' . ($n['zapnuto'] ? '.' : ', web vypnutý.'));
    }

    /** @param array<string, mixed> $n @param array<string, mixed> $p */
    private function zaklad(array &$n, array $p): void
    {
        $n['zapnuto'] = !empty($p['zapnuto']);
        $n['odkaz_administrace'] = !empty($p['odkaz_administrace']);
        $n['skryt_vyhledavacum'] = !empty($p['skryt_vyhledavacum']);
        // doporučené fotografie (0.8.4): chybí-li pole ve formuláři (starší klient), zůstane dosavadní hodnota
        $p += array_intersect_key($n, array_flip(['doporucene_nadpis', 'doporucene_podnadpis', 'doporucene_sloupce', 'doporucene_radky']));
        foreach (['nazev' => ['Název webu', 60, true], 'stitek' => ['Štítek nad nadpisem', 60, false], 'nadpis' => ['Nadpis', 120, true],
            'podnadpis' => ['Podnadpis', 300, false], 'tlacitko' => ['Text tlačítka', 40, true], 'poznamka' => ['Poznámka pod tlačítkem', 120, false],
            'vybrane_nadpis' => ['Nadpis vybraných galerií', 80, true], 'vybrane_podnadpis' => ['Podnadpis vybraných galerií', 160, false],
            'doporucene_nadpis' => ['Nadpis doporučených fotografií', 80, true], 'doporucene_podnadpis' => ['Podnadpis doporučených fotografií', 160, false],
            'patka' => ['Patička', 200, false]] as $k => [$popis, $max, $povinne]) {
            $n[$k] = (string)Vstup::text($p, $k, $popis, $max, $povinne);
        }
        $n['o_galerii'] = (string)Vstup::text($p, 'o_galerii', 'Text stránky O galerii', 20000, false);
        $pocet = (int)($p['vybrane_pocet'] ?? 4);
        if ($pocet < 0 || $pocet > 12) throw new Chyba('Počet vybraných galerií musí být 0 až 12.');
        $n['vybrane_pocet'] = $pocet;
        foreach (['doporucene_sloupce' => [self::SLOUPCE, 'Počet sloupců doporučených fotografií'], 'doporucene_radky' => [self::RADKY, 'Počet řádků doporučených fotografií']] as $k => [[$od, $do], $popis]) {
            $c = filter_var($p[$k], FILTER_VALIDATE_INT);
            if ($c === false || $c < $od || $c > $do) throw new Chyba("{$popis} musí být {$od} až {$do}.");
            $n[$k] = $c;
        }
        $uvod = (int)($p['uvod_id'] ?? 0);
        if ($uvod > 0 && !$this->muzeBytUvodni($uvod)) throw new Chyba('Úvodní fotka musí být obrázek se zapnutým veřejným odkazem, který není v archivu.');
        $n['uvod_id'] = $uvod > 0 ? $uvod : null;
    }

    /** @param array<string, mixed> $n @param array<string, mixed> $p */
    private function reklama(array &$n, array $p): void
    {
        $n['reklama_zapnuto'] = !empty($p['reklama_zapnuto']);
        foreach (self::POZICE as $k => [$nazev]) $n['reklama'][$k] = self::kod($p, "reklama_{$k}", "Reklama – {$nazev}");
        $n['ads_txt'] = self::kod($p, 'ads_txt', 'Obsah ads.txt');
    }

    /** @param array<string, mixed> $n @param array<string, mixed> $p */
    private function mereni(array &$n, array $p): void
    {
        $n['mereni_zapnuto'] = !empty($p['mereni_zapnuto']);
        $n['mereni_kod'] = self::kod($p, 'mereni_kod', 'Měřicí kód');
        $kam = (string)($p['mereni_umisteni'] ?? 'konec');
        if (!isset(self::UMISTENI_MERENI[$kam])) throw new Chyba('Vyberte, kam měřicí kód vložit.');
        $n['mereni_umisteni'] = $kam;
    }

    /** @param array<string, mixed> $n @param array<string, mixed> $p */
    private function gdpr(array &$n, array $p): void
    {
        $n['cookies_souhlas'] = !empty($p['cookies_souhlas']);
        $n['zasady_zapnuto'] = !empty($p['zasady_zapnuto']);
        $n['provozovatel'] = (string)Vstup::text($p, 'provozovatel', 'Provozovatel', 120, $n['zasady_zapnuto']);
        $ico = preg_replace('/\s+/', '', (string)($p['ico'] ?? ''));
        if ($ico !== '' && !preg_match('/^\d{8}$/', $ico)) throw new Chyba('IČO má 8 číslic.');
        $n['ico'] = $ico;
        $n['email'] = (string)Vstup::text($p, 'email', 'Kontaktní e-mail', 120, $n['zasady_zapnuto']);
        if ($n['email'] !== '' && filter_var($n['email'], FILTER_VALIDATE_EMAIL) === false) throw new Chyba('Kontaktní e-mail nemá správný tvar.');
        $n['adresa'] = (string)Vstup::text($p, 'adresa', 'Adresa', 200, false);
        $n['nazev_stranky'] = (string)Vstup::text($p, 'nazev_stranky', 'Název stránky', 80, false);
        $n['zasady_text'] = (string)Vstup::text($p, 'zasady_text', 'Text zásad', 30000, false);
    }

    // ---------- reklama, měření, souhlas ----------

    /** Kód reklamní pozice k vložení ('' = pozice se nezobrazí). @param array<string, mixed> $n */
    public static function reklamaKod(array $n, string $pozice): string
    {
        return $n['reklama_zapnuto'] ? (string)($n['reklama'][$pozice] ?? '') : '';
    }

    /** Měří se? (zapnuto a je kód) @param array<string, mixed> $n */
    public static function meri(array $n): bool
    {
        return $n['mereni_zapnuto'] && $n['mereni_kod'] !== '';
    }

    /** Je vyplněná aspoň jedna reklamní pozice (a reklama zapnutá)? @param array<string, mixed> $n */
    public static function maReklamu(array $n): bool
    {
        foreach (array_keys(self::POZICE) as $k) if (self::reklamaKod($n, $k) !== '') return true;
        return false;
    }

    /** Na co se cookie lišta ptá (prázdné = lišta se neukáže). @param array<string, mixed> $n @return array<string, string> */
    public static function kategorieSouhlasu(array $n): array
    {
        if (!$n['cookies_souhlas']) return [];
        return array_filter(['mereni' => self::meri($n) ? 'Měření návštěvnosti' : '', 'reklama' => self::maReklamu($n) ? 'Reklama' : '']);
    }

    /** Název webu v textu zásad: vyplněný „Název stránky“, jinak doména, jinak název webu. @param array<string, mixed> $n */
    public function nazevStranky(array $n): string
    {
        if ($n['nazev_stranky'] !== '') return $n['nazev_stranky'];
        $host = (string)parse_url($this->app->url(), PHP_URL_HOST);
        return $host !== '' ? $host : (string)$n['nazev'];
    }

    /**
     * Vzorový text zásad (Markdown) sestavený z údajů provozovatele a z toho, co web opravdu dělá: bez registrace a formulářů,
     * záznamy serveru u hostingu, cookie s volbou souhlasu, měření a reklama podle nastavení. Podklad, ne právní posudek.
     * @param array<string, mixed> $n
     */
    public function vzorZasad(array $n): string
    {
        $web = $this->nazevStranky($n);
        $kdo = $n['provozovatel'] !== '' ? $n['provozovatel'] : '[provozovatel]';
        $udaje = array_filter([$n['ico'] !== '' ? "IČO {$n['ico']}" : '', $n['adresa']]);
        $email = $n['email'] !== '' ? $n['email'] : '[e-mail]';
        $souhlas = $n['cookies_souhlas'];
        $t = "## Kdo zpracovává vaše údaje\n\nSprávcem osobních údajů na webu {$web} je **{$kdo}**" . ($udaje ? ', ' . implode(', ', $udaje) : '') . ".\n"
            . "S dotazy k osobním údajům a s uplatněním svých práv se obracejte na e-mail [{$email}](mailto:{$email}).\n\n"
            . "## Jaké údaje zpracováváme\n\n"
            . "Web je galerie fotografií. Nemá registraci, přihlášení pro návštěvníky ani formuláře, takže nám sami žádné osobní údaje nezadáváte.\n\n"
            . "- **Záznamy serveru** – při každé návštěvě server hostingu zaznamená technické údaje (IP adresa, čas, navštívená adresa, typ prohlížeče). "
            . "Slouží k provozu a ochraně webu před útoky (oprávněný zájem), uchovávají se jen po dobu nutnou pro provoz a bezpečnost.\n"
            . ($souhlas ? "- **Vaše volba cookies** – v prohlížeči uložíme nezbytnou cookie `" . self::COOKIE_SOUHLAS . "` s tím, co jste povolili. Platí rok a nic jiného neobsahuje.\n" : '');
        if (self::meri($n)) {
            $t .= "- **Měření návštěvnosti** – používáme nástroj pro měření návštěvnosti, který může ukládat cookies a zpracovávat údaje o návštěvě "
                . "(zkrácenou nebo celou IP adresu, prohlížené stránky, zařízení). Výsledky používáme jen souhrnně ke zlepšení webu."
                . ($souhlas ? " Načte se jen s vaším souhlasem." : '') . "\n";
        }
        if (self::maReklamu($n)) {
            $t .= "- **Reklama** – na webu zobrazujeme reklamu z reklamních systémů. Ty mohou ukládat cookies a zpracovávat údaje o zařízení a návštěvě "
                . "(IP adresu, identifikátory, zobrazené reklamy) pro zobrazení, měření a případně přizpůsobení reklamy; jsou v tom samostatnými správci "
                . "podle svých zásad." . ($souhlas ? " Reklamní kód se načte jen s vaším souhlasem." : '') . "\n";
        }
        $t .= "\n## Cookies\n\n" . ($souhlas
                ? "Nezbytná cookie s vaší volbou se ukládá vždy. Ostatní cookies (měření, reklama) jen s vaším souhlasem, který můžete kdykoli změnit nebo odvolat odkazem **Nastavení cookies** v patičce webu."
                : "Cookies můžete kdykoli smazat nebo zakázat v nastavení svého prohlížeče.")
            . "\n\n## Vaše práva\n\n"
            . "Máte právo na přístup ke svým údajům, jejich opravu nebo výmaz, omezení zpracování, přenositelnost a právo vznést námitku. "
            . ($souhlas ? "Souhlas můžete kdykoli odvolat. " : '')
            . "Pokud se domníváte, že zpracování porušuje předpisy, můžete podat stížnost u Úřadu pro ochranu osobních údajů ([www.uoou.gov.cz](https://www.uoou.gov.cz)).\n";
        return $t;
    }

    /** Text stránky zásad: vlastní text, jinak vzor. @param array<string, mixed> $n */
    public function textZasad(array $n): string
    {
        return trim((string)$n['zasady_text']) !== '' ? (string)$n['zasady_text'] : $this->vzorZasad($n);
    }

    /** Nastaví úvodní fotku (tlačítko v detailu média). */
    public function nastavUvodni(int $id): void
    {
        if (!$this->muzeBytUvodni($id)) throw new Chyba('Úvodní fotka musí být obrázek se zapnutým veřejným odkazem, který není v archivu.');
        $n = $this->nastaveni();
        $n['uvod_id'] = $id;
        $this->app->nastaveni()->uloz('web', (string)json_encode($n, JSON_UNESCAPED_UNICODE));
    }

    /** Vložený kód (HTML/JS) nebo ads.txt: ořízne okraje, sjednotí konce řádků, kontroluje jen délku (obsah je na správci). @param array<string, mixed> $v */
    private static function kod(array $v, string $klic, string $popis): string
    {
        $s = trim(str_replace(["\r\n", "\r"], "\n", (string)($v[$klic] ?? '')));
        $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s);
        if (mb_strlen($s) > self::MAX_KOD) throw new Chyba("Pole „{$popis}“ může mít nejvýš " . self::MAX_KOD . ' znaků.');
        return $s;
    }

    /**
     * CSP veřejného webu. Bez reklamního a měřicího kódu jen vlastní skripty a styly. S kódem musí projít skripty, rámečky a styly
     * reklamních systémů a měřicích nástrojů (AdSense, Sklik, Revive, Matomo… načítají skripty z různých domén a vkládají vložené skripty a styly),
     * proto https: a 'unsafe-inline'; zůstává zákaz vložení stránky do cizího rámu, změny <base> a odeslání formulářů jinam.
     */
    public static function csp(bool $sKodem): string
    {
        if (!$sKodem) return "default-src 'self'; img-src 'self' https: data:; style-src 'self'; script-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'";
        return "default-src 'self' https:; img-src 'self' https: data: blob:; style-src 'self' 'unsafe-inline' https:; script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; "
            . "frame-src https:; connect-src 'self' https:; font-src 'self' https: data:; media-src 'self' https:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'";
    }

    private function muzeBytUvodni(int $id): bool
    {
        return (bool)$this->app->db->q("SELECT 1 FROM media WHERE id = ? AND kind = 'image' AND archived_at IS NULL AND public_key IS NOT NULL", [$id])->fetchColumn();
    }

    /** Obrázky, které jdou zvolit jako úvodní (veřejný odkaz, ne archiv), nejnovější první. @return array<int, string> */
    public function moznosti(int $limit = 500): array
    {
        $out = [];
        foreach ($this->app->db->q("SELECT m.id, m.original_name, c.name AS kat, c.web_public FROM media m LEFT JOIN categories c ON c.id = m.category_id
            WHERE m.kind = 'image' AND m.archived_at IS NULL AND m.public_key IS NOT NULL ORDER BY m.id DESC LIMIT " . max(1, $limit))->fetchAll() as $r) {
            $out[(int)$r['id']] = $r['original_name'] . ($r['kat'] !== null ? " ({$r['kat']}" . ($r['web_public'] ? '' : ', neveřejná') . ')' : ' (bez kategorie)');
        }
        return $out;
    }

    // ---------- obsah webu ----------

    /** Veřejné kategorie s počtem fotek a obálkou, v pořadí z administrace (pořadí, název). Prázdné se nevypíší. @return list<array<string, mixed>> */
    public function kategorie(): array
    {
        $r = $this->app->db->q('SELECT c.*, COUNT(m.id) AS pocet FROM categories c JOIN media m ON m.category_id = c.id AND ' . self::VEREJNA
            . ' WHERE c.web_public = 1 GROUP BY c.id HAVING COUNT(m.id) > 0')->fetchAll();
        usort($r, [Kategorie::class, 'porovnej']);
        foreach ($r as &$k) $k['obalka'] = $this->obalka($k);
        return $r;
    }

    /** Jedna veřejná kategorie (null = neexistuje nebo není veřejná). @return ?array<string, mixed> */
    public function jednaKategorie(int $id): ?array
    {
        $k = $this->app->db->q('SELECT * FROM categories WHERE id = ? AND web_public = 1', [$id])->fetch();
        return $k ?: null;
    }

    /** Obálka kategorie: zvolená fotka (pokud je pořád veřejná), jinak nejnovější fotka kategorie. @param array<string, mixed> $k */
    private function obalka(array $k): ?array
    {
        $sql = 'SELECT m.* FROM media m JOIN categories c ON c.id = m.category_id WHERE c.id = ? AND ' . self::VEREJNA;
        $m = $k['web_cover_id'] ? $this->app->db->q($sql . ' AND m.id = ?', [$k['id'], $k['web_cover_id']])->fetch() : false;
        return ($m ?: $this->app->db->q($sql . ' ORDER BY m.id DESC LIMIT 1', [$k['id']])->fetch()) ?: null;
    }

    /** Fotky veřejné kategorie, nejnovější první. @return array{polozky: list<array<string, mixed>>, celkem: int, strana: int, stran: int} */
    public function fotky(int $kategorie, int $strana = 1, int $naStranku = self::NA_STRANKU): array
    {
        $kde = 'FROM media m JOIN categories c ON c.id = m.category_id WHERE c.id = ? AND ' . self::VEREJNA;
        $celkem = (int)$this->app->db->q("SELECT COUNT(*) $kde", [$kategorie])->fetchColumn();
        $stran = max(1, (int)ceil($celkem / $naStranku));
        $strana = min(max(1, $strana), $stran);
        $polozky = $this->app->db->q("SELECT m.* $kde ORDER BY m.id DESC LIMIT " . $naStranku . ' OFFSET ' . (($strana - 1) * $naStranku), [$kategorie])->fetchAll();
        return ['polozky' => $polozky, 'celkem' => $celkem, 'strana' => $strana, 'stran' => $stran];
    }

    /**
     * Doporučené fotky pro hlavní stránku: náhodný výběr z veřejných fotek označených jako doporučená (každé načtení jiný,
     * stránka se drží v mezipaměti 60 s). Počet = sloupce × řádky z nastavení. @return list<array<string, mixed>>
     */
    public function doporucene(?int $pocet = null): array
    {
        $n = $this->nastaveni();
        $pocet ??= (int)$n['doporucene_sloupce'] * (int)$n['doporucene_radky'];
        return $this->app->db->q('SELECT m.*, c.name AS kategorie_nazev FROM media m JOIN categories c ON c.id = m.category_id WHERE m.featured = 1 AND ' . self::VEREJNA
            . ' ORDER BY RANDOM() LIMIT ' . max(1, $pocet))->fetchAll();
    }

    /**
     * 0.8.5: šířky obrazovky, na kterých mřížka doporučených mění počet sloupců (musí odpovídat assets/verejne.css).
     * Hodnota = přípona třídy skryt-… (d = nad 1100 px, mNNN = do NNN px).
     */
    public const PASMA_DOPORUCENYCH = ['d', 'm1100', 'm860', 'm640', 'm420'];

    /** Kolik sloupců má mřížka doporučených v daném pásmu šířky (stejně jako assets/verejne.css). */
    public static function sloupceVPasmu(int $sloupce, string $pasmo): int
    {
        return match ($pasmo) {
            'm1100' => min($sloupce, 6),
            'm860' => $sloupce === 3 ? 3 : 4,
            'm640' => 3,
            'm420' => 2,
            default => $sloupce,
        };
    }

    /**
     * Pro každé pásmo šířky kolik fotek z $pocet ukázat (zbytek dostane třídu skryt-<pásmo>): nejvýš sloupce v pásmu × řádky.
     * 0.8.6: neúplný poslední řádek se ukáže (0.8.5 ho skrývala, takže při 9 fotkách v mřížce 5 × 2 zbyl jen jeden řádek);
     * skrývají se jen fotky navíc na užších obrazovkách, kde je sloupců méně, aby zůstal nastavený počet řádků.
     * @return array<string, int>
     */
    public static function viditelneDoporucene(int $pocet, int $sloupce, int $radky): array
    {
        $out = [];
        foreach (self::PASMA_DOPORUCENYCH as $pasmo) {
            $s = self::sloupceVPasmu($sloupce, $pasmo);
            $out[$pasmo] = min($pocet, $s * $radky);
        }
        return $out;
    }

    /** Kolik doporučených fotek může web ukázat a kolik je doporučených celkem (i neveřejných). @return array{verejne: int, celkem: int} */
    public function pocetDoporucenych(): array
    {
        return [
            'verejne' => (int)$this->app->db->q('SELECT COUNT(*) FROM media m JOIN categories c ON c.id = m.category_id WHERE m.featured = 1 AND ' . self::VEREJNA)->fetchColumn(),
            'celkem' => (int)$this->app->db->q('SELECT COUNT(*) FROM media WHERE featured = 1')->fetchColumn(),
        ];
    }

    /**
     * 0.8.7: doporučená média, která se na webu neukážou, s důvodem (pro přehled v administraci, karta Základ a texty).
     * @return list<array{id: int, nazev: string, kategorie: string, duvod: string}>
     */
    public function doporuceneMimoWeb(): array
    {
        $out = [];
        foreach ($this->app->db->q('SELECT m.*, c.name AS kategorie_nazev FROM media m LEFT JOIN categories c ON c.id = m.category_id WHERE m.featured = 1 ORDER BY m.id DESC')->fetchAll() as $m) {
            $duvod = $this->procNeniNaWebu($m);
            if ($duvod !== '') $out[] = ['id' => (int)$m['id'], 'nazev' => (string)$m['original_name'], 'kategorie' => (string)($m['kategorie_nazev'] ?? ''), 'duvod' => $duvod];
        }
        return $out;
    }

    /** Proč se doporučená fotka na webu neukáže ('' = ukáže se). @param array<string, mixed> $m */
    public function procNeniNaWebu(array $m): string
    {
        if ($m['kind'] !== 'image') return 'Na webu se ukazují jen obrázky.';
        if ($m['archived_at']) return 'Médium je v archivu.';
        if (!$m['public_key']) return 'Médium nemá zapnutý veřejný odkaz.';
        if (!$m['category_id']) return 'Médium není v žádné kategorii – na webu jsou jen fotky z kategorií zobrazených na veřejném webu.';
        if (!$this->app->db->q('SELECT web_public FROM categories WHERE id = ?', [$m['category_id']])->fetchColumn()) return 'Kategorie média není zobrazená na veřejném webu.';
        return '';
    }

    /** Nejnovější fotky ze všech veřejných kategorií. @return list<array<string, mixed>> */
    public function nejnovejsi(int $n = 12): array
    {
        return $this->app->db->q('SELECT m.*, c.name AS kategorie_nazev FROM media m JOIN categories c ON c.id = m.category_id WHERE ' . self::VEREJNA
            . ' ORDER BY m.id DESC LIMIT ' . max(1, $n))->fetchAll();
    }

    /** Úvodní fotka: zvolená v administraci (musí mít veřejný odkaz), jinak obálka první veřejné kategorie. @return ?array<string, mixed> */
    public function uvodni(?array $kategorie = null): ?array
    {
        $id = (int)($this->nastaveni()['uvod_id'] ?? 0);
        if ($id > 0) {
            $m = $this->app->db->q("SELECT * FROM media WHERE id = ? AND kind = 'image' AND archived_at IS NULL AND public_key IS NOT NULL", [$id])->fetch();
            if ($m) return $m;
        }
        $kategorie ??= $this->kategorie();
        return $kategorie[0]['obalka'] ?? null;
    }

    /** Adresa náhledu fotky (trvalá, přes veřejný odkaz). */
    public function obrazek(array $m, int $px): string
    {
        return (string)($this->app->media()->adresyNahledu($m)['adresy'][(string)$px] ?? '');
    }

    /** Titulek fotky: název bez přípony. */
    public static function titulek(array $m): string
    {
        $n = (string)pathinfo((string)$m['original_name'], PATHINFO_FILENAME);
        return $n !== '' ? $n : (string)$m['original_name'];
    }

    /** Část adresy kategorie: „5-priroda“. */
    public static function slug(array $k): string
    {
        $t = Hledani::normalizuj((string)$k["name"]);
        $t = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($t)), '-');
        return (int)$k['id'] . ($t !== '' ? '-' . substr($t, 0, 60) : '');
    }

    /** Uloží nastavení kategorie pro web (stránka Kategorie). @param array<string, mixed> $p */
    public function ulozKategorii(int $id, array $p): void
    {
        $this->app->kategorie()->radek($id);
        $obalka = (int)($p['web_cover_id'] ?? 0);
        if ($obalka > 0 && !$this->app->db->q("SELECT 1 FROM media WHERE id = ? AND category_id = ? AND kind = 'image'", [$obalka, $id])->fetchColumn()) {
            throw new Chyba('Obálka musí být obrázek z této kategorie.');
        }
        // pořadí (0.8.8) se nastavuje přetažením v seznamu kategorií (Kategorie::nastavPoradi), formulář ho nemění
        $this->app->db->q('UPDATE categories SET web_public = ?, web_description = ?, web_cover_id = ?, updated_at = ? WHERE id = ?', [
            !empty($p['web_public']) ? 1 : 0, Vstup::text($p, 'web_description', 'Popis na webu', 500, false), $obalka > 0 ? $obalka : null, time(), $id]);
    }

    /** Obrázky kategorie s veřejným odkazem pro výběr obálky. @return array<int, string> */
    public function obrazkyKategorie(int $id, int $limit = 300): array
    {
        $out = [];
        foreach ($this->app->db->q("SELECT id, original_name FROM media WHERE category_id = ? AND kind = 'image' AND archived_at IS NULL AND public_key IS NOT NULL ORDER BY id DESC LIMIT " . max(1, $limit), [$id])->fetchAll() as $r) {
            $out[(int)$r['id']] = (string)$r['original_name'];
        }
        return $out;
    }

    /** Počet fotek, které kategorie ukazuje na webu (jen obrázky s veřejným odkazem mimo archiv). */
    public function pocetVerejnych(int $kategorie): int
    {
        return (int)$this->app->db->q("SELECT COUNT(*) FROM media WHERE category_id = ? AND kind = 'image' AND archived_at IS NULL AND public_key IS NOT NULL", [$kategorie])->fetchColumn();
    }
}
