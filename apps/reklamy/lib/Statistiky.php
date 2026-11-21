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

    /** @return array{0: string, 1: list<mixed>} */
    private function podminky(?string $od, ?string $do, ?int $kampan, ?int $banner, ?int $zona): array
    {
        $k = []; $p = [];
        if ($od !== null) { $k[] = 's.day >= ?'; $p[] = $od; }
        if ($do !== null) { $k[] = 's.day <= ?'; $p[] = $do; }
        if ($kampan !== null) { $k[] = 's.banner_id IN (SELECT id FROM banners WHERE campaign_id = ?)'; $p[] = $kampan; }
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
