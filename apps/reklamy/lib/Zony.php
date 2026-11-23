<?php
declare(strict_types=1);
namespace Reklamy;

/**
 * Zóny = místa pro reklamu na webu. Do zóny se přiřazují celé kampaně (platí i pro bannery přidané později)
 * nebo jednotlivé bannery. Zóna může mít pevnou šířku, pevnou výšku, obojí, nebo nic: bere jen bannery,
 * které v zadaných rozměrech sedí (nezadaný rozměr je libovolný).
 *
 * Responzivní zóna (0.9) má místo jednoho rozměru seznam rozměrů (sloupec sizes, např. 970×250, 728×90, 300×250):
 * bere bannery všech těchto rozměrů a při doručení vybere ten nejširší, který se vejde do šířky místa na stránce
 * (Dorucovani::vyber). Banner se nikdy nezmenšuje – na mobilu se ukáže mobilní rozměr.
 * Prázdný rozměr (0.9.1) má výšku 0, např. 728x0: od této šířky až po další širší rozměr zóna nic nezobrazí.
 */
final class Zony
{
    public const DRUHY = ['banner' => 'Banner na stránce', 'popup' => 'Vyskakovací okno (popup)'];
    public const MAX_ROZMERU = 10;

    public function __construct(private App $app) {}

    /** @return list<array<string, mixed>> */
    public function seznam(?int $web = null): array
    {
        $sql = 'SELECT z.*, s.name AS site_name,
            (SELECT COUNT(*) FROM zone_campaigns zc WHERE zc.zone_id = z.id) AS campaigns,
            (SELECT COUNT(*) FROM zone_banners zb WHERE zb.zone_id = z.id) AS banners
            FROM zones z JOIN sites s ON s.id = z.site_id';
        return $web !== null ? $this->app->db->q("$sql WHERE z.site_id = ? ORDER BY z.name", [$web])->fetchAll()
            : $this->app->db->q("$sql ORDER BY s.name, z.name")->fetchAll();
    }

    /** @return array<string, mixed> */
    public function radek(int $id): array
    {
        return $this->app->db->q('SELECT z.*, s.name AS site_name FROM zones z JOIN sites s ON s.id = z.site_id WHERE z.id = ?', [$id])->fetch()
            ?: throw new Chyba('Zóna nenalezena.');
    }

    /** Rozměry responzivní zóny od nejširšího ([[970, 250], [728, 90], …]); prázdné = obyčejná zóna. @return list<array{0:int, 1:int}> */
    public static function rozmery(array $z): array
    {
        $r = json_decode((string)($z['sizes'] ?? ''), true);
        if (!is_array($r)) return [];
        $out = [];
        foreach ($r as $x) if (is_string($x) && preg_match('/^(\d+)x(\d+)$/', $x, $m)) $out[] = [(int)$m[1], (int)$m[2]];
        return $out;
    }

    /** Je zóna responzivní (seznam rozměrů)? */
    public static function jeResponzivni(array $z): bool { return self::rozmery($z) !== []; }

    /** Rozměry zóny pro bannery (bez prázdných). @return list<array{0:int, 1:int}> */
    public static function rozmeryBanneru(array $z): array
    {
        return array_values(array_filter(self::rozmery($z), fn($x) => $x[1] > 0));
    }

    /**
     * Prázdný rozměr, do kterého padne místo dané šířky: šířka nejširšího prázdného rozměru, který se ještě vejde (0 = žádný).
     * Bannery užší než tato šířka se pak nepoužijí, širší (do šířky místa) ano. $sirka 0 = bez omezení.
     */
    public static function prazdnaHranice(array $z, int $sirka): int
    {
        $h = 0;
        foreach (self::rozmery($z) as [$w, $v]) if ($v === 0 && ($sirka === 0 || $w <= $sirka)) $h = max($h, $w);
        return $h;
    }

