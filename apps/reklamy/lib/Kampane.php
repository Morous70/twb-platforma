<?php
declare(strict_types=1);
namespace Reklamy;

use TWB\Tagy\Tagy;

/** Kampaně: termín, druh a priorita, váha, limity zobrazení a prokliků, cílení a frekvence. */
final class Kampane
{
    public const STAVY = ['active' => 'Aktivní', 'paused' => 'Pozastavená', 'archived' => 'Archiv'];
    public const DRUHY = [
        'exclusive' => 'Exkluzivní – má přednost před ostatními',
        'standard' => 'Standardní',
        'remnant' => 'Zbytková – jen když není nic jiného',
    ];
    private ?Tagy $tagy = null;

    public function __construct(private App $app) {}

    public function tagy(): Tagy { return $this->tagy ??= new Tagy($this->app->db, 'campaigns', 'id', 'name', 'tags', 'Kampaň'); }

    /** @return list<array<string, mixed>> */
    public function seznam(string $stav = '', string $tag = ''): array
    {
        $kde = []; $p = [];
        if ($stav !== '') { $kde[] = 'c.status = ?'; $p[] = $stav; }
        else $kde[] = "c.status <> 'archived'";
        $rows = $this->app->db->q('SELECT c.*, (SELECT COUNT(*) FROM campaign_banners cb WHERE cb.campaign_id = c.id) AS banners,
            (SELECT COUNT(*) FROM zone_campaigns z WHERE z.campaign_id = c.id) AS zones
            FROM campaigns c WHERE ' . implode(' AND ', $kde) . " ORDER BY CASE c.kind WHEN 'exclusive' THEN 0 WHEN 'standard' THEN 1 ELSE 2 END, c.priority DESC, c.name", $p)->fetchAll();
        if ($tag !== '') $rows = array_values(array_filter($rows, fn($r) => in_array(Tagy::klic($tag), array_map([Tagy::class, 'klic'], $this->tagy()->tagyPolozky($r)), true)));
        return $rows;
    }

    /** @return array<string, mixed> */
    public function radek(int $id): array
    {
        return $this->app->db->q('SELECT * FROM campaigns WHERE id = ?', [$id])->fetch() ?: throw new Chyba('Kampaň nenalezena.');
    }

    /** Termín kampaně slovy (pro přehled). */
    public static function termin(array $k): string
    {
        $od = $k['starts_at'] ? date('j. n. Y H:i', (int)$k['starts_at']) : null;
        $do = $k['ends_at'] ? date('j. n. Y H:i', (int)$k['ends_at']) : null;
        if (!$od && !$do) return 'bez omezení';
        if ($od && $do) return "$od – $do";
        return $od ? "od $od" : "do $do";
    }

    /** Běží kampaň právě teď (stav a termín, bez limitů)? */
    public static function bezi(array $k, ?int $ted = null): bool
    {
        $ted ??= time();
        return $k['status'] === 'active' && (!$k['starts_at'] || (int)$k['starts_at'] <= $ted) && (!$k['ends_at'] || (int)$k['ends_at'] > $ted);
    }

    /** Uloží kampaň (nová, když $id je null). Vrací ID. @param array<string, mixed> $v */
    public function uloz(array $v, ?int $id = null): int
    {
        $d = [
            'name' => Vstup::text($v, 'name', 'Název'),
            'status' => Vstup::volba($v, 'status', 'Stav', self::STAVY, 'active'),
            'kind' => Vstup::volba($v, 'kind', 'Druh', self::DRUHY, 'standard'),
            'priority' => Vstup::cislo($v, 'priority', 'Priorita', 1, 10) ?? 5,
            'weight' => Vstup::cislo($v, 'weight', 'Váha', 1, 1000) ?? 1,
            'starts_at' => Vstup::cas($v, 'starts_at', 'Začátek'),
            'ends_at' => Vstup::cas($v, 'ends_at', 'Konec'),
            'limit_impressions' => Vstup::cislo($v, 'limit_impressions', 'Limit zobrazení celkem', 1, PHP_INT_MAX),
            'limit_clicks' => Vstup::cislo($v, 'limit_clicks', 'Limit prokliků celkem', 1, PHP_INT_MAX),
            'limit_conversions' => Vstup::cislo($v, 'limit_conversions', 'Limit konverzí celkem', 1, PHP_INT_MAX),
            'daily_impressions' => Vstup::cislo($v, 'daily_impressions', 'Denní limit zobrazení', 1, PHP_INT_MAX),
            'daily_clicks' => Vstup::cislo($v, 'daily_clicks', 'Denní limit prokliků', 1, PHP_INT_MAX),
            'note' => Vstup::text($v, 'note', 'Poznámka', 5000, false),
            'cap_impressions' => Vstup::cislo($v, 'cap_impressions', 'Frekvence – počet zobrazení', 1, 1000),
            'cap_hours' => Vstup::cislo($v, 'cap_hours', 'Frekvence – za hodin', 1, 24 * 30),
        ];
        $cil = Cileni::zFormulare($v);
        $d['targeting'] = $cil ? json_encode($cil, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        if ($d['cap_impressions'] === null) $d['cap_hours'] = null;
        else $d['cap_hours'] ??= 24;
        if ($d['starts_at'] && $d['ends_at'] && $d['ends_at'] <= $d['starts_at']) throw new Chyba('Konec kampaně musí být později než začátek.');
        try {
            $tagy = $this->tagy()->vycistiSeznam((string)($v['tags'] ?? ''));
        } catch (\InvalidArgumentException $e) { throw new Chyba($e->getMessage()); }
        $d['tags'] = $tagy ? json_encode($tagy, JSON_UNESCAPED_UNICODE) : null;
        $d['updated_at'] = time();
        $db = $this->app->db;
        if ($id === null) {
            $d['created_at'] = $d['updated_at'];
            $db->q('INSERT INTO campaigns (' . implode(', ', array_keys($d)) . ') VALUES (' . implode(', ', array_fill(0, count($d), '?')) . ')', array_values($d));
            $id = $db->posledniId();
            $this->app->log('info', "Nová kampaň: {$d['name']}");
        } else {
            $this->radek($id);
            $db->q('UPDATE campaigns SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($d))) . ' WHERE id = ?', [...array_values($d), $id]);
        }
        return $id;
    }

    public function nastavStav(int $id, string $stav): void
    {
        if (!isset(self::STAVY[$stav])) throw new Chyba('Neplatný stav kampaně.');
        $k = $this->radek($id);
        $this->app->db->q('UPDATE campaigns SET status = ?, updated_at = ? WHERE id = ?', [$stav, time(), $id]);
        $this->app->log('info', "Kampaň {$k['name']}: " . mb_strtolower(self::STAVY[$stav]));
    }

    /** Kopie kampaně se stejnými bannery (bannery se nekopírují, jen přiřadí i nové kampani; bez přiřazení do zón), pozastavená. */
    public function duplikuj(int $id): int
    {
        $k = $this->radek($id);
        return $this->app->db->transakce(function () use ($k) {
            $db = $this->app->db;
            $novy = $k;
            unset($novy['id']);
            $novy['name'] = mb_substr($k['name'] . ' (kopie)', 0, 200);
            $novy['status'] = 'paused';
            $novy['created_at'] = $novy['updated_at'] = time();
            $db->q('INSERT INTO campaigns (' . implode(', ', array_keys($novy)) . ') VALUES (' . implode(', ', array_fill(0, count($novy), '?')) . ')', array_values($novy));
            $nid = $db->posledniId();
            $db->q('INSERT INTO campaign_banners (campaign_id, banner_id) SELECT ?, banner_id FROM campaign_banners WHERE campaign_id = ?', [$nid, $k['id']]);
            $this->app->log('info', "Kampaň zkopírována: {$k['name']}");
            return $nid;
        });
    }

    /** Bannery, které jsou jen v této kampani (při smazání kampaně se smažou s ní). @return list<int> */
    public function jenVlastniBannery(int $id): array
    {
        return array_map('intval', $this->app->db->q('SELECT cb.banner_id FROM campaign_banners cb WHERE cb.campaign_id = ?
            AND NOT EXISTS (SELECT 1 FROM campaign_banners x WHERE x.banner_id = cb.banner_id AND x.campaign_id <> cb.campaign_id) ORDER BY cb.banner_id', [$id])->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Smaže kampaň a bannery, které nejsou v žádné jiné kampani. Bannery sdílené s jinými kampaněmi zůstanou. */
    public function smaz(int $id): void
    {
        $k = $this->radek($id);
        foreach ($this->jenVlastniBannery($id) as $b) $this->app->bannery()->smaz($b);
        $db = $this->app->db;
        $db->transakce(function () use ($db, $id) {
            $db->q('UPDATE banners SET campaign_id = NULL WHERE campaign_id = ?', [$id]);
            $db->q('DELETE FROM campaigns WHERE id = ?', [$id]);
        });
        $this->app->log('info', "Kampaň smazána: {$k['name']}");
    }
}
