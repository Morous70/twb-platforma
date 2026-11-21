<?php
declare(strict_types=1);
namespace Reklamy;

/** Bannery kampaní: obrázek z galerie, obrázek z jiné adresy, HTML, HTML5 (ZIP), slide z editoru. */
final class Bannery
{
    public const TYPY = [
        'image' => 'Obrázek z galerie',
        'external' => 'Obrázek z jiné adresy',
        'html' => 'HTML kód',
        'html5' => 'HTML5 (ZIP)',
        'slide' => 'Slide z editoru',
    ];
    /** Druhy, které se vydávají jako obrázek (i vložením odkazem do e-mailu). */
    public const OBRAZKOVE = ['image', 'external', 'slide'];
    public const STAVY = ['active' => 'Aktivní', 'paused' => 'Pozastavený'];
    public const CILE = ['_blank' => 'Nové okno', '_top' => 'Stejné okno (celá stránka)', '_self' => 'Uvnitř rámce'];
    public const MAX_ROZMER = 4096;
    public const MAX_HTML = 512 * 1024;
    /** Běžné rozměry reklamních ploch (IAB a české weby). */
    public const ROZMERY = [
        '300x250' => 'Medium rectangle', '336x280' => 'Large rectangle', '728x90' => 'Leaderboard', '970x90' => 'Large leaderboard',
        '970x250' => 'Billboard', '970x310' => 'Branding (Seznam)', '300x600' => 'Half page', '160x600' => 'Wide skyscraper',
        '120x600' => 'Skyscraper', '480x300' => 'Square (Seznam)', '320x50' => 'Mobilní banner', '320x100' => 'Velký mobilní banner',
        '468x60' => 'Full banner', '250x250' => 'Čtverec', '200x200' => 'Malý čtverec', '1200x628' => 'Sdílení (Facebook)',
    ];

    public function __construct(private App $app) {}

    /** @return list<array<string, mixed>> */
    public function seznam(?int $kampan = null): array
    {
        $sql = 'SELECT b.*, c.name AS campaign_name, c.status AS campaign_status FROM banners b JOIN campaigns c ON c.id = b.campaign_id';
        if ($kampan !== null) return $this->app->db->q("$sql WHERE b.campaign_id = ? ORDER BY b.name, b.id", [$kampan])->fetchAll();
        return $this->app->db->q("$sql WHERE c.status <> 'archived' ORDER BY c.name, b.name, b.id")->fetchAll();
    }

    /** @return array<string, mixed> */
    public function radek(int $id): array
    {
        return $this->app->db->q('SELECT b.*, c.name AS campaign_name FROM banners b JOIN campaigns c ON c.id = b.campaign_id WHERE b.id = ?', [$id])->fetch()
            ?: throw new Chyba('Banner nenalezen.');
    }

