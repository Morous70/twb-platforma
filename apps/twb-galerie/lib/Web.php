<?php
declare(strict_types=1);
namespace MediaGalerie;

/**
 * Veřejný web galerie (0.8.0): hlavní stránka, Galerie (veřejné kategorie a jejich fotografie) a O galerii.
 * Veřejné je jen to, co uživatel výslovně zveřejní: kategorie se zaškrtnutým „Zobrazit na veřejném webu“ a v ní obrázky,
 * které nejsou v archivu a mají zapnutý veřejný odkaz (vypnutý odkaz = fotka zmizí i z webu). Obrázky se vydávají
 * přes trvalé adresy náhledů veřejného odkazu (soubor.php/<klíč>/w<px>/…), originál se na webu nenabízí.
 * Texty, úvodní fotka a reklamní plochy jsou v nastavení `web` (JSON, zálohuje se).
 * Reklamní plocha (0.8.2) je buď vlastní text s tlačítkem (`typ` = text), nebo kód z reklamního systému (`typ` = kod: HTML/JS
 * z AdSense, Skliku…), který se na web vloží bez úprav. Kód zadává jen přihlášený správce; stránka s kódem dostane volnější CSP.
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
        'o_galerii' => "Fotografie, příběhy a okamžiky zachycené na jednom místě.\n\nNové fotografie průběžně přibývají.",
        'patka' => '© TWB Galerie',
        'odkaz_administrace' => true,
        'skryt_vyhledavacum' => false,
        'banner1' => ['zobrazit' => true, 'typ' => 'text', 'stitek' => 'Reklamní banner', 'text' => 'Prostor pro reklamní sdělení', 'tlacitko' => 'Zjistit více', 'odkaz' => '?s=o-galerii', 'kod' => ''],
        'banner2' => ['zobrazit' => true, 'typ' => 'text', 'stitek' => 'Reklamní plocha', 'text' => 'Vaše reklama může být součástí této stránky', 'tlacitko' => 'Zobrazit nabídku', 'odkaz' => '?s=o-galerii', 'kod' => ''],
        'kod_hlavicka' => '',
        'ads_txt' => '',
    ];
    /** Druhy reklamní plochy. */
    public const TYPY_BANNERU = ['text' => 'Vlastní text a tlačítko', 'kod' => 'Kód z reklamního systému (AdSense, Sklik…)'];
    /** Největší délka vloženého kódu (reklamní plocha, hlavička) a obsahu ads.txt. */
    public const MAX_KOD = 20000;
    public const BANNERY = ['banner1' => 'Reklamní banner (pod úvodní fotkou)', 'banner2' => 'Reklamní plocha (pod galeriemi)'];
    public const NA_STRANKU = 36;
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
        foreach (array_keys(self::BANNERY) as $b) $out[$b] = array_replace(self::VYCHOZI[$b], is_array($n[$b] ?? null) ? array_intersect_key($n[$b], self::VYCHOZI[$b]) : []);
        return $out;
    }

    /** Uloží nastavení z formuláře (administrace → Veřejný web). @param array<string, mixed> $p */
    public function uloz(array $p): void
    {
        $n = $this->nastaveni();
        $n['zapnuto'] = !empty($p['zapnuto']);
        $n['odkaz_administrace'] = !empty($p['odkaz_administrace']);
        $n['skryt_vyhledavacum'] = !empty($p['skryt_vyhledavacum']);
        foreach (['nazev' => ['Název webu', 60, true], 'stitek' => ['Štítek nad nadpisem', 60, false], 'nadpis' => ['Nadpis', 120, true],
            'podnadpis' => ['Podnadpis', 300, false], 'tlacitko' => ['Text tlačítka', 40, true], 'poznamka' => ['Poznámka pod tlačítkem', 120, false],
            'vybrane_nadpis' => ['Nadpis vybraných galerií', 80, true], 'vybrane_podnadpis' => ['Podnadpis vybraných galerií', 160, false],
            'patka' => ['Patička', 200, false]] as $k => [$popis, $max, $povinne]) {
            $n[$k] = (string)Vstup::text($p, $k, $popis, $max, $povinne);
        }
        $n['o_galerii'] = (string)Vstup::text($p, 'o_galerii', 'Text stránky O galerii', 20000, false);
        $n['kod_hlavicka'] = self::kod($p, 'kod_hlavicka', 'Kód do hlavičky stránky');
        $n['ads_txt'] = self::kod($p, 'ads_txt', 'Obsah ads.txt');
        $pocet = (int)($p['vybrane_pocet'] ?? 4);
        if ($pocet < 0 || $pocet > 12) throw new Chyba('Počet vybraných galerií musí být 0 až 12.');
        $n['vybrane_pocet'] = $pocet;
        $uvod = (int)($p['uvod_id'] ?? 0);
        if ($uvod > 0 && !$this->muzeBytUvodni($uvod)) throw new Chyba('Úvodní fotka musí být obrázek se zapnutým veřejným odkazem, který není v archivu.');
        $n['uvod_id'] = $uvod > 0 ? $uvod : null;
        foreach (array_keys(self::BANNERY) as $b) {
            $v = [];
            foreach (array_keys(self::VYCHOZI[$b]) as $pole) $v[$pole] = $p["{$b}_{$pole}"] ?? null;
            $odkaz = trim((string)($v['odkaz'] ?? ''));
            if ($odkaz !== '' && !self::platnyOdkaz($odkaz)) throw new Chyba('Odkaz reklamní plochy musí začínat https://, http://, mailto:, tel:, / nebo ? (adresa na tomto webu).');
            $typ = (string)($v['typ'] ?? 'text');
            if (!isset(self::TYPY_BANNERU[$typ])) throw new Chyba('Neznámý druh reklamní plochy.');
            $kod = self::kod($v, 'kod', 'Kód reklamní plochy');
            if ($typ === 'kod' && $kod === '' && !empty($v['zobrazit'])) throw new Chyba('Vložte kód z reklamního systému, nebo plochu přepněte na vlastní text či skryjte.');
            $n[$b] = ['zobrazit' => !empty($v['zobrazit']), 'typ' => $typ, 'stitek' => (string)Vstup::text($v, 'stitek', 'Štítek reklamní plochy', 40, false),
                'text' => (string)Vstup::text($v, 'text', 'Text reklamní plochy', 200, false), 'tlacitko' => (string)Vstup::text($v, 'tlacitko', 'Tlačítko reklamní plochy', 40, false),
                'odkaz' => $odkaz, 'kod' => $kod];
        }
        $this->app->nastaveni()->uloz('web', (string)json_encode($n, JSON_UNESCAPED_UNICODE));
        $this->app->log('info', 'Nastavení veřejného webu změněno' . ($n['zapnuto'] ? '.' : ' (web vypnutý).'));
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

    /** Ukáže stránka vložený kód z reklamního systému (plocha s kódem nebo kód v hlavičce)? Pak potřebuje volnější CSP. @param array<string, mixed> $n */
    public static function maKod(array $n, array $plochy = []): bool
    {
        if ($n['kod_hlavicka'] !== '') return true;
        foreach ($plochy as $b) if ($n[$b]['zobrazit'] && $n[$b]['typ'] === 'kod' && $n[$b]['kod'] !== '') return true;
        return false;
    }

    /**
     * CSP veřejného webu. Bez reklamních kódů jen vlastní skripty a styly. S kódem musí projít skripty, rámečky a styly
     * reklamních systémů (AdSense, Sklik, Etarget… načítají další skripty z různých domén a vkládají vložené skripty a styly),
     * proto https: a 'unsafe-inline'; zůstává zákaz vložení stránky do cizího rámu, změny <base> a odeslání formulářů jinam.
     */
    public static function csp(bool $sKodem): string
    {
        if (!$sKodem) return "default-src 'self'; img-src 'self' https: data:; style-src 'self'; script-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'";
        return "default-src 'self' https:; img-src 'self' https: data: blob:; style-src 'self' 'unsafe-inline' https:; script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; "
            . "frame-src https:; connect-src 'self' https:; font-src 'self' https: data:; media-src 'self' https:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'";
    }

    /** Odkaz reklamní plochy: web (http/https), e-mail, telefon nebo adresa na tomto webu. Ne javascript: apod. */
    public static function platnyOdkaz(string $o): bool
    {
        if (preg_match('/[\x00-\x20"<>\\\\]/', $o)) return false;
        if (preg_match('~^https?://[^/?#]+~i', $o)) return filter_var($o, FILTER_VALIDATE_URL) !== false;
        return (bool)preg_match('~^(mailto:|tel:|/(?!/)|\?|#|\./)~i', $o);
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
        usort($r, fn($a, $b) => ((int)$a['web_order'] <=> (int)$b['web_order'])
            ?: strcmp(\TWB\Tagy\Tagy::klicRazeni($a['name']), \TWB\Tagy\Tagy::klicRazeni($b['name'])) ?: $a['id'] <=> $b['id']);
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
        $poradi = (int)($p['web_order'] ?? 0);
        if ($poradi < -999 || $poradi > 999) throw new Chyba('Pořadí musí být −999 až 999.');
        $this->app->db->q('UPDATE categories SET web_public = ?, web_description = ?, web_cover_id = ?, web_order = ?, updated_at = ? WHERE id = ?', [
            !empty($p['web_public']) ? 1 : 0, Vstup::text($p, 'web_description', 'Popis na webu', 500, false), $obalka > 0 ? $obalka : null, $poradi, time(), $id]);
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
