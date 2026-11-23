<?php
declare(strict_types=1);
namespace Reklamy;

/**
 * Výběr banneru pro zónu a počítání zobrazení a prokliků.
 *
 * Pravidla výběru:
 *  1. kandidáti = dvojice banner–kampaň: bannery zóny (přímo přiřazené ve všech svých kampaních, z přiřazených kampaní jen v nich, vhodného rozměru),
 *     aktivní banner v aktivní kampani, v termínu kampaně, s nevyčerpanými limity,
 *     bez bannerů, které už na stránce jsou, splňující cílení kampaně a frekvenci (cookie návštěvníka)
 *     a další filtry (pridejFiltr);
 *  2. druh kampaně: exkluzivní mají přednost před standardními, zbytkové přijdou na řadu jen bez jiných;
 *  3. v rámci druhu rozhoduje nejvyšší priorita;
 *  4. responzivní zóna: jen bannery, které se vejdou do šířky místa, a z nich ten nejširší rozměr (banner se nikdy nezmenšuje);
 *     místo v rozsahu prázdného rozměru (728x0) zůstane prázdné;
 *  5. mezi zbylými se losuje podle váhy kampaně a v ní podle váhy banneru.
 * Banner jen pro některá zařízení (banners.devices) a cílení kampaně na zařízení se řídí Kontext::zarizeni().
 */
final class Dorucovani
{
    private const PORADI = ['exclusive' => 0, 'standard' => 1, 'remnant' => 2];

    /** @var list<callable(array, Kontext): bool> další podmínky doručení (cílení, frekvence) */
    private array $filtry = [];
    /** @var callable(int): int */
    private $los;
    /** @var array<int, array{cap:int, hodin:int}> */
    private array $frekvence = [];
    private ?bool $zobrazeniProKonverze = null;

    /** @param ?callable(int): int $los náhodné číslo 0…n-1 (pro testy) */
    public function __construct(private App $app, ?callable $los = null)
    {
        $this->los = $los ?? static fn(int $n): int => random_int(0, $n - 1);
    }

    /** Přidá podmínku: function(array $banner, Kontext $k): bool – false = banner se nezobrazí. */
    public function pridejFiltr(callable $filtr): void { $this->filtry[] = $filtr; }

    /** Tajný klíč pro podpis odkazů doručování (vznikne při prvním použití). */
    public function tajemstvi(): string
    {
        $t = (string)$this->app->nastaveni()->dej('delivery_secret', '');
        if ($t === '') {
            $t = bin2hex(random_bytes(32));
            $this->app->nastaveni()->uloz('delivery_secret', $t);
        }
        return $t;
    }

    public function podpis(string $data): string { return substr(hash_hmac('sha256', $data, $this->tajemstvi()), 0, 16); }

    public function overPodpis(string $data, mixed $podpis): bool { return is_string($podpis) && hash_equals($this->podpis($data), $podpis); }

