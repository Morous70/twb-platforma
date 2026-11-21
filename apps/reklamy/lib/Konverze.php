<?php
declare(strict_types=1);
namespace Reklamy;

/**
 * Konverze: trackery (kód na webu inzerenta), záznam konverze a její přiřazení k banneru.
 *
 * Přiřazení (od nejspolehlivějšího):
 *  1. token twbr z cílové adresy prokliku (klik.php ho přidá, d/t.js ho na webu uloží) – funguje i bez cookies třetích stran;
 *  2. cookie návštěvníka twbr_v: poslední proklik v okně trackeru (click_days);
 *  3. totéž pro zobrazení, když má tracker okno po zobrazení (view_days > 0).
 * Jen prokliky a zobrazení kampaní, které tracker sleduje (žádné vybrané = všechny). Nepřiřazená konverze se uloží také (bez banneru).
 */
final class Konverze
{
    public const DRUHY = ['sale' => 'Nákup', 'lead' => 'Poptávka / kontakt', 'signup' => 'Registrace', 'other' => 'Jiné'];
    public const STAVY = ['active' => 'Aktivní', 'paused' => 'Pozastavený'];
    public const CESTY = ['click' => 'po prokliku', 'view' => 'po zobrazení', '' => 'nepřiřazená'];
    public const PARAMETR = 'twbr';

    public function __construct(private App $app) {}

    // ---------- trackery ----------