    /** Uloží banner (nový, když $id je null). Vrací ID. @param array<string, mixed> $v */
    public function uloz(array $v, ?int $id = null): int
    {
        $stary = $id !== null ? $this->radek($id) : null;
        $kampan = $this->app->kampane()->radek((int)($v['campaign_id'] ?? $stary['campaign_id'] ?? 0));
        $typ = $stary['type'] ?? Vstup::volba($v, 'type', 'Druh banneru', self::TYPY);
        $d = [
            'campaign_id' => (int)$kampan['id'],
            'name' => Vstup::text($v, 'name', 'Název'),
            'status' => Vstup::volba($v, 'status', 'Stav', self::STAVY, 'active'),
            'type' => $typ,
            'width' => Vstup::cislo($v, 'width', 'Šířka', 1, self::MAX_ROZMER),
            'height' => Vstup::cislo($v, 'height', 'Výška', 1, self::MAX_ROZMER),
            'weight' => Vstup::cislo($v, 'weight', 'Váha', 1, 1000) ?? 1,
            'media_id' => null, 'image_url' => null, 'html' => null, 'content' => null,
            'target_url' => Vstup::adresa($v, 'target_url', 'Cílová adresa'),
            'target' => Vstup::volba($v, 'target', 'Otevřít', self::CILE, '_blank'),
            'alt' => Vstup::text($v, 'alt', 'Popis obrázku (alt)', 255, false),
            'note' => Vstup::text($v, 'note', 'Poznámka', 5000, false),
        ];
        switch ($typ) {
            case 'image':
                $mid = (int)($v['media_id'] ?? 0);
                if ($mid <= 0) throw new Chyba('Vyberte obrázek z galerie.');
                try { $m = $this->app->galerie()->radek($mid); } catch (\RuntimeException) { throw new Chyba('Vybraný obrázek v galerii není.'); }
                if ($m['druh'] !== 'image') throw new Chyba('Banner může použít jen obrázek, ne video ani PDF.');
                $d['media_id'] = $mid;
                $d['width'] ??= $m['sirka'] !== null ? (int)$m['sirka'] : null;
                $d['height'] ??= $m['vyska'] !== null ? (int)$m['vyska'] : null;
                break;
            case 'external':
                $d['image_url'] = Vstup::adresa($v, 'image_url', 'Adresa obrázku', true);
                break;
            case 'html':
                $html = (string)($v['html'] ?? '');
                if (trim($html) === '') throw new Chyba('Vložte HTML kód banneru.');
                if (strlen($html) > self::MAX_HTML) throw new Chyba('HTML kód banneru může mít nejvýš 512 kB.');
                if (!mb_check_encoding($html, 'UTF-8')) throw new Chyba('HTML kód banneru musí být v kódování UTF-8.');
                $d['html'] = $html;
                break;
            case 'html5':
                // nový balíček: ['tmp' => cesta, 'name' => původní název]; jinak zůstane uložený
                $f = $v['balicek'] ?? null;
                if (is_array($f) && !empty($f['tmp'])) {
                    $info = $this->app->html5()->nahraj((string)$f['tmp'], (string)($f['name'] ?? 'banner.zip'));
                    $d['width'] ??= $info['sirka'];
                    $d['height'] ??= $info['vyska'];
                } else {
                    $info = json_decode((string)($stary['content'] ?? ''), true);
                    if (!is_array($info) || empty($info['zip'])) throw new Chyba('Nahrajte balíček HTML5 (ZIP s index.html).');
                }
                $d['content'] = json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                break;
            case 'slide':
                $design = array_key_exists('design', $v) ? $this->app->navrh()->vycisti($v['design']) : json_decode((string)($stary['content'] ?? ''), true);
                if (!is_array($design) || empty($design['w'])) throw new Chyba('Slide nemá šablonu – otevřete ho v editoru.');
                $d['content'] = json_encode($design, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $d['width'] = (int)$design['w'];
                $d['height'] = (int)$design['h'];
                break;
        }
        if ($d['width'] === null || $d['height'] === null) throw new Chyba('Vyplňte šířku a výšku banneru.');
        $d['updated_at'] = time();
        $db = $this->app->db;
        if ($stary === null) {
            $d['created_at'] = $d['updated_at'];
            $db->q('INSERT INTO banners (' . implode(', ', array_keys($d)) . ') VALUES (' . implode(', ', array_fill(0, count($d), '?')) . ')', array_values($d));
            $id = $db->posledniId();
            $this->app->log('info', "Nový banner {$d['name']} ({$d['width']}×{$d['height']}) v kampani {$kampan['name']}");
        } else {
            $db->transakce(function () use ($db, $d, $id, $stary) {
                $db->q('UPDATE banners SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($d))) . ' WHERE id = ?', [...array_values($d), $id]);
                // změna rozměru: přímá přiřazení do zón s jiným pevným rozměrem už neplatí
                if ((int)$stary['width'] !== $d['width'] || (int)$stary['height'] !== $d['height']) {
                    $db->q('DELETE FROM zone_banners WHERE banner_id = ? AND zone_id IN (SELECT id FROM zones WHERE width IS NOT NULL AND (width <> ? OR height <> ?))',
                        [$id, $d['width'], $d['height']]);
                }
            });
        }
        if ($stary !== null && $typ === 'html5' && $stary['content'] !== $d['content']) $this->app->html5()->uklid($this->balickyHtml5());
        return $id;
    }

    public function nastavStav(int $id, string $stav): void
    {
        if (!isset(self::STAVY[$stav])) throw new Chyba('Neplatný stav banneru.');
        $this->radek($id);
        $this->app->db->q('UPDATE banners SET status = ?, updated_at = ? WHERE id = ?', [$stav, time(), $id]);
    }

    public function duplikuj(int $id): int
    {
        $b = $this->radek($id);
        unset($b['id'], $b['campaign_name']);
        $b['name'] = mb_substr($b['name'] . ' (kopie)', 0, 200);
        $b['created_at'] = $b['updated_at'] = time();
        $this->app->db->q('INSERT INTO banners (' . implode(', ', array_keys($b)) . ') VALUES (' . implode(', ', array_fill(0, count($b), '?')) . ')', array_values($b));
        return $this->app->db->posledniId();
    }

    public function smaz(int $id): void
    {
        $b = $this->radek($id);
        $this->app->db->q('DELETE FROM banners WHERE id = ?', [$id]);
        if ($b['type'] === 'slide') $this->app->slideBannery()->smazObrazky($id);
        if ($b['type'] === 'html5') $this->app->html5()->uklid($this->balickyHtml5());
        $this->app->log('info', "Banner smazán: {$b['name']}");
    }

    /** Balíčky HTML5, které bannery používají (md5). @return list<string> */
    public function balickyHtml5(): array
    {
        $out = [];
        foreach ($this->app->db->q("SELECT content FROM banners WHERE type = 'html5'")->fetchAll(\PDO::FETCH_COLUMN) as $c) {
            $z = json_decode((string)$c, true)['zip'] ?? null;
            if (is_string($z)) $out[] = $z;
        }
        return array_values(array_unique($out));
    }
}
