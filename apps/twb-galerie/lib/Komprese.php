<?php
declare(strict_types=1);
namespace MediaGalerie;

use TWB\Zalohy\Zalohy;

/**
 * Hromadná komprese obrázků z galerie (0.8.0). Úloha se uloží do místního nastavení `komprese_uloha` a zpracovává se po krocích
 * (každý krok nejvýš několik sekund, aspoň jeden obrázek), takže na sdíleném hostingu nenarazí na časový limit PHP.
 * Před každým obrázkem se zapíše, který se právě zpracovává: kdyby PHP spadlo (paměť, limit), další krok ho přeskočí jako chybu
 * a pokračuje dál místo nekonečného opakování.
 */
final class Komprese
{
    public const FORMATY = ['jpg' => 'JPEG', 'webp' => 'WebP'];
    /** Delší strana po zmenšení (0 = nezmenšovat). */
    public const ROZMERY = [0 => 'Nezmenšovat', 3840 => '3840 px (4K)', 2560 => '2560 px', 1920 => '1920 px (Full HD)', 1600 => '1600 px', 1280 => '1280 px'];
    public const VYCHOZI = ['format' => 'jpg', 'kvalita' => 82, 'rozmer' => 2560, 'ponechat' => true, 'metadata' => true];
    /** Kolik sekund smí trvat jeden krok (pod běžným max_execution_time 30 s i s rezervou na jeden velký obrázek). */
    public const KROK_SEKUND = 8.0;
    private const KLIC = 'komprese_uloha';

    public function __construct(private App $app) {}

    /**
     * Ověří volby z formuláře. @param array<string, mixed> $p
     * @return array{format: string, kvalita: int, rozmer: int, ponechat: bool, metadata: bool}
     */
    public static function volby(array $p): array
    {
        $format = (string)($p['komprese_format'] ?? self::VYCHOZI['format']);
        if (!isset(self::FORMATY[$format])) throw new Chyba('Neplatný formát.');
        if ($format === 'webp' && !function_exists('imagewebp')) throw new Chyba('Server neumí ukládat WebP (chybí podpora v GD). Zvolte JPEG.');
        $kvalita = (int)($p['komprese_kvalita'] ?? self::VYCHOZI['kvalita']);
        if ($kvalita < 40 || $kvalita > 95) throw new Chyba('Kvalita musí být 40 až 95.');
        $rozmer = (int)($p['komprese_rozmer'] ?? self::VYCHOZI['rozmer']);
        if (!isset(self::ROZMERY[$rozmer])) throw new Chyba('Neplatný rozměr.');
        return ['format' => $format, 'kvalita' => $kvalita, 'rozmer' => $rozmer, 'ponechat' => !empty($p['komprese_ponechat']), 'metadata' => !empty($p['komprese_metadata'])];
    }

    /** Nastavení pro Galerie::komprimujPodrobne. @param array{format: string, kvalita: int, rozmer: int, metadata: bool} $v */
    public static function nastaveni(array $v): array
    {
        $max = $v['rozmer'] > 0 ? $v['rozmer'] : 100_000;
        return ['max_w' => $max, 'max_h' => $max, 'quality' => $v['kvalita'], 'min_kb' => 0, 'format' => $v['format'], 'metadata' => $v['metadata']];
    }

    /** Rozpracovaná nebo dokončená úloha (null = žádná). @return ?array<string, mixed> */
    public function uloha(): ?array
    {
        $u = json_decode((string)$this->app->nastaveni()->dej(self::KLIC, ''), true);
        return is_array($u) && isset($u['ids'], $u['i']) ? $u : null;
    }

    private function uloz(?array $u): void
    {
        $this->app->nastaveni()->uloz(self::KLIC, $u === null ? null : (string)json_encode($u, JSON_UNESCAPED_UNICODE));
    }

    public function zrus(): void { $this->uloz(null); }