    /** @return list<array<string, mixed>> */
    public function seznam(): array
    {
        return $this->app->db->q("SELECT t.*, (SELECT COUNT(*) FROM conversions c WHERE c.tracker_id = t.id) AS pocet,
                (SELECT COALESCE(SUM(value), 0) FROM conversions c WHERE c.tracker_id = t.id) AS hodnota,
                (SELECT MAX(at) FROM conversions c WHERE c.tracker_id = t.id) AS posledni
            FROM trackers t ORDER BY t.status, t.name")->fetchAll();
    }

    /** @return array<string, mixed> */
    public function radek(int $id): array
    {
        return $this->app->db->q('SELECT * FROM trackers WHERE id = ?', [$id])->fetch() ?: throw new Chyba('Tracker nenalezen.');
    }

    /** Kampaně, které tracker sleduje (prázdné = všechny). @return list<int> */
    public function kampane(int $id): array
    {
        return array_map('intval', $this->app->db->q('SELECT campaign_id FROM tracker_campaigns WHERE tracker_id = ? ORDER BY campaign_id', [$id])->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Uloží tracker (nový, když $id je null). Vrací ID. @param array<string, mixed> $v */
    public function uloz(array $v, ?int $id = null): int
    {
        $d = [
            'name' => Vstup::text($v, 'name', 'Název'),
            'status' => Vstup::volba($v, 'status', 'Stav', self::STAVY, 'active'),
            'kind' => Vstup::volba($v, 'kind', 'Druh', self::DRUHY, 'sale'),
            'value_default' => self::castka($v['value_default'] ?? '', 'Výchozí hodnota'),
            'click_days' => Vstup::cislo($v, 'click_days', 'Okno po prokliku', 1, 365) ?? 30,
            'view_days' => Vstup::cislo($v, 'view_days', 'Okno po zobrazení', 0, 90) ?? 0,
            'once_per_click' => empty($v['once_per_click']) ? 0 : 1,
            'note' => Vstup::text($v, 'note', 'Poznámka', 5000, false),
            'updated_at' => time(),
        ];
        $kampane = array_values(array_unique(array_filter(array_map('intval', (array)($v['kampane'] ?? [])))));
        foreach ($kampane as $k) $this->app->kampane()->radek($k);
        $db = $this->app->db;
        return $db->transakce(function () use ($db, $d, $id, $kampane) {
            if ($id === null) {
                $d['created_at'] = $d['updated_at'];
                $db->q('INSERT INTO trackers (' . implode(', ', array_keys($d)) . ') VALUES (' . implode(', ', array_fill(0, count($d), '?')) . ')', array_values($d));
                $id = $db->posledniId();
                $this->app->log('info', "Nový tracker konverzí: {$d['name']}");
            } else {
                $this->radek($id);
                $db->q('UPDATE trackers SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($d))) . ' WHERE id = ?', [...array_values($d), $id]);
            }
            $db->q('DELETE FROM tracker_campaigns WHERE tracker_id = ?', [$id]);
            foreach ($kampane as $k) $db->q('INSERT INTO tracker_campaigns (tracker_id, campaign_id) VALUES (?, ?)', [$id, $k]);
            return $id;
        });
    }

    /** Smaže tracker i jeho konverze; ze statistik banerů se konverze neodečítají. */
    public function smaz(int $id): void
    {
        $t = $this->radek($id);
        $this->app->db->q('DELETE FROM trackers WHERE id = ?', [$id]);
        $this->app->db->q('DELETE FROM conversions WHERE tracker_id = ?', [$id]);
        $this->app->log('info', "Tracker smazán: {$t['name']}");
    }

    // ---------- částky, token prokliku ----------

    /** Částka v Kč z textu („1 234,50“, „1234.5“) na haléře; prázdné = null. */
    public static function castka(mixed $s, string $popis = 'Hodnota'): ?int
    {
        if (!is_scalar($s)) return null;
        $s = str_replace([' ', "\u{00A0}", 'Kč', 'kč', 'CZK'], '', trim((string)$s));
        if ($s === '') return null;
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $s)) $s = str_replace('.', '', $s);   // 1.234,50
        $s = str_replace(',', '.', $s);
        if (!preg_match('/^\d{1,10}(\.\d{1,4})?$/', $s)) throw new Chyba("Pole „{$popis}“ musí být částka v Kč (např. 1234,50).");
        return (int)round((float)$s * 100);
    }

    /** Haléře jako text v Kč. */
    public static function kc(?int $h): string
    {
        if ($h === null) return '';
        return number_format($h / 100, $h % 100 ? 2 : 0, ',', ' ') . ' Kč';
    }

    /** Token prokliku pro cílovou adresu: <id>.<podpis>. */
    public function token(int $klik): string { return $klik . '.' . substr($this->app->dorucovani()->podpis("konv|$klik"), 0, 10); }

    /** ID prokliku z tokenu, nebo null. */
    public function overToken(mixed $t): ?int
    {
        if (!is_string($t) || !preg_match('/^(\d{1,12})\.([a-f0-9]{10})$/', $t, $m)) return null;
        return hash_equals(substr($this->app->dorucovani()->podpis("konv|{$m[1]}"), 0, 10), $m[2]) ? (int)$m[1] : null;
    }

    /** Přidá parametr twbr k cílové adrese (před #, existující twbr nahradí). */
    public static function pridejParametr(string $url, string $token): string
    {
        $hash = '';
        if (($p = strpos($url, '#')) !== false) { $hash = substr($url, $p); $url = substr($url, 0, $p); }
        $url = (string)preg_replace('/([?&])' . self::PARAMETR . '=[^&]*(&|$)/', '$1', $url);
        $url = rtrim($url, '?&');
        return $url . (str_contains($url, '?') ? '&' : '?') . self::PARAMETR . '=' . rawurlencode($token) . $hash;
    }

    // ---------- záznam konverze ----------

    /**
     * Zaznamená konverzi. $hodnota v haléřích (null = výchozí hodnota trackeru), $ref = číslo objednávky (stejné se podruhé nezapočítá).
     * @return array{stav: string, via: string, banner: ?int, id: ?int} stav: ok | duplicita | neaktivni
     */
    public function zaznamenej(int $tracker, ?string $token, Kontext $k, ?int $hodnota = null, ?string $ref = null): array
    {
        $t = $this->app->db->q('SELECT * FROM trackers WHERE id = ?', [$tracker])->fetch();
        if (!$t || $t['status'] !== 'active') return ['stav' => 'neaktivni', 'via' => '', 'banner' => null, 'id' => null];
        $db = $this->app->db;
        $ted = $k->ted();
        $ref = $ref !== null ? mb_substr(trim($ref), 0, 100) : null;
        if ($ref === '') $ref = null;
        if ($ref !== null && $db->q('SELECT 1 FROM conversions WHERE tracker_id = ? AND ref = ?', [$tracker, $ref])->fetchColumn()) {
            return ['stav' => 'duplicita', 'via' => '', 'banner' => null, 'id' => null];
        }
        $kampane = $this->kampane($tracker);
        $kde = $kampane ? ' AND campaign_id IN (' . implode(',', $kampane) . ')' : '';
        $odKlik = $ted - (int)$t['click_days'] * 86400;
        $zdroj = null; $via = '';
        $klik = $this->overToken($token);
        if ($klik !== null) $zdroj = $db->q("SELECT * FROM clicks WHERE id = ? AND at >= ? AND at <= ?$kde", [$klik, $odKlik, $ted])->fetch() ?: null;
        if (!$zdroj && $k->navstevnik !== '') {
            $zdroj = $db->q("SELECT * FROM clicks WHERE visitor = ? AND at >= ? AND at <= ?$kde ORDER BY at DESC, id DESC LIMIT 1", [$k->navstevnik, $odKlik, $ted])->fetch() ?: null;
        }
        if ($zdroj) {
            $via = 'click';
        } elseif ((int)$t['view_days'] > 0 && $k->navstevnik !== '') {
            $zdroj = $db->q("SELECT * FROM views WHERE visitor = ? AND at >= ? AND at <= ?$kde ORDER BY at DESC LIMIT 1",
                [$k->navstevnik, $ted - (int)$t['view_days'] * 86400, $ted])->fetch() ?: null;
            if ($zdroj) $via = 'view';
        }
        if ($zdroj && $t['once_per_click']) {
            $uz = $via === 'click'
                ? $db->q('SELECT 1 FROM conversions WHERE tracker_id = ? AND click_id = ?', [$tracker, $zdroj['id']])->fetchColumn()
                : $db->q("SELECT 1 FROM conversions WHERE tracker_id = ? AND via = 'view' AND banner_id = ? AND at >= ?", [$tracker, $zdroj['banner_id'], $zdroj['at']])->fetchColumn();
            if ($uz) return ['stav' => 'duplicita', 'via' => $via, 'banner' => (int)$zdroj['banner_id'], 'id' => null];
        }
        $hodnota ??= $t['value_default'] !== null ? (int)$t['value_default'] : 0;
        try {
            $db->q('INSERT INTO conversions (tracker_id, campaign_id, banner_id, zone_id, click_id, via, value, ref, at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $tracker, $zdroj['campaign_id'] ?? null, $zdroj['banner_id'] ?? null, $zdroj['zone_id'] ?? null, $via === 'click' ? $zdroj['id'] : null,
                $via, $hodnota, $ref, $ted,
            ]);
        } catch (\PDOException) {
            return ['stav' => 'duplicita', 'via' => '', 'banner' => null, 'id' => null];   // souběžné odeslání stejné objednávky
        }
        $id = $db->posledniId();
        if ($zdroj) $this->app->dorucovani()->konverze((int)$zdroj['banner_id'], (int)$zdroj['zone_id'], $ted, $hodnota, (int)$zdroj['campaign_id']);
        return ['stav' => 'ok', 'via' => $via, 'banner' => $zdroj ? (int)$zdroj['banner_id'] : null, 'id' => $id];
    }

    /** Poslední konverze (s názvy). @return list<array<string, mixed>> */
    public function posledni(?int $tracker = null, int $limit = 50): array
    {
        $kde = $tracker !== null ? 'WHERE c.tracker_id = ' . $tracker : '';
        return $this->app->db->q("SELECT c.*, t.name AS tracker_name, b.name AS banner_name, k.name AS campaign_name
            FROM conversions c JOIN trackers t ON t.id = c.tracker_id LEFT JOIN banners b ON b.id = c.banner_id LEFT JOIN campaigns k ON k.id = c.campaign_id
            $kde ORDER BY c.at DESC, c.id DESC LIMIT " . max(1, min(500, $limit)))->fetchAll();
    }

    // ---------- kódy pro web inzerenta ----------

    /** Kódy trackeru: [nadpis, popis, kód]. @return list<array{0:string,1:string,2:string}> */
    public function kody(array $t): array
    {
        $id = (int)$t['id'];
        $js = $this->app->url('d/t.js');
        $pixel = $this->app->url('d/konverze.php');
        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return [
            ['Na všech stránkách webu', 'Zapamatuje si proklik z reklamy (parametr twbr v adrese), aby šla konverze přiřadit i v prohlížečích, které blokují cookies třetích stran. Stačí jednou pro všechny trackery.',
                '<script async src="' . $h($js) . '"></script>'],
            ['Po dokončení objednávky (JavaScript, doporučeno)', 'Hodnotu a číslo objednávky doplňte z e-shopu (hodnota v Kč, desetinná tečka nebo čárka). Číslo objednávky brání dvojímu započítání. Bez hodnoty se použije výchozí hodnota trackeru.',
                "<script>\nwindow.twbr = window.twbr || [];\ntwbr.push({ tracker: $id, hodnota: '1234.50', id: 'CISLO-OBJEDNAVKY' });\n</script>\n<script async src=\"" . $h($js) . '"></script>'],
            ['Obrázek (bez JavaScriptu)', 'Konverzi přiřadí jen podle cookie návštěvníka (bez parametru twbr).',
                '<img src="' . $h($pixel . "?t=$id&hodnota=1234.50&id=CISLO-OBJEDNAVKY") . '" width="1" height="1" alt="" style="position:absolute;visibility:hidden">'],
            ['Ze serveru (postback)', 'Server e-shopu si při příchodu návštěvníka uloží parametr twbr z adresy a po objednávce zavolá tuto adresu. Odpověď je JSON: stav ok | duplicita | neaktivni a způsob přiřazení.',
                'GET ' . $pixel . "?t=$id&twbr={twbr}&hodnota=1234.50&id=CISLO-OBJEDNAVKY&format=json"],
        ];
    }
}
