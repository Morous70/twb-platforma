<?php
declare(strict_types=1);
namespace MediaGalerie;

use TWB\Licence\Licence;
use TWB\Zalohy\Zalohy;

/**
 * Média z pohledu aplikace: seznam s filtry, kategorie, poznámka, veřejný odkaz, náhledy.
 * Soubory, nahrávání, tagy, archiv a komprese obstarává modul galerie (App::galerie()).
 */
final class Media
{
    public const DRUHY = ['image' => 'Obrázky', 'video' => 'Videa', 'pdf' => 'PDF'];
    public const RAZENI = ['nove' => 'Nejnovější', 'stare' => 'Nejstarší', 'nazev' => 'Název', 'velikost' => 'Největší'];
    /** Médií na stránku v administraci. 0.8.5: dělitelné 2, 3, 4, 6, 8 i 12 – počty sloupců mřížky v assets/style.css (.galerie-obal). */
    public const NA_STRANKU = 48;
    /** Náhled v mřížce: nejdelší strana v px. */
    public const NAHLED_PX = 480;
    /** Větší obrázky se pro náhled nezmenšují (paměť GD), pošle se rovnou soubor. */
    public const NAHLED_MAX_PIXELU = 40_000_000;
    /** Velikosti náhledů pro jiné aplikace (delší strana v px, API a veřejný odkaz). */
    public const NAHLEDY = [150, 320, 640, 1280, 1920];
    /** Náhled v okně galerie (delší strana v px, jedna z NAHLEDY). */
    public const NAHLED_VELKY_PX = 1920;
    /** Zobrazení galerie v administraci (pamatuje se v místním nastavení galerie_zobrazeni). */
    public const ZOBRAZENI = ['mrizka' => 'Mřížka', 'seznam' => 'Seznam', 'media' => 'Jen média'];
    public const DRUH_JEDNOTNE = ['image' => 'Obrázek', 'video' => 'Video', 'pdf' => 'PDF'];
    public const ORIENTACE = ['na_sirku' => 'Na šířku', 'na_vysku' => 'Na výšku', 'ctverec' => 'Čtverec'];
    /** Podepsaná adresa náhledu platí 1–2 hodiny (zaokrouhleno na celé hodiny, aby se adresa mezi požadavky neměnila). */
    public const PODPIS_HODIN = 2;

    public function __construct(private App $app) {}

    /** Náhodný klíč veřejného odkazu (96 bitů, neuhodnutelný). */
    public static function novyKlic(): string { return bin2hex(random_bytes(12)); }

    /** Řádek z tabulky media (sloupce databáze) nebo Chyba. @return array<string, mixed> */
    public function radek(int $id): array
    {
        return $this->app->db->q('SELECT * FROM media WHERE id = ?', [$id])->fetch() ?: throw new Chyba('Médium nenalezeno.');
    }

    public function podleKlice(string $klic): ?array
    {
        if (!preg_match('/^[0-9a-f]{24}$/', $klic)) return null;
        return $this->app->db->q('SELECT * FROM media WHERE public_key = ?', [$klic])->fetch() ?: null;
    }