    /**
     * Založí úlohu z vybraných médií (jen obrázky; videa a PDF se přeskočí). Rozpracovanou úlohu nahradí.
     * @param list<int> $ids @param array{format: string, kvalita: int, rozmer: int, ponechat: bool, metadata: bool} $volby
     * @return array<string, mixed>
     */
    public function zaloz(array $ids, array $volby, string $zpet): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $obrazky = [];
        if ($ids) {
            $otazniky = implode(',', array_fill(0, count($ids), '?'));
            $obrazky = array_map('intval', $this->app->db->q("SELECT id FROM media WHERE kind = 'image' AND id IN ($otazniky) ORDER BY id", $ids)->fetchAll(\PDO::FETCH_COLUMN));
        }
        if (!$obrazky) throw new Chyba('Mezi vybranými médii nejsou žádné obrázky.');
        $u = ['ids' => $obrazky, 'i' => 0, 'volby' => $volby, 'zpet' => $zpet, 'jine' => count($ids) - count($obrazky),
            'zkomprimovano' => 0, 'preskoceno' => 0, 'chyby' => [], 'pred' => 0, 'po' => 0, 'disk_pred' => 0, 'disk_po' => 0,
            'rozpracovano' => null, 'zacatek' => time(), 'konec' => null];
        $this->uloz($u);
        return $u;
    }

    /** Zpracuje další obrázky (nejvýš $sekund, aspoň jeden). @return array<string, mixed> stav úlohy */
    public function krok(float $sekund = self::KROK_SEKUND): array
    {
        $u = $this->uloha() ?? throw new Chyba('Žádná komprese neprobíhá.');
        if ($u['konec'] !== null) return $u;
        @set_time_limit(120);
        ignore_user_abort(true);
        $galerie = $this->app->galerie();
        $s = self::nastaveni($u['volby']);
        $start = microtime(true);
        $n = count($u['ids']);
        while ($u['i'] < $n) {
            $id = (int)$u['ids'][$u['i']];
            if ($u['rozpracovano'] === $id) {
                // minulý krok u tohoto obrázku spadl (paměť, časový limit) – přeskočit
                $u['chyby'][] = $this->nazev($id) . ': zpracování se nedokončilo (obrázek je na server příliš velký).';
                $u['rozpracovano'] = null;
                $u['i']++;
                $this->uloz($u);
                continue;
            }
            $u['rozpracovano'] = $id;
            $this->uloz($u);
            try {
                $v = $galerie->komprimujPodrobne($id, $s, true, (bool)$u['volby']['ponechat'], true);
                if ($v === null) {
                    $u['preskoceno']++;
                } else {
                    $u['zkomprimovano']++;
                    foreach (['pred', 'po', 'disk_pred', 'disk_po'] as $k) $u[$k] += $v[$k];
                }
            } catch (\Throwable $e) {
                $u['chyby'][] = $this->nazev($id) . ': ' . $e->getMessage();
            }
            $u['rozpracovano'] = null;
            $u['i']++;
            $this->uloz($u);
            if (microtime(true) - $start >= $sekund) break;
        }
        if ($u['i'] >= $n) {
            $u['konec'] = time();
            $this->uloz($u);
            $this->app->media()->uklidNahledy();
            $this->app->log('info', 'Hromadná komprese: ' . self::souhrn($u));
            foreach (array_slice($u['chyby'], 0, 20) as $c) $this->app->log('warn', 'Komprese se nezdařila: ' . $c);
        }
        return $u;
    }

    private function nazev(int $id): string
    {
        return (string)($this->app->db->q('SELECT original_name FROM media WHERE id = ?', [$id])->fetchColumn() ?: "#{$id}");
    }

    /** Stav pro prohlížeč (JSON). @param array<string, mixed> $u @return array<string, mixed> */
    public static function stav(array $u): array
    {
        return ['hotovo' => $u['konec'] !== null, 'zpracovano' => (int)$u['i'], 'celkem' => count($u['ids']), 'zkomprimovano' => (int)$u['zkomprimovano'],
            'preskoceno' => (int)$u['preskoceno'], 'chyb' => count($u['chyby']), 'usetreno' => max(0, (int)$u['pred'] - (int)$u['po']),
            'usetreno_text' => Zalohy::velikost(max(0, (int)$u['pred'] - (int)$u['po'])), 'souhrn' => self::souhrn($u)];
    }

    /** Souhrn česky: kolik obrázků, o kolik menší, co s místem na disku. @param array<string, mixed> $u */
    public static function souhrn(array $u): string
    {
        $t = "zkomprimováno {$u['zkomprimovano']} z " . count($u['ids']) . ' obrázků';
        if ($u['preskoceno']) $t .= ", {$u['preskoceno']} už bylo dost malých";
        if ($u['chyby']) $t .= ', chyb ' . count($u['chyby']);
        if ($u['zkomprimovano']) {
            $usetreno = max(0, (int)$u['pred'] - (int)$u['po']);
            $t .= '; soubory jsou menší o ' . Zalohy::velikost($usetreno) . ' (' . Zalohy::velikost((int)$u['pred']) . ' → ' . Zalohy::velikost((int)$u['po'])
                . ($u['pred'] > 0 ? ', −' . (int)round($usetreno * 100 / (int)$u['pred']) . ' %' : '') . ')';
            $disk = (int)$u['disk_pred'] - (int)$u['disk_po'];
            $t .= $disk >= 0 ? '; na disku uvolněno ' . Zalohy::velikost($disk) : '; na disku přibylo ' . Zalohy::velikost(-$disk) . ' (originály zůstaly kvůli návratu)';
        }
        return $t . '.';
    }
}
