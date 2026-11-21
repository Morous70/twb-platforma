<?php
declare(strict_types=1);
namespace Reklamy;

/**
 * Zóny = místa pro reklamu na webu. Do zóny se přiřazují celé kampaně (platí i pro bannery přidané později)
 * nebo jednotlivé bannery. Zóna s pevným rozměrem bere jen bannery stejného rozměru.
 */
final class Zony
{
    public const DRUHY = ['banner' => 'Banner na stránce', 'popup' => 'Vyskakovací okno (popup)'];

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

    public static function rozmer(array $z): string
    {
        return $z['width'] !== null ? "{$z['width']}×{$z['height']}" : 'libovolný';
    }

    /** Vejde se banner do zóny? */
    public static function pasuje(array $zona, array $banner): bool
    {
        return $zona['width'] === null || ((int)$zona['width'] === (int)$banner['width'] && (int)$zona['height'] === (int)$banner['height']);
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
        if (($d['width'] === null) !== ($d['height'] === null)) throw new Chyba('Vyplňte šířku i výšku zóny, nebo obojí nechte prázdné (libovolný rozměr).');
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
            if ($d['width'] !== null) {
                $db->q('DELETE FROM zone_banners WHERE zone_id = ? AND banner_id IN (SELECT id FROM banners WHERE width <> ? OR height <> ?)', [$id, $d['width'], $d['height']]);
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
        $rows = $this->app->db->q('SELECT DISTINCT b.*, c.name AS campaign_name FROM banners b JOIN campaigns c ON c.id = b.campaign_id
            WHERE b.id IN (SELECT banner_id FROM zone_banners WHERE zone_id = ?)
               OR b.campaign_id IN (SELECT campaign_id FROM zone_campaigns WHERE zone_id = ?)
            ORDER BY c.name, b.name', [$id, $id])->fetchAll();
        return array_values(array_filter($rows, fn($b) => self::pasuje($z, $b)));
    }

    /** Zóny, ve kterých se kampaň nebo banner může zobrazit. @return list<array<string, mixed>> */
    public function zonyBanneru(array $banner): array
    {
        $rows = $this->app->db->q('SELECT z.*, s.name AS site_name FROM zones z JOIN sites s ON s.id = z.site_id
            WHERE z.id IN (SELECT zone_id FROM zone_banners WHERE banner_id = ?) OR z.id IN (SELECT zone_id FROM zone_campaigns WHERE campaign_id = ?)
            ORDER BY s.name, z.name', [$banner['id'], $banner['campaign_id']])->fetchAll();
        return array_values(array_filter($rows, fn($z) => self::pasuje($z, $banner)));
    }
}