    /**
     * Seznam podle filtru. Filtr: q (slova hledaná v názvu, tagech, autorovi a poznámce bez ohledu na diakritiku, viz Hledani;
     * q_vse false = stačí kterékoli slovo), druh, kategorie (id, 0 = bez kategorie), tag, archiv (bool), razeni, strana.
     * Navíc (API): orientace, min_sirka, min_vyska, tagy (seznam) s tagy_vse (true = všechny, false = kterýkoli), id (seznam; pořadí
     * se zachová, když razeni chybí), razeni „nahodne“ se semenem seme (stejné semeno = stejné pořadí, stránkování funguje),
     * razeni „relevance“ (výchozí, když je q a razeni chybí): podle skóre shody, při shodě nejnovější.
     * @param array<string, mixed> $f
     * @return array{polozky: list<array<string, mixed>>, celkem: int, strana: int, stran: int}
     */
    public function seznam(array $f, int $naStranku = self::NA_STRANKU): array
    {
        $kde = [!empty($f['archiv']) ? 'archived_at IS NOT NULL' : 'archived_at IS NULL'];
        $p = [];
        $vyrazy = Hledani::vyrazy((string)($f['q'] ?? ''));
        if (isset(self::DRUHY[$f['druh'] ?? ''])) { $kde[] = 'kind = ?'; $p[] = $f['druh']; }
        if (!empty($f['doporucene'])) $kde[] = 'featured = 1';
        $o = (string)($f['orientace'] ?? '');
        if ($o !== '') $kde[] = 'width > 0 AND height > 0 AND ' . match ($o) {
            'na_sirku' => 'width * 100 > height * 102', 'na_vysku' => 'height * 100 > width * 102', default => 'width * 100 <= height * 102 AND height * 100 <= width * 102',
        };
        foreach (['min_sirka' => 'width', 'min_vyska' => 'height'] as $k => $sl) {
            if (!empty($f[$k])) { $kde[] = "$sl >= ?"; $p[] = (int)$f[$k]; }
        }
        $ids = array_values(array_unique(array_map('intval', (array)($f['id'] ?? []))));
        if ($ids) { $kde[] = 'id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; array_push($p, ...$ids); }
        if (isset($f['kategorie']) && $f['kategorie'] !== '' && $f['kategorie'] !== null) {
            if ((int)$f['kategorie'] === 0) $kde[] = 'category_id IS NULL';
            else { $kde[] = 'category_id = ?'; $p[] = (int)$f['kategorie']; }
        }
        $poradi = match ($f['razeni'] ?? 'nove') {
            'stare' => 'id ASC', 'nazev' => 'original_name COLLATE NOCASE ASC, id DESC', 'velikost' => 'size DESC, id DESC', default => 'id DESC',
        };
        $radky = $this->app->db->q('SELECT * FROM media WHERE ' . implode(' AND ', $kde) . " ORDER BY $poradi", $p)->fetchAll();
        $hledane = array_map([\TWB\Tagy\Tagy::class, 'klic'], array_values(array_filter(array_map('trim', array_merge([(string)($f['tag'] ?? '')], (array)($f['tagy'] ?? []))), 'strlen')));
        if ($hledane) {
            $vse = (bool)($f['tagy_vse'] ?? true);
            $radky = array_values(array_filter($radky, function ($r) use ($hledane, $vse) {
                $ma = array_map([\TWB\Tagy\Tagy::class, 'klic'], self::tagy($r));
                $shoda = count(array_intersect($hledane, $ma));
                return $vse ? $shoda === count(array_unique($hledane)) : $shoda > 0;
            }));
        }
        if ($vyrazy) {
            // hledání ve všech textových polích a tazích, bez diakritiky (Hledani); skóre se přidá k řádku jako _skore
            $vse = (bool)($f['q_vse'] ?? true);
            $nalezene = [];
            foreach ($radky as $r) {
                $sk = Hledani::skore($r, $vyrazy, $vse);
                if ($sk !== null) $nalezene[] = $r + ['_skore' => $sk];
            }
            $radky = $nalezene;
        }
        if ($vyrazy && in_array($f['razeni'] ?? '', ['', 'relevance'], true)) {
            usort($radky, fn($a, $b) => [$b['_skore'], (int)$b['id']] <=> [$a['_skore'], (int)$a['id']]);
        } elseif (($f['razeni'] ?? '') === 'nahodne') {
            $seme = (string)($f['seme'] ?? '');
            usort($radky, fn($a, $b) => strcmp(md5($seme . ':' . $a['id']), md5($seme . ':' . $b['id'])));
        } elseif ($ids && empty($f['razeni'])) {
            $poz = array_flip($ids);
            usort($radky, fn($a, $b) => $poz[(int)$a['id']] <=> $poz[(int)$b['id']]);
        }
        $celkem = count($radky);
        $stran = max(1, (int)ceil($celkem / max(1, $naStranku)));
        $strana = min($stran, max(1, (int)($f['strana'] ?? 1)));
        return ['polozky' => array_slice($radky, ($strana - 1) * $naStranku, $naStranku), 'celkem' => $celkem, 'strana' => $strana, 'stran' => $stran];
    }

    /** @return list<string> */
    public static function tagy(array $r): array
    {
        $t = json_decode((string)($r['tags'] ?? ''), true);
        return is_array($t) ? array_values(array_map('strval', $t)) : [];
    }

    /** Počty podle druhu a velikost (bez archivu) a počet v archivu. @return array{image:int, video:int, pdf:int, velikost:int, archiv:int} */
    public function souhrn(): array
    {
        $out = ['image' => 0, 'video' => 0, 'pdf' => 0, 'velikost' => 0, 'archiv' => 0];
        foreach ($this->app->db->q('SELECT kind, COUNT(*) AS n, COALESCE(SUM(size), 0) AS s FROM media WHERE archived_at IS NULL GROUP BY kind')->fetchAll() as $r) {
            $out[$r['kind']] = (int)$r['n'];
            $out['velikost'] += (int)$r['s'];
        }
        $out['archiv'] = (int)$this->app->db->q('SELECT COUNT(*) FROM media WHERE archived_at IS NOT NULL')->fetchColumn();
        return $out;
    }

    // ---------- nahrání ----------

    /** Obrázky, které jde nahrát v ZIPu. */
    public const ZIP_PRIPONY = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    /** Nejvýš souborů v jednom ZIPu (ochrana serveru). */
    public const ZIP_MAX_SOUBORU = 2000;

    /**
     * Nahraje hotový soubor $cesta (přesune se) do galerie. U nového obrázku načte metadata: název (když $nazevPevny je false),
     * štítky (přidají se k $tagy), autora a licenci. Nahrazení souboru ($nahradit) metadata nečte, údaje média zůstanou.
     * @param list<string> $tagy
     * @return array{id:int, poznamka:?string, nahrazeno:bool, nazev:string}
     */
    public function nahraj(string $cesta, string $nazev, array $tagy = [], ?int $kategorie = null, ?int $nahradit = null, bool $nazevPevny = false): array
    {
        $md = null;
        // název bez přípony (např. jen titulek z jiné aplikace): přípona podle obsahu souboru
        $nazev = $this->app->galerie()->nazevSPriponou($nazev, \TWB\Galerie\Galerie::zjistiMime($cesta));
        $pripona = strtolower(pathinfo($nazev, PATHINFO_EXTENSION));
        if ($nahradit === null && in_array($pripona, self::ZIP_PRIPONY, true)) {
            try { $md = Metadata::cti($cesta); } catch (\Throwable $e) { $this->app->log('warn', "Metadata souboru {$nazev} nejdou přečíst: " . $e->getMessage()); }
            if ($md) {
                if (!$nazevPevny && $md['nazev'] !== null) $nazev = self::nazevZTitulku($md['nazev'], $pripona);
                $tagy = self::spojTagy($tagy, $md['tagy']);
            }
        }
        $v = $this->app->galerie()->uloz($cesta, $nazev, $tagy, $nahradit);
        $this->poNahrani($v['id'], $nahradit === null ? $kategorie : null);
        if ($md && ($md['autor'] !== null || $md['licence'] !== null || $md['licence_url'] !== null)) {
            $this->nastavAutora($v['id'], $md['autor'] !== null ? mb_substr($md['autor'], 0, 200) : null,
                $md['licence'] !== null ? mb_substr($md['licence'], 0, 2000) : null, $md['licence_url'] !== null ? mb_substr($md['licence_url'], 0, 500) : null);
            $o = Licence::odhad($md['licence'], $md['licence_url']);
            if ($o['kod'] !== null) $this->nastavLicenci($v['id'], $o['kod'], $o['verze']);
        }
        return $v + ['nazev' => $nazev];
    }

    /**
     * ZIP s obrázky: každý obrázek jako samostatné médium (metadata jako u nahrání jednoho souboru). Složky, skryté soubory,
     * __MACOSX a jiné typy se přeskočí. ZIP se po zpracování smaže.
     * @param list<string> $tagy
     * @return array{media: list<int>, preskoceno: list<string>, chyby: list<string>}
     */
    public function nahrajZip(string $zip, array $tagy = [], ?int $kategorie = null): array
    {
        $out = ['media' => [], 'preskoceno' => [], 'chyby' => []];
        try {
            if (!class_exists(\ZipArchive::class)) throw new Chyba('PHP na serveru nemá rozšíření zip.');
            $z = new \ZipArchive();
            if ($z->open($zip, \ZipArchive::RDONLY) !== true) throw new Chyba('Soubor není platný ZIP.');
            if ($z->numFiles > self::ZIP_MAX_SOUBORU) { $z->close(); throw new Chyba('ZIP má víc než ' . self::ZIP_MAX_SOUBORU . ' souborů, rozdělte ho.'); }
            $tmp = $this->app->data . '/tmp';
            if (!is_dir($tmp)) @mkdir($tmp, 0775, true);
            @set_time_limit(600);
            $celkem = 0;
            for ($i = 0; $i < $z->numFiles; $i++) {
                $st = $z->statIndex($i, \ZipArchive::FL_ENC_RAW);
                if ($st === false) continue;
                $jmeno = self::jmenoVZipu((string)$st['name']);
                $zaklad = basename($jmeno);
                if (str_ends_with($jmeno, '/') || $zaklad === '') continue;
                if (str_starts_with($jmeno, '__MACOSX/') || str_starts_with($zaklad, '.') || in_array(strtolower($zaklad), ['thumbs.db', 'desktop.ini'], true)) continue;
                if (!in_array(strtolower(pathinfo($zaklad, PATHINFO_EXTENSION)), self::ZIP_PRIPONY, true)) { $out['preskoceno'][] = $jmeno; continue; }
                if (($st['encryption_method'] ?? 0) !== 0) { $out['chyby'][] = "{$jmeno}: soubor je zaheslovaný."; continue; }
                $celkem += (int)$st['size'];
                if ($celkem > App::MAX_NAHRANI) { $out['chyby'][] = "{$jmeno} a další: rozbalený ZIP by byl větší než " . (int)(App::MAX_NAHRANI / 1048576) . ' MB.'; break; }
                $cil = $tmp . '/zip-' . bin2hex(random_bytes(8));
                try {
                    self::rozbal($z, $i, $cil, (int)$st['size']);
                    $out['media'][] = $this->nahraj($cil, $zaklad, $tagy, $kategorie)['id'];
                } catch (\RuntimeException | \InvalidArgumentException $e) {
                    @unlink($cil);
                    $out['chyby'][] = "{$jmeno}: " . $e->getMessage();
                }
            }
            $z->close();
        } finally {
            @unlink($zip);
        }
        $this->app->log('info', 'ZIP: nahráno ' . count($out['media']) . ' obrázků' . ($out['preskoceno'] ? ', přeskočeno ' . count($out['preskoceno']) : '')
            . ($out['chyby'] ? ', chyby ' . count($out['chyby']) : '') . '.');
        return $out;
    }

    /** Rozbalí jednu položku po kouscích a hlídá, aby nebyla větší, než ZIP tvrdí (ochrana proti „zip bombě“). */
    private static function rozbal(\ZipArchive $z, int $i, string $cil, int $velikost): void
    {
        $in = method_exists($z, 'getStreamIndex') ? $z->getStreamIndex($i) : $z->getStream($z->getNameIndex($i, \ZipArchive::FL_UNCHANGED) ?: '');
        if (!$in) throw new Chyba('Soubor v ZIPu nejde přečíst.');
        $out = fopen($cil, 'wb');
        if (!$out) { fclose($in); throw new Chyba('Nelze zapsat dočasný soubor.'); }
        $n = 0;
        try {
            while (!feof($in)) {
                $kus = fread($in, 1048576);
                if ($kus === false) throw new Chyba('Soubor v ZIPu je poškozený.');
                $n += strlen($kus);
                if ($n > $velikost) throw new Chyba('Soubor v ZIPu je poškozený (velikost nesouhlasí).');
                fwrite($out, $kus);
            }
        } finally {
            fclose($in); fclose($out);
        }
    }

    /** Jména v ZIPech z Windows bývají v kódování CP852 (DOS), ne UTF-8. */
    private static function jmenoVZipu(string $jmeno): string
    {
        $jmeno = str_replace('\\', '/', $jmeno);
        if (mb_check_encoding($jmeno, 'UTF-8')) return $jmeno;
        $u = function_exists('iconv') ? @iconv('CP852', 'UTF-8//IGNORE', $jmeno) : false;
        return $u !== false && $u !== '' ? $u : mb_convert_encoding($jmeno, 'UTF-8', 'ISO-8859-1');
    }

    /** Název média z titulku v metadatech: bez lomítek, nejvýš 180 znaků, s příponou souboru. */
    public static function nazevZTitulku(string $titulek, string $pripona): string
    {
        $t = trim((string)preg_replace('/[\/\\\\:*?"<>|]+/u', ' ', $titulek));
        $t = rtrim(mb_substr($t, 0, 180), ' .');
        if ($t === '') return 'obrazek.' . $pripona;
        return strtolower(pathinfo($t, PATHINFO_EXTENSION)) === $pripona ? $t : "$t.$pripona";
    }

    /** Tagy zadané při nahrání + štítky z metadat: bez opakování, jen platné (do 30 znaků), celkem nejvýš 10. @return list<string> */
    public static function spojTagy(array $zadane, array $zMetadat): array
    {
        $out = [];
        foreach (array_merge($zadane, $zMetadat) as $t) {
            $t = trim((string)preg_replace('/[\s,;]+/u', ' ', (string)$t));
            if ($t === '' || mb_strlen($t) > \TWB\Tagy\Tagy::MAX_DELKA) continue;
            $k = mb_strtolower($t);
            if (!isset($out[$k])) $out[$k] = $t;
            if (count($out) >= \TWB\Tagy\Tagy::MAX_POCET) break;
        }
        return array_values($out);
    }

    // ---------- úpravy ----------

    /** Autor a licence (null = smazat). */
    public function nastavAutora(int $id, ?string $autor, ?string $licence, ?string $licenceUrl): void
    {
        $this->radek($id);
        if ($licenceUrl !== null && !preg_match('~^https?://\S+$~i', $licenceUrl)) throw new Chyba('Odkaz na licenci musí začínat http:// nebo https://.');
        $this->app->db->q('UPDATE media SET author = ?, license = ?, license_url = ? WHERE id = ?', [$autor, $licence, $licenceUrl, $id]);
    }

    /** Licence ze společného číselníku (kód null = neuvedena); verze se ověří a zahodí, kde nedává smysl. */
    public function nastavLicenci(int $id, ?string $kod, ?string $verze): void
    {
        $this->radek($id);
        try { [$kod, $verze] = Licence::vycisti($kod, $verze); } catch (\InvalidArgumentException $e) { throw new Chyba($e->getMessage()); }
        $this->app->db->q('UPDATE media SET license_code = ?, license_version = ? WHERE id = ?', [$kod, $verze, $id]);
    }

    /** Uvedení autora pro použití média jinde: text, HTML a zda je povinné (podle licence). @return array{text:string, html:string, povinne:bool} */
    public static function uvedeni(array $r): array
    {
        $u = Licence::uvedeni(['autor' => $r['author'] ?? null, 'kod' => $r['license_code'] ?? null, 'verze' => $r['license_version'] ?? null, 'licence_url' => $r['license_url'] ?? null],
            ($r['kind'] ?? 'image') === 'video' ? 'Video: ' : 'Foto: ');
        return ['text' => $u['text'], 'html' => $u['html'], 'povinne' => $u['povinne']];
    }

    /** Orientace obrázku nebo videa (čtverec = strany se liší nejvýš o 2 %). Null = rozměr neznámý. */
    public static function orientace(array $r): ?string
    {
        $w = (int)($r['width'] ?? 0); $h = (int)($r['height'] ?? 0);
        if ($w <= 0 || $h <= 0) return null;
        if ($w * 100 > $h * 102) return 'na_sirku';
        if ($h * 100 > $w * 102) return 'na_vysku';
        return 'ctverec';
    }

    /** Po nahrání: kategorie, poznámka a veřejný odkaz (modul galerie tyto sloupce nezná). */
    public function poNahrani(int $id, ?int $kategorie): void
    {
        $r = $this->radek($id);
        if ($r['public_key'] === null) $this->app->db->q('UPDATE media SET public_key = ? WHERE id = ?', [self::novyKlic(), $id]);
        if ($kategorie !== null) $this->nastavKategorii([$id], $kategorie);
    }

    /** Přiřadí média kategorii (null = bez kategorie). Vrací počet změněných. @param list<int> $ids */
    public function nastavKategorii(array $ids, ?int $kategorie): int
    {
        if ($kategorie !== null) $this->app->kategorie()->radek($kategorie);
        $n = 0;
        foreach ($ids as $id) $n += $this->app->db->q('UPDATE media SET category_id = ? WHERE id = ? AND category_id IS NOT ?', [$kategorie, (int)$id, $kategorie])->rowCount();
        return $n;
    }

    /** Označí média jako doporučená (nebo doporučení zruší); vrátí počet změněných. Doporučená fotka se na webu ukáže, jen když je veřejná (Web::VEREJNA). @param list<int> $ids */
    public function nastavDoporucene(array $ids, bool $doporucene): int
    {
        $n = 0;
        foreach ($ids as $id) $n += $this->app->db->q('UPDATE media SET featured = ? WHERE id = ? AND featured <> ?', [(int)$doporucene, (int)$id, (int)$doporucene])->rowCount();
        return $n;
    }

    public function nastavPoznamku(int $id, ?string $poznamka): void
    {
        $this->radek($id);
        $this->app->db->q('UPDATE media SET note = ? WHERE id = ?', [$poznamka, $id]);
    }

    /** Nový veřejný odkaz (starý přestane fungovat). */
    public function novyOdkaz(int $id): string
    {
        $r = $this->radek($id);
        $k = self::novyKlic();
        $this->app->db->q('UPDATE media SET public_key = ? WHERE id = ?', [$k, $id]);
        $this->app->log('info', "Nový veřejný odkaz: {$r['original_name']}");
        return $k;
    }

    /** Veřejný odkaz vypnout (soubor bude jen v administraci a přes API). */
    public function zrusOdkaz(int $id): void
    {
        $r = $this->radek($id);
        $this->app->db->q('UPDATE media SET public_key = NULL WHERE id = ?', [$id]);
        $this->app->log('info', "Veřejný odkaz vypnut: {$r['original_name']}");
    }

    /** Veřejná adresa souboru: base_url/soubor.php/<klíč>/<název> (název jen pro čitelnost a příponu). */
    public function verejnaAdresa(array $r): ?string
    {
        if (empty($r['public_key'])) return null;
        $nazev = preg_replace('/[^\p{L}\p{N}._-]+/u', '-', (string)$r['original_name']) ?: 'soubor';
        return $this->app->url('soubor.php/' . $r['public_key'] . '/' . rawurlencode(trim($nazev, '-')));
    }

    // ---------- náhledy ----------

    /** Cesta k náhledu obrázku v administraci (JPEG v data/nahledy, vytvoří se při prvním použití). Null = poslat původní soubor. */
    public function nahled(array $r): ?string { return $this->varianta($r, self::NAHLED_PX); }

    /**
     * Zmenšená varianta obrázku: delší strana nejvýš $px, JPEG (průhlednost na bílém podkladu). Vytvoří se jednou a uloží
     * do data/nahledy/<id>-<md5>-<px>.jpg; po nahrazení souboru vznikne nová (jiné md5), staré smaže úklid.
     * Null = není obrázek, je menší než $px, nebo je příliš velký pro GD – pošle se původní soubor.
     */
    public function varianta(array $r, int $px): ?string
    {
        if ($r['kind'] !== 'image' || !function_exists('imagecreatefromstring') || $px < 16) return null;
        $w = (int)$r['width']; $h = (int)$r['height'];
        if ($w <= 0 || $h <= 0 || $w * $h > self::NAHLED_MAX_PIXELU || max($w, $h) <= $px) return null;
        $slozka = $this->app->data . '/nahledy';
        $cil = $slozka . '/' . (int)$r['id'] . '-' . $r['md5'] . '-' . $px . '.jpg';
        if (is_file($cil)) return $cil;
        $zdroj = $this->app->data . '/media/' . basename((string)$r['filename']);
        $data = @file_get_contents($zdroj);
        $im = $data !== false ? @imagecreatefromstring($data) : false;
        if (!$im) return null;
        $k = $px / max($w, $h);
        $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
        $out = imagecreatetruecolor($nw, $nh);
        // průhlednost na šachovnici by v JPEG zčernala – podklad bílý
        imagefill($out, 0, 0, (int)imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        if (!is_dir($slozka)) @mkdir($slozka, 0775, true);
        // náhledy předchozího obsahu (jiné md5) pryč
        foreach (glob($slozka . '/' . (int)$r['id'] . '-*.jpg') ?: [] as $stary) {
            if (!str_starts_with(basename($stary), (int)$r['id'] . '-' . $r['md5'] . '-')) @unlink($stary);
        }
        $tmp = $cil . '.' . bin2hex(random_bytes(4));
        if (!imagejpeg($out, $tmp, $px <= 320 ? 80 : 85)) { @unlink($tmp); return null; }
        rename($tmp, $cil);
        return $cil;
    }

    /** Smaže náhledy médií, která už neexistují nebo mají jiný obsah. */
    public function uklidNahledy(): int
    {
        $platne = [];
        foreach ($this->app->db->q('SELECT id, md5 FROM media')->fetchAll() as $r) $platne[$r['id'] . '-' . $r['md5']] = true;
        $n = 0;
        foreach (glob($this->app->data . '/nahledy/*.jpg') ?: [] as $f) {
            $k = preg_match('/^(\d+-[0-9a-f]{32})-\d+\.jpg$/', basename($f), $m) ? $m[1] : '';
            if (!isset($platne[$k]) && @unlink($f)) $n++;
        }
        return $n;
    }

    /** Tajemství pro podpis adres náhledů (vytvoří se při prvním použití; místní nastavení, obnova zálohy ho nepřepíše). */
    private function tajemstvi(): string
    {
        $n = $this->app->nastaveni();
        $t = (string)$n->dej('nahled_tajemstvi', '');
        if (strlen($t) < 32) { $t = bin2hex(random_bytes(32)); $n->uloz('nahled_tajemstvi', $t); }
        return $t;
    }

    /** Konec platnosti podepsaných adres vydaných teď (unix čas, celá hodina). */
    public static function platnostPodpisu(?int $ted = null): int { return (intdiv($ted ?? time(), 3600) + self::PODPIS_HODIN) * 3600; }

    public function podpis(int $id, int $px, int $do): string { return substr(hash_hmac('sha256', "$id/$px/$do", $this->tajemstvi()), 0, 32); }

    /** Ověří podepsanou adresu náhledu (nahled.php/<id>/<px>/<platí do>/<podpis>). */
    public function overPodpis(int $id, int $px, int $do, string $podpis, ?int $ted = null): bool
    {
        return in_array($px, self::NAHLEDY, true) && $do >= ($ted ?? time()) && $do <= ($ted ?? time()) + self::PODPIS_HODIN * 3600
            && hash_equals($this->podpis($id, $px, $do), $podpis);
    }

    /**
     * Adresy náhledů pro jiné aplikace (klíč = delší strana v px). Médium s veřejným odkazem má trvalé adresy
     * (soubor.php/<klíč>/w<px>/<název>?v=<md5>), ostatní podepsané s omezenou platností. Jen obrázky; jinak null.
     * @return ?array{adresy: array<string, string>, plati_do: ?int}
     */
    public function adresyNahledu(array $r): ?array
    {
        if ($r['kind'] !== 'image') return null;
        $nazev = trim((string)preg_replace('/[^\p{L}\p{N}._-]+/u', '-', pathinfo((string)$r['original_name'], PATHINFO_FILENAME)), '-') ?: 'nahled';
        $out = [];
        if (!empty($r['public_key'])) {
            foreach (self::NAHLEDY as $px) $out[(string)$px] = $this->app->url('soubor.php/' . $r['public_key'] . '/w' . $px . '/' . rawurlencode($nazev) . '.jpg?v=' . substr((string)$r['md5'], 0, 10));
            return ['adresy' => $out, 'plati_do' => null];
        }
        $do = self::platnostPodpisu();
        foreach (self::NAHLEDY as $px) {
            $out[(string)$px] = $this->app->url('nahled.php/' . (int)$r['id'] . '/' . $px . '/' . $do . '/' . $this->podpis((int)$r['id'], $px, $do) . '/' . rawurlencode($nazev) . '.jpg');
        }
        return ['adresy' => $out, 'plati_do' => $do];
    }

    /** Popis velikosti a rozměru pro seznamy. */
    public static function popis(array $r): string
    {
        $s = Zalohy::velikost((int)$r['size']);
        return $r['width'] ? (int)$r['width'] . '×' . (int)$r['height'] . ' · ' . $s : $s;
    }
}
