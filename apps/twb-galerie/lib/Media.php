<?php
declare(strict_types=1);
namespace MediaGalerie;

use TWB\Zalohy\Zalohy;

/**
 * Média z pohledu aplikace: seznam s filtry, kategorie, poznámka, veřejný odkaz, náhledy.
 * Soubory, nahrávání, tagy, archiv a komprese obstarává modul galerie (App::galerie()).
 */
final class Media
{
    public const DRUHY = ['image' => 'Obrázky', 'video' => 'Videa', 'pdf' => 'PDF'];
    public const RAZENI = ['nove' => 'Nejnovější', 'stare' => 'Nejstarší', 'nazev' => 'Název', 'velikost' => 'Největší'];
    public const NA_STRANKU = 48;
    /** Náhled v mřížce: nejdelší strana v px. */
    public const NAHLED_PX = 480;
    /** Větší obrázky se pro náhled nezmenšují (paměť GD), pošle se rovnou soubor. */
    public const NAHLED_MAX_PIXELU = 40_000_000;

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
     * Seznam podle filtru. Filtr: q (text v názvu a poznámce), druh, kategorie (id, 0 = bez kategorie), tag, archiv (bool), razeni, strana.
     * @param array<string, mixed> $f
     * @return array{polozky: list<array<string, mixed>>, celkem: int, strana: int, stran: int}
     */
    public function seznam(array $f, int $naStranku = self::NA_STRANKU): array
    {
        $kde = [!empty($f['archiv']) ? 'archived_at IS NOT NULL' : 'archived_at IS NULL'];
        $p = [];
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $kde[] = "(original_name LIKE ? ESCAPE '\\' OR note LIKE ? ESCAPE '\\')";
            $vzor = '%' . addcslashes($q, '%_\\') . '%';
            array_push($p, $vzor, $vzor);
        }
        if (isset(self::DRUHY[$f['druh'] ?? ''])) { $kde[] = 'kind = ?'; $p[] = $f['druh']; }
        if (isset($f['kategorie']) && $f['kategorie'] !== '' && $f['kategorie'] !== null) {
            if ((int)$f['kategorie'] === 0) $kde[] = 'category_id IS NULL';
            else { $kde[] = 'category_id = ?'; $p[] = (int)$f['kategorie']; }
        }
        $poradi = match ($f['razeni'] ?? 'nove') {
            'stare' => 'id ASC', 'nazev' => 'original_name COLLATE NOCASE ASC, id DESC', 'velikost' => 'size DESC, id DESC', default => 'id DESC',
        };
        $radky = $this->app->db->q('SELECT * FROM media WHERE ' . implode(' AND ', $kde) . " ORDER BY $poradi", $p)->fetchAll();
        $tag = trim((string)($f['tag'] ?? ''));
        if ($tag !== '') {
            $k = \TWB\Tagy\Tagy::klic($tag);
            $radky = array_values(array_filter($radky, fn($r) => in_array($k, array_map([\TWB\Tagy\Tagy::class, 'klic'], self::tagy($r)), true)));
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

    // ---------- úpravy ----------

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

    /** Cesta k náhledu obrázku (JPEG nebo PNG v data/nahledy, vytvoří se při prvním použití). Null = poslat původní soubor. */
    public function nahled(array $r): ?string
    {
        if ($r['kind'] !== 'image' || !function_exists('imagecreatefromstring')) return null;
        $w = (int)$r['width']; $h = (int)$r['height'];
        if ($w <= 0 || $h <= 0 || $w * $h > self::NAHLED_MAX_PIXELU || max($w, $h) <= self::NAHLED_PX) return null;
        $slozka = $this->app->data . '/nahledy';
        $cil = $slozka . '/' . (int)$r['id'] . '-' . $r['md5'] . '.jpg';
        if (is_file($cil)) return $cil;
        $zdroj = $this->app->data . '/media/' . basename((string)$r['filename']);
        $data = @file_get_contents($zdroj);
        $im = $data !== false ? @imagecreatefromstring($data) : false;
        if (!$im) return null;
        $k = self::NAHLED_PX / max($w, $h);
        $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
        $out = imagecreatetruecolor($nw, $nh);
        // průhlednost na šachovnici by v JPEG zčernala – podklad bílý
        imagefill($out, 0, 0, (int)imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        if (!is_dir($slozka)) @mkdir($slozka, 0775, true);
        foreach (glob($slozka . '/' . (int)$r['id'] . '-*.jpg') ?: [] as $stary) @unlink($stary);
        return imagejpeg($out, $cil, 82) ? $cil : null;
    }

    /** Smaže náhledy médií, která už neexistují nebo mají jiný obsah. */
    public function uklidNahledy(): int
    {
        $platne = [];
        foreach ($this->app->db->q('SELECT id, md5 FROM media')->fetchAll() as $r) $platne[$r['id'] . '-' . $r['md5'] . '.jpg'] = true;
        $n = 0;
        foreach (glob($this->app->data . '/nahledy/*.jpg') ?: [] as $f) if (!isset($platne[basename($f)]) && @unlink($f)) $n++;
        return $n;
    }

    /** Popis velikosti a rozměru pro seznamy. */
    public static function popis(array $r): string
    {
        $s = Zalohy::velikost((int)$r['size']);
        return $r['width'] ? (int)$r['width'] . '×' . (int)$r['height'] . ' · ' . $s : $s;
    }
}
