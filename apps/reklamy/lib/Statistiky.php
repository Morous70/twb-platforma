<?php
declare(strict_types=1);
namespace Reklamy;

/** Součty zobrazení, prokliků a konverzí z tabulky stats. */
final class Statistiky
{
    public function __construct(private App $app) {}

    /** @return array{impressions:int, clicks:int, conversions:int, revenue:int} */
    public function souhrn(?string $od = null, ?string $do = null, ?int $kampan = null, ?int $banner = null, ?int $zona = null): array
    {
        [$kde, $p] = $this->podminky($od, $do, $kampan, $banner, $zona);
        $r = $this->app->db->q('SELECT COALESCE(SUM(impressions),0) AS impressions, COALESCE(SUM(clicks),0) AS clicks, COALESCE(SUM(conversions),0) AS conversions,
            COALESCE(SUM(revenue),0) AS revenue FROM stats s' . $kde, $p)->fetch();
        return array_map('intval', $r);
    }

    /** Součty po bannerech. @return array<int, array{impressions:int, clicks:int, conversions:int, revenue:int}> */
    public function poBannerech(?string $od = null, ?string $do = null, ?int $kampan = null): array
    {
        [$kde, $p] = $this->podminky($od, $do, $kampan, null, null);
        $out = [];
        foreach ($this->app->db->q('SELECT banner_id, SUM(impressions) AS impressions, SUM(clicks) AS clicks, SUM(conversions) AS conversions, SUM(revenue) AS revenue
            FROM stats s' . $kde . ' GROUP BY banner_id', $p)->fetchAll() as $r) {
            $id = (int)$r['banner_id'];
            unset($r['banner_id']);
            $out[$id] = array_map('intval', $r);
        }
        return $out;
    }

    /** Seskupení přehledu statistik: klíč => popis. */
    public const PODLE = ['den' => 'Dny', 'tyden' => 'Týdny', 'mesic' => 'Měsíce', 'hodina' => 'Hodiny dne', 'kampan' => 'Kampaně', 'banner' => 'Bannery', 'zona' => 'Zóny', 'web' => 'Weby', 'zarizeni' => 'Zařízení'];
    /** Ukazatele: klíč => popis. */
    public const UKAZATELE = ['impressions' => 'Zobrazení', 'clicks' => 'Prokliky', 'conversions' => 'Konverze', 'revenue' => 'Hodnota'];

    /**
     * Přehled seskupený podle času nebo podle kampaní, bannerů, zón a webů.
     * Časové řady mají i dny (týdny, měsíce, hodiny) bez provozu; ostatní seskupení jen řádky s provozem, seřazené podle zobrazení.
     * Zařízení (0.9): počítač, tablet, mobil; data ze starších verzí jsou „neznámé zařízení“.
     * @param array{kampan?: ?int, banner?: ?int, zona?: ?int, web?: ?int, zarizeni?: ?string} $filtr
     * @return list<array{klic: string, popis: string, odkaz: ?array{0:string,1:array<string,int>}, impressions: int, clicks: int, conversions: int, revenue: int}>
     */
    public function prehled(string $od, string $do, string $podle, array $filtr = []): array
    {
        if (!isset(self::PODLE[$podle])) throw new Chyba('Neznámé seskupení statistik.');
        [$kde, $p] = $this->podminky($od, $do, $filtr['kampan'] ?? null, $filtr['banner'] ?? null, $filtr['zona'] ?? null, $filtr['web'] ?? null, $filtr['zarizeni'] ?? null);
        $sum = 'SUM(s.impressions) AS impressions, SUM(s.clicks) AS clicks, SUM(s.conversions) AS conversions, SUM(s.revenue) AS revenue';
        $db = $this->app->db;
        $cisla = fn(array $r) => ['impressions' => (int)$r['impressions'], 'clicks' => (int)$r['clicks'], 'conversions' => (int)$r['conversions'], 'revenue' => (int)$r['revenue']];
        $nula = ['impressions' => 0, 'clicks' => 0, 'conversions' => 0, 'revenue' => 0];
        if (in_array($podle, ['den', 'tyden', 'mesic', 'hodina'], true)) {
            $data = [];
            if ($podle === 'hodina') {
                foreach ($db->q("SELECT s.hour AS k, $sum FROM stats s$kde GROUP BY s.hour", $p)->fetchAll() as $r) $data[(string)(int)$r['k']] = $cisla($r);
                $klice = array_map(fn($h) => [(string)$h, sprintf('%d–%d h', $h, $h + 1)], range(0, 23));
            } else {
                foreach ($db->q("SELECT s.day AS k, $sum FROM stats s$kde GROUP BY s.day", $p)->fetchAll() as $r) {
                    $k = self::obdobi((string)$r['k'], $podle);
                    $data[$k] = isset($data[$k]) ? array_map(fn($a, $b) => $a + $b, $data[$k], $cisla($r)) : $cisla($r);
                    $data[$k] = array_combine(array_keys($nula), array_values($data[$k]));
                }
                $klice = [];
                $d = new \DateTimeImmutable($od);
                $konec = new \DateTimeImmutable($do);
                for ($i = 0; $d <= $konec && $i < 4000; $i++, $d = $d->modify('+1 day')) {
                    $k = self::obdobi($d->format('Y-m-d'), $podle);
                    $klice[$k] ??= [$k, self::popisObdobi($k, $podle)];
                }
                $klice = array_values($klice);
            }
            return array_map(fn($k) => ['klic' => $k[0], 'popis' => $k[1], 'odkaz' => null] + ($data[$k[0]] ?? $nula), $klice);
        }
        if ($podle === 'zarizeni') {
            $data = [];
            foreach ($db->q("SELECT s.device AS k, $sum FROM stats s$kde GROUP BY s.device ORDER BY impressions DESC, clicks DESC, s.device", $p)->fetchAll() as $r) $data[(string)$r['k']] = $cisla($r);
            $out = [];
            foreach ($data as $k => $c) $out[] = ['klic' => $k, 'popis' => self::popisZarizeni($k), 'odkaz' => null] + $c;
            return $out;
        }
        [$sloupec, $nazev, $stranka] = match ($podle) {
            'kampan' => ['s.campaign_id', 'SELECT name FROM campaigns WHERE id = x.k', 'kampan'],
            'banner' => ['s.banner_id', 'SELECT name FROM banners WHERE id = x.k', 'banner'],
            'zona' => ['s.zone_id', 'SELECT name FROM zones WHERE id = x.k', 'zona'],
            'web' => ['z.site_id', 'SELECT name FROM sites WHERE id = x.k', 'web'],
        };
        $rows = $db->q("SELECT x.*, ($nazev) AS nazev FROM (SELECT $sloupec AS k, $sum FROM stats s
                LEFT JOIN banners b ON b.id = s.banner_id LEFT JOIN zones z ON z.id = s.zone_id$kde GROUP BY $sloupec) x
            ORDER BY x.impressions DESC, x.clicks DESC, x.k", $p)->fetchAll();
        return array_map(function ($r) use ($cisla, $podle, $stranka) {
            $id = (int)$r['k'];
            $popis = $r['nazev'] ?? ($id === 0 ? ($podle === 'kampan' ? 'neznámá kampaň (starší data smazaných bannerů)' : 'bez zóny (vložení ze serveru)') : "#$id (smazáno)");
            return ['klic' => (string)$id, 'popis' => (string)$popis, 'odkaz' => $r['nazev'] !== null ? [$stranka, ['id' => $id]] : null] + $cisla($r);
        }, $rows);
    }

    /** Popis zařízení ve statistikách. */
    public static function popisZarizeni(string $z): string
    {
        return Cileni::ZARIZENI[$z] ?? 'neznámé (data před verzí 0.9)';
    }

    /** Klíč období pro den Y-m-d: den, týden (ISO, „2026-W47“) nebo měsíc („2026-11“). */
    public static function obdobi(string $den, string $podle): string
    {
        return match ($podle) {
            'tyden' => (new \DateTimeImmutable($den))->format('o-\\WW'),
            'mesic' => substr($den, 0, 7),
            default => $den,
        };
    }

    private const MESICE = [1 => 'leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];
    private const DNY = [1 => 'po', 'út', 'st', 'čt', 'pá', 'so', 'ne'];

    public static function popisObdobi(string $k, string $podle): string
    {
        if ($podle === 'mesic') return self::MESICE[(int)substr($k, 5, 2)] . ' ' . substr($k, 0, 4);
        if ($podle === 'tyden') {
            $d = (new \DateTimeImmutable())->setISODate((int)substr($k, 0, 4), (int)substr($k, 6));
            return (int)substr($k, 6) . '. týden (od ' . $d->format('j. n.') . ')';
        }
        $d = new \DateTimeImmutable($k);
        return self::DNY[(int)$d->format('N')] . ' ' . $d->format('j. n. Y');
    }

    /** Součet řádků přehledu. @param list<array<string, mixed>> $rows @return array{impressions:int, clicks:int, conversions:int, revenue:int} */
    public static function soucet(array $rows): array
    {
        $s = ['impressions' => 0, 'clicks' => 0, 'conversions' => 0, 'revenue' => 0];
        foreach ($rows as $r) foreach ($s as $k => $_) $s[$k] += (int)$r[$k];
        return $s;
    }

    /**
     * CSV pro Excel (UTF-8 s BOM, středník, desetinná čárka).
     * @param list<array<string, mixed>> $rows
     */
    public static function csv(array $rows, string $podle): string
    {
        $pole = fn(string $s) => preg_match('/[;"\r\n]/', $s) ? '"' . str_replace('"', '""', $s) . '"' : $s;
        $hlavni = self::PODLE[$podle] ?? 'Položka';
        $out = "\u{FEFF}" . implode(';', [$hlavni, 'ID', 'Zobrazení', 'Prokliky', 'CTR %', 'Konverze', 'Konverzní poměr %', 'Hodnota Kč']) . "\r\n";
        $pct = fn(int $a, int $b) => $b > 0 ? number_format($a / $b * 100, 2, ',', '') : '';
        foreach ([...$rows, ['klic' => '', 'popis' => 'Celkem'] + self::soucet($rows)] as $r) {
            // vzorec v buňce (=, +, -, @) Excel nespustí
            $popis = preg_match('/^[=+\-@\t]/', (string)$r['popis']) ? "'" . $r['popis'] : (string)$r['popis'];
            $out .= implode(';', [$pole($popis), $pole((string)$r['klic']), (int)$r['impressions'], (int)$r['clicks'], $pct((int)$r['clicks'], (int)$r['impressions']),
                (int)$r['conversions'], $pct((int)$r['conversions'], (int)$r['clicks']), number_format((int)$r['revenue'] / 100, 2, ',', '')]) . "\r\n";
        }
        return $out;
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function podminky(?string $od, ?string $do, ?int $kampan, ?int $banner, ?int $zona, ?int $web = null, ?string $zarizeni = null): array
    {
        $k = []; $p = [];
        if ($zarizeni !== null) { $k[] = 's.device = ?'; $p[] = $zarizeni; }
        if ($web !== null) { $k[] = 's.zone_id IN (SELECT id FROM zones WHERE site_id = ?)'; $p[] = $web; }
        if ($od !== null) { $k[] = 's.day >= ?'; $p[] = $od; }
        if ($do !== null) { $k[] = 's.day <= ?'; $p[] = $do; }
        if ($kampan !== null) { $k[] = 's.campaign_id = ?'; $p[] = $kampan; }
        if ($banner !== null) { $k[] = 's.banner_id = ?'; $p[] = $banner; }
        if ($zona !== null) { $k[] = 's.zone_id = ?'; $p[] = $zona; }
        return [$k ? ' WHERE ' . implode(' AND ', $k) : '', $p];
    }

    /** CTR v procentech (text). */
    public static function ctr(int $zobrazeni, int $prokliky): string
    {
        return $zobrazeni > 0 ? number_format($prokliky / $zobrazeni * 100, 2, ',', ' ') . ' %' : '–';
    }
}