    /**
     * Bannery, které zóna právě teď může zobrazit (seřazené podle druhu, priority, kampaně).
     * @param list<string> $typy omezit na druhy bannerů (např. jen obrázky u vložení odkazem)
     * @return list<array<string, mixed>>
     */
    public function kandidati(array $zona, Kontext $k, array $typy = []): array
    {
        $ted = $k->ted();
        // banner může být ve více kampaních: každá dvojice banner–kampaň je samostatný kandidát (campaign_id = kampaň dvojice)
        $rows = $this->app->db->q("SELECT b.*, cb.campaign_id AS campaign_id, c.name AS campaign_name, c.kind AS campaign_kind, c.priority AS campaign_priority, c.weight AS campaign_weight,
                c.limit_impressions, c.limit_clicks, c.limit_conversions, c.daily_impressions, c.daily_clicks,
                c.targeting AS campaign_targeting, c.cap_impressions AS campaign_cap, c.cap_hours AS campaign_cap_hours
            FROM banners b JOIN campaign_banners cb ON cb.banner_id = b.id JOIN campaigns c ON c.id = cb.campaign_id
            WHERE b.status = 'active' AND c.status = 'active'
              AND (c.starts_at IS NULL OR c.starts_at <= ?) AND (c.ends_at IS NULL OR c.ends_at > ?)
              AND (b.id IN (SELECT banner_id FROM zone_banners WHERE zone_id = ?) OR cb.campaign_id IN (SELECT campaign_id FROM zone_campaigns WHERE zone_id = ?))
            ORDER BY b.id, cb.campaign_id", [$ted, $ted, $zona['id'], $zona['id']])->fetchAll();
        $zarizeni = $k->zarizeni();
        $rows = array_filter($rows, fn($b) => Zony::pasuje($zona, $b) && !isset($k->vynechat[(int)$b['id']]) && (!$typy || in_array($b['type'], $typy, true))
            && Bannery::proZarizeni($b, $zarizeni));
        $rows = $this->bezVycerpanych(array_values($rows), $ted);
        $cileni = [];
        $rows = array_values(array_filter($rows, function ($b) use ($k, &$cileni) {
            $kid = (int)$b['campaign_id'];
            $cileni[$kid] ??= Cileni::splnuje(json_decode((string)$b['campaign_targeting'], true) ?: null, $k);
            if (!$cileni[$kid]) return false;
            // frekvence jen s cookie návštěvníka (vložení ze serveru ji neumí)
            return !($b['campaign_cap'] && $k->cookies && $k->videl($kid, (int)($b['campaign_cap_hours'] ?: 24)) >= (int)$b['campaign_cap']);
        }));
        foreach ($this->filtry as $f) $rows = array_values(array_filter($rows, fn($b) => $f($b, $k)));
        usort($rows, fn($a, $b) => [self::PORADI[$a['campaign_kind']] ?? 9, -(int)$a['campaign_priority'], (int)$a['campaign_id'], (int)$a['id']]
            <=> [self::PORADI[$b['campaign_kind']] ?? 9, -(int)$b['campaign_priority'], (int)$b['campaign_id'], (int)$b['id']]);
        return $rows;
    }

    /** Vyřadí bannery kampaní, které vyčerpaly limit celkem nebo za dnešek. @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function bezVycerpanych(array $rows, int $ted): array
    {
        $sLimity = [];
        foreach ($rows as $b) {
            if ($b['limit_impressions'] || $b['limit_clicks'] || $b['limit_conversions'] || $b['daily_impressions'] || $b['daily_clicks']) $sLimity[(int)$b['campaign_id']] = true;
        }
        if (!$sLimity) return $rows;
        $vycerpane = [];
        $den = date('Y-m-d', $ted);
        foreach (array_keys($sLimity) as $kid) {
            $c = $this->pocty($kid, null);
            $d = $this->pocty($kid, $den);
            $b = current(array_filter($rows, fn($r) => (int)$r['campaign_id'] === $kid));
            if (($b['limit_impressions'] && $c['impressions'] >= $b['limit_impressions'])
                || ($b['limit_clicks'] && $c['clicks'] >= $b['limit_clicks'])
                || ($b['limit_conversions'] && $c['conversions'] >= $b['limit_conversions'])
                || ($b['daily_impressions'] && $d['impressions'] >= $b['daily_impressions'])
                || ($b['daily_clicks'] && $d['clicks'] >= $b['daily_clicks'])) $vycerpane[$kid] = true;
        }
        return array_values(array_filter($rows, fn($r) => !isset($vycerpane[(int)$r['campaign_id']])));
    }

    /** Součty kampaně celkem (den null) nebo za den. @return array{impressions:int, clicks:int, conversions:int} */
    public function pocty(int $kampan, ?string $den): array
    {
        $sql = 'SELECT COALESCE(SUM(s.impressions), 0) AS impressions, COALESCE(SUM(s.clicks), 0) AS clicks, COALESCE(SUM(s.conversions), 0) AS conversions
            FROM stats s WHERE s.campaign_id = ?';
        $r = $den === null ? $this->app->db->q($sql, [$kampan])->fetch() : $this->app->db->q("$sql AND s.day = ?", [$kampan, $den])->fetch();
        return array_map('intval', $r);
    }

    /**
     * Vybere banner pro zónu (nebo null). Vybraný banner se přidá do $k->vynechat a dostane klíč `zarizeni` (zařízení zobrazení pro odkazy).
     * U responzivní zóny rozhoduje šířka místa ($sirkaMista ze skriptu; 0 = neznámá → šířka okna, nebo odhad podle zařízení):
     * vejít se musí šířka banneru, v rámci druhu a priority vyhraje nejširší rozměr, mezi bannery tohoto rozměru se losuje.
     * @param list<string> $typy
     * @return ?array<string, mixed>
     */
    public function vyber(array $zona, Kontext $k, array $typy = [], int $sirkaMista = 0): ?array
    {
        $kandidati = $this->kandidati($zona, $k, $typy);
        if (Zony::jeResponzivni($zona)) {
            $limit = $sirkaMista > 0 ? $sirkaMista : $k->odhadSirky();
            // prázdný rozměr (728x0): od jeho šířky se užší bannery nepoužijí
            $hranice = Zony::prazdnaHranice($zona, $limit);
            $kandidati = array_values(array_filter($kandidati, fn($b) => ($limit === 0 || (int)$b['width'] <= $limit) && (int)$b['width'] > $hranice));
        }
        if (!$kandidati) return null;
        $prvni = $kandidati[0];
        $skupina = array_values(array_filter($kandidati, fn($b) => $b['campaign_kind'] === $prvni['campaign_kind'] && (int)$b['campaign_priority'] === (int)$prvni['campaign_priority']));
        if (Zony::jeResponzivni($zona)) {
            $nej = max(array_map(fn($b) => [(int)$b['width'], (int)$b['height']], $skupina));
            $skupina = array_values(array_filter($skupina, fn($b) => [(int)$b['width'], (int)$b['height']] === $nej));
        }
        $vybrany = $this->losuj($skupina);
        $vybrany['zarizeni'] = $k->zarizeni();
        $k->vynechat[(int)$vybrany['id']] = true;
        return $vybrany;
    }

    /**
     * Los podle vah: kampaň dostane podíl podle své váhy, ten se dělí mezi její bannery podle jejich vah.
     * Počítá se v celých číslech (součin vah ostatních kampaní), aby výsledek nezávisel na zaokrouhlení.
     * @param non-empty-list<array<string, mixed>> $skupina
     * @return array<string, mixed>
     */
    public function losuj(array $skupina): array
    {
        $kampane = [];
        foreach ($skupina as $b) $kampane[(int)$b['campaign_id']][] = $b;
        $soucty = array_map(fn($bs) => array_sum(array_map(fn($b) => max(1, (int)$b['weight']), $bs)), $kampane);
        $spolecny = 1;
        foreach ($soucty as $s) $spolecny = self::nsn($spolecny, $s);
        $vahy = [];
        foreach ($kampane as $kid => $bs) {
            foreach ($bs as $b) $vahy[] = [$b, max(1, (int)$b['campaign_weight']) * max(1, (int)$b['weight']) * intdiv($spolecny, $soucty[$kid])];
        }
        $celkem = array_sum(array_column($vahy, 1));
        $x = ($this->los)($celkem);
        foreach ($vahy as [$b, $v]) {
            if ($x < $v) return $b;
            $x -= $v;
        }
        return $vahy[count($vahy) - 1][0];
    }

    private static function nsn(int $a, int $b): int
    {
        $x = $a; $y = $b;
        while ($y !== 0) [$x, $y] = [$y, $x % $y];
        $v = intdiv($a, $x) * $b;
        return $v > 1_000_000_000 ? 1_000_000_000 : $v;   // velmi mnoho různých vah: přesnost stačí
    }

    // ---------- počítání ----------

    private function pricti(int $banner, int $zona, int $kampan, string $sloupec, int $cas, string $zarizeni = '', int $kolik = 1, int $hodnota = 0): void
    {
        $this->app->db->q("INSERT INTO stats (day, hour, banner_id, zone_id, campaign_id, device, $sloupec, revenue) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (day, hour, banner_id, zone_id, campaign_id, device) DO UPDATE SET $sloupec = $sloupec + excluded.$sloupec, revenue = revenue + excluded.revenue",
            [date('Y-m-d', $cas), (int)date('G', $cas), $banner, $zona, $kampan, $zarizeni, $kolik, $hodnota]);
    }

    /**
     * Kampaň, za kterou se banner počítá, když ji odkaz neuvádí (odkazy ze starších verzí): kampaň, pro kterou banner vznikl,
     * pokud v ní ještě je, jinak první z jeho kampaní; 0 = banner není v žádné kampani.
     */
    public function hlavniKampan(int $banner): int
    {
        $r = $this->app->db->q('SELECT cb.campaign_id FROM campaign_banners cb LEFT JOIN banners b ON b.id = cb.banner_id AND b.campaign_id = cb.campaign_id
            WHERE cb.banner_id = ? ORDER BY b.id IS NULL, cb.campaign_id LIMIT 1', [$banner])->fetchColumn();
        return $r === false ? 0 : (int)$r;
    }

    /** Je banner (ještě) v kampani? */
    public function vKampani(int $banner, int $kampan): bool
    {
        return (bool)$this->app->db->q('SELECT 1 FROM campaign_banners WHERE banner_id = ? AND campaign_id = ?', [$banner, $kampan])->fetchColumn();
    }

    /** Frekvence kampaně (z mezipaměti požadavku). @return array{cap:int, hodin:int} */
    private function frekvence(int $kampan): array
    {
        if (!isset($this->frekvence[$kampan])) {
            $r = $this->app->db->q('SELECT cap_impressions, cap_hours FROM campaigns WHERE id = ?', [$kampan])->fetch();
            $this->frekvence[$kampan] = ['cap' => (int)($r['cap_impressions'] ?? 0), 'hodin' => (int)($r['cap_hours'] ?? 0) ?: 24];
        }
        return $this->frekvence[$kampan];
    }

    /** Zapisovat zobrazení návštěvníků? Jen když nějaký aktivní tracker přiřazuje konverze i po zobrazení. */
    private function zobrazeniProKonverze(): bool
    {
        return $this->zobrazeniProKonverze ??= (bool)$this->app->db->q("SELECT 1 FROM trackers WHERE status = 'active' AND view_days > 0 LIMIT 1")->fetchColumn();
    }

    /** Zobrazení banneru v kampani: statistika, frekvence kampaně (cookie) a záznam pro přiřazení konverzí po zobrazení. */
    public function zobrazeni(int $banner, int $zona, Kontext $k, ?int $kampan = null): void
    {
        if (!$k->zapocitat()) return;
        $kampan ??= $this->hlavniKampan($banner);
        $this->pricti($banner, $zona, $kampan, 'impressions', $k->ted(), $k->zarizeni());
        if (!$k->cookies) return;
        $c = $this->frekvence($kampan);
        if ($c['cap']) $k->zapisFrekvenci($kampan, $c['hodin']);
        if ($this->zobrazeniProKonverze()) {
            $this->app->db->q('INSERT INTO views (visitor, banner_id, zone_id, campaign_id, device, at) VALUES (?, ?, ?, ?, ?, ?)', [$k->navstevnik(), $banner, $zona, $kampan, $k->zarizeni(), $k->ted()]);
        }
    }

    /** Proklik: statistika a záznam pro přiřazení konverzí. Vrací ID prokliku (null = nepočítá se). */
    public function proklik(int $banner, int $zona, Kontext $k, ?int $kampan = null): ?int
    {
        if (!$k->zapocitat()) return null;
        $kampan ??= $this->hlavniKampan($banner);
        $this->pricti($banner, $zona, $kampan, 'clicks', $k->ted(), $k->zarizeni());
        $this->app->db->q('INSERT INTO clicks (visitor, banner_id, zone_id, campaign_id, device, at) VALUES (?, ?, ?, ?, ?, ?)',
            [$k->cookies ? $k->navstevnik() : null, $banner, $zona, $kampan, $k->zarizeni(), $k->ted()]);
        return $this->app->db->posledniId();
    }

    /** Konverze do statistiky (hodnota v haléřích); zařízení prokliku nebo zobrazení, ke kterému se konverze přiřadila. */
    public function konverze(int $banner, int $zona, int $cas, int $hodnota = 0, ?int $kampan = null, string $zarizeni = ''): void
    {
        $this->pricti($banner, $zona, $kampan ?? $this->hlavniKampan($banner), 'conversions', $cas, $zarizeni, 1, $hodnota);
    }

    // ---------- vložení odkazem (obrázek a proklik se stejným n) ----------

    public function zapamatuj(string $n, int $zona, int $banner, int $cas, int $kampan = 0): void
    {
        $this->app->db->q('INSERT INTO view_map (n, zone_id, banner_id, campaign_id, at) VALUES (?, ?, ?, ?, ?)
            ON CONFLICT (n, zone_id) DO UPDATE SET banner_id = excluded.banner_id, campaign_id = excluded.campaign_id, at = excluded.at', [$n, $zona, $banner, $kampan, $cas]);
    }

    /** Banner a kampaň zapamatované pod n, nebo null. @return ?array{0:int, 1:int} */
    public function zapamatovany(string $n, int $zona): ?array
    {
        $r = $this->app->db->q('SELECT banner_id, campaign_id FROM view_map WHERE n = ? AND zone_id = ?', [$n, $zona])->fetch();
        return $r ? [(int)$r['banner_id'], (int)$r['campaign_id']] : null;
    }

    /** Smaže staré záznamy vložení odkazem (po 30 dnech) a prokliky a zobrazení návštěvníků starší než nejdelší okno trackerů. */
    public function uklid(int $dni = 30): int
    {
        $db = $this->app->db;
        $n = $db->q('DELETE FROM view_map WHERE at < ?', [time() - $dni * 86400])->rowCount();
        $okna = $db->q('SELECT COALESCE(MAX(click_days), 30) AS c, COALESCE(MAX(view_days), 0) AS v FROM trackers')->fetch();
        $n += $db->q('DELETE FROM clicks WHERE at < ?', [time() - max(30, (int)$okna['c']) * 86400])->rowCount();
        $n += $db->q('DELETE FROM views WHERE at < ?', [time() - max(1, (int)$okna['v']) * 86400])->rowCount();
        return $n;
    }
}