    /** Popis jednoho rozměru: „300×250“, prázdný „728 px nic“. */
    public static function popisRozmeru(array $r): string { return $r[1] === 0 ? "{$r[0]} px nic" : "{$r[0]}×{$r[1]}"; }

    /**
     * Seznam rozměrů z formuláře („970x250, 728×90 300x250“), seřazený od nejširšího; null = prázdné.
     * Výška 0 (728x0) = prázdný rozměr: od této šířky nic. Jeho šířku nesmí mít jiný rozměr a seznam potřebuje aspoň jeden skutečný rozměr.
     * @return ?list<string>
     */
    public static function rozmeryZTextu(string $s): ?array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/u', trim($s)) ?: [] as $c) {
            if ($c === '') continue;
            if (!preg_match('/^(\d{1,4})[x×X](\d{1,4})$/u', $c, $m) || (int)$m[1] < 1 || (int)$m[1] > Bannery::MAX_ROZMER || (int)$m[2] > Bannery::MAX_ROZMER) {
                throw new Chyba("Rozměr „{$c}“ zadejte jako šířka×výška, např. 300x250 (prázdný rozměr 728x0).");
            }
            $out[(int)$m[1] . 'x' . (int)$m[2]] = [(int)$m[1], (int)$m[2]];
        }
        if (!$out) return null;
        foreach ($out as [$w, $v]) {
            if ($v === 0 && count(array_filter($out, fn($x) => $x[0] === $w)) > 1) throw new Chyba("Prázdný rozměr {$w}x0 nesmí mít stejnou šířku jako jiný rozměr zóny.");
        }
        if (!array_filter($out, fn($x) => $x[1] > 0)) throw new Chyba('Responzivní zóna potřebuje kromě prázdných i aspoň jeden rozměr banneru.');
        if (count($out) > self::MAX_ROZMERU) throw new Chyba('Responzivní zóna může mít nejvýš ' . self::MAX_ROZMERU . ' rozměrů.');
        uasort($out, fn($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        return array_keys($out);
    }

    /** Popis rozměru zóny: „300×250“, „1200 × libovolná výška“, „libovolná šířka × 100“, „responzivní 970×250, 300×250“ nebo „libovolný“. */
    public static function rozmer(array $z): string
    {
        if ($r = self::rozmery($z)) return 'responzivní ' . implode(', ', array_map(fn($x) => self::popisRozmeru($x), $r));
        $w = $z['width'] ?? null;
        $h = $z['height'] ?? null;
        if ($w !== null && $h !== null) return "{$w}×{$h}";
        if ($w !== null) return "{$w} × libovolná výška";
        if ($h !== null) return "libovolná šířka × {$h}";
        return 'libovolný';
    }

    /** Má zóna aspoň jeden pevný rozměr? */
    public static function maRozmer(array $z): bool
    {
        return self::jeResponzivni($z) || ($z['width'] ?? null) !== null || ($z['height'] ?? null) !== null;
    }

    /** Vejde se banner do zóny? Nezadaný rozměr zóny je libovolný. */
    public static function pasuje(array $zona, array $banner): bool
    {
        if ($r = self::rozmeryBanneru($zona)) return in_array([(int)$banner['width'], (int)$banner['height']], $r, true);
        return (($zona['width'] ?? null) === null || (int)$zona['width'] === (int)$banner['width'])
            && (($zona['height'] ?? null) === null || (int)$zona['height'] === (int)$banner['height']);
    }

    /** @param array<string, mixed> $v */
    public function uloz(array $v, ?int $id = null): int
    {
        $stara = $id !== null ? $this->radek($id) : null;
        $web = $this->app->weby()->radek((int)($v['site_id'] ?? $stara['site_id'] ?? 0));
        $d = [
            'site_id' => (int)$web['id'],
            'name' => Vstup::text($v, 'name', 'Název'),
            'kind' => Vstup::volba($v, 'kind', 'Druh', self::DRUHY, 'banner'),
            'width' => Vstup::cislo($v, 'width', 'Šířka', 1, Bannery::MAX_ROZMER),
            'height' => Vstup::cislo($v, 'height', 'Výška', 1, Bannery::MAX_ROZMER),
            'fallback' => null,
            'note' => Vstup::text($v, 'note', 'Poznámka', 5000, false),
            'updated_at' => time(),
        ];
        $sizes = self::rozmeryZTextu((string)($v['sizes'] ?? ''));
        if ($sizes !== null && ($d['width'] !== null || $d['height'] !== null)) throw new Chyba('Responzivní zóna má jen seznam rozměrů – šířku a výšku nechte prázdné.');
        $d['sizes'] = $sizes !== null ? json_encode($sizes) : null;
        $fb = (string)($v['fallback'] ?? '');
        if (strlen($fb) > Bannery::MAX_HTML) throw new Chyba('Náhradní HTML může mít nejvýš 512 kB.');
        $d['fallback'] = trim($fb) !== '' ? $fb : null;
        $db = $this->app->db;
        if ($stara === null) {
            $d['created_at'] = $d['updated_at'];
            $db->q('INSERT INTO zones (' . implode(', ', array_keys($d)) . ') VALUES (' . implode(', ', array_fill(0, count($d), '?')) . ')', array_values($d));
            $id = $db->posledniId();
            $this->app->log('info', "Nová zóna {$d['name']} na webu {$web['name']}");
            return $id;
        }
        $db->transakce(function () use ($db, $d, $id) {
            $db->q('UPDATE zones SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($d))) . ' WHERE id = ?', [...array_values($d), $id]);
            // přímo přiřazené bannery, které do nového rozměru nesedí, se odeberou
            foreach ($db->q('SELECT b.id, b.width, b.height FROM banners b JOIN zone_banners zb ON zb.banner_id = b.id WHERE zb.zone_id = ?', [$id])->fetchAll() as $b) {
                if (!self::pasuje($d, $b)) $db->q('DELETE FROM zone_banners WHERE zone_id = ? AND banner_id = ?', [$id, $b['id']]);
            }
        });
        return $id;
    }

    public function smaz(int $id): void
    {
        $z = $this->radek($id);
        $this->app->db->q('DELETE FROM zones WHERE id = ?', [$id]);
        $this->app->log('info', "Zóna smazána: {$z['name']}");
    }

    /** @return array{kampane: list<int>, bannery: list<int>} */
    public function prirazeni(int $id): array
    {
        $db = $this->app->db;
        return [
            'kampane' => array_map('intval', $db->q('SELECT campaign_id FROM zone_campaigns WHERE zone_id = ? ORDER BY campaign_id', [$id])->fetchAll(\PDO::FETCH_COLUMN)),
            'bannery' => array_map('intval', $db->q('SELECT banner_id FROM zone_banners WHERE zone_id = ? ORDER BY banner_id', [$id])->fetchAll(\PDO::FETCH_COLUMN)),
        ];
    }

    /**
     * Nastaví přiřazení zóny (nahradí dosavadní).
     * @param list<int|string> $kampane @param list<int|string> $bannery
     */
    public function prirad(int $id, array $kampane, array $bannery): void
    {
        $z = $this->radek($id);
        $db = $this->app->db;
        $kampane = array_values(array_unique(array_map('intval', $kampane)));
        $bannery = array_values(array_unique(array_map('intval', $bannery)));
        foreach ($kampane as $k) $this->app->kampane()->radek($k);
        foreach ($bannery as $b) {
            $r = $this->app->bannery()->radek($b);
            if (!self::pasuje($z, $r)) throw new Chyba("Banner „{$r['name']}“ ({$r['width']}×{$r['height']}) nemá rozměr zóny " . self::rozmer($z) . '.');
        }
        $db->transakce(function () use ($db, $id, $kampane, $bannery) {
            $db->q('DELETE FROM zone_campaigns WHERE zone_id = ?', [$id]);
            $db->q('DELETE FROM zone_banners WHERE zone_id = ?', [$id]);
            foreach ($kampane as $k) $db->q('INSERT INTO zone_campaigns (zone_id, campaign_id) VALUES (?, ?)', [$id, $k]);
            foreach ($bannery as $b) $db->q('INSERT INTO zone_banners (zone_id, banner_id) VALUES (?, ?)', [$id, $b]);
        });
    }

    /**
     * Bannery, které zóna může zobrazit (přímo přiřazené a z přiřazených kampaní, jen vhodného rozměru),
     * bez ohledu na stav a termín – ty řeší doručování.
     * @return list<array<string, mixed>>
     */
    public function bannery(int $id): array
    {
        $z = $this->radek($id);
        $rows = $this->app->db->q('SELECT b.* FROM banners b
            WHERE b.id IN (SELECT banner_id FROM zone_banners WHERE zone_id = ?)
               OR b.id IN (SELECT cb.banner_id FROM campaign_banners cb JOIN zone_campaigns zc ON zc.campaign_id = cb.campaign_id WHERE zc.zone_id = ?)
            ORDER BY b.name, b.id', [$id, $id])->fetchAll();
        return $this->app->bannery()->sKampanemi(array_values(array_filter($rows, fn($b) => self::pasuje($z, $b))));
    }

    /** Zóny, ve kterých se kampaň nebo banner může zobrazit. @return list<array<string, mixed>> */
    public function zonyBanneru(array $banner): array
    {
        $rows = $this->app->db->q('SELECT z.*, s.name AS site_name FROM zones z JOIN sites s ON s.id = z.site_id
            WHERE z.id IN (SELECT zone_id FROM zone_banners WHERE banner_id = ?)
               OR z.id IN (SELECT zc.zone_id FROM zone_campaigns zc JOIN campaign_banners cb ON cb.campaign_id = zc.campaign_id WHERE cb.banner_id = ?)
            ORDER BY s.name, z.name', [$banner['id'], $banner['id']])->fetchAll();
        return array_values(array_filter($rows, fn($z) => self::pasuje($z, $banner)));
    }

    /**
     * Odebere banner z přímých přiřazení zón, do kterých po změně rozměru nesedí.
     * (Zóny s kampaní banner berou podle rozměru samy.)
     */
    public function odeberNesedici(int $banner): void
    {
        $db = $this->app->db;
        $b = $db->q('SELECT id, width, height FROM banners WHERE id = ?', [$banner])->fetch();
        if (!$b) return;
        foreach ($db->q('SELECT z.* FROM zones z JOIN zone_banners zb ON zb.zone_id = z.id WHERE zb.banner_id = ?', [$banner])->fetchAll() as $z) {
            if (!self::pasuje($z, $b)) $db->q('DELETE FROM zone_banners WHERE zone_id = ? AND banner_id = ?', [$z['id'], $banner]);
        }
    }

    /**
     * Rozměr pro rám, náhled a vyskakovací okno. Nezadaný rozměr zóny se vezme jako největší z bannerů,
     * které zóna může zobrazit (aby se vešel každý), bez bannerů výchozí hodnota.
     * @return array{0:int, 1:int}
     */
    public function rozmerVlozeni(array $zona, int $vychoziW = 300, int $vychoziH = 250): array
    {
        if ($r = self::rozmeryBanneru($zona)) return [max(array_column($r, 0)), max(array_column($r, 1))];
        $w = ($zona['width'] ?? null) !== null ? (int)$zona['width'] : 0;
        $h = ($zona['height'] ?? null) !== null ? (int)$zona['height'] : 0;
        if ((!$w || !$h) && isset($zona['id'])) {
            try { $bannery = $this->bannery((int)$zona['id']); } catch (Chyba) { $bannery = []; }
            if (!$w && $bannery) $w = max(array_map(fn($b) => (int)$b['width'], $bannery));
            if (!$h && $bannery) $h = max(array_map(fn($b) => (int)$b['height'], $bannery));
        }
        return [$w ?: $vychoziW, $h ?: $vychoziH];
    }
}
