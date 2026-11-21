<?php
declare(strict_types=1);
namespace TWB\Data;

use RuntimeException;
use Throwable;
use TWB\Jadro\{Db, Protokol};

/**
 * Vzdálené zdroje dat (JSON nebo CSV) uložené v tabulce `sources`. Cron je stahuje podle intervalu, texty z nich čtou hodnoty
 * ({{Allwyn:sportka.superjackpot}}, {{Ceník:Káva.Cena}}). Při výpadku zůstávají poslední stažená data; do protokolu jde
 * jedno varování a po obnovení zpráva o obnovení.
 */
final class Vzdalene implements Zdroje
{
    /** @var list<array<string, mixed>>|null */
    private ?array $cache = null;
    private string $t;
    /** @var callable(string, bool): array{0: int, 1: string} */
    private $http;
    /** @var callable(): int */
    private $cas;

    /**
     * @param callable(string, bool): array{0: int, 1: string}|null $http stáhne adresu → [kód, tělo]; chyba spojení = RuntimeException
     * @param callable(): int|null $cas aktuální čas (UNIX)
     */
    public function __construct(private Db $db, private Protokol $protokol, private string $aplikace = 'TWB', ?callable $http = null, ?callable $cas = null, string $tabulka = 'sources')
    {
        $this->t = Db::identifikator($tabulka);
        $this->http = $http ?? [$this, 'curl'];
        $this->cas = $cas ?? 'time';
    }

    /** @return list<array<string, mixed>> všechny zdroje včetně dekódovaných dat (klíč `json`) */
    public function vsechny(bool $znovu = false): array
    {
        if ($this->cache === null || $znovu) {
            $this->cache = $this->db->q("SELECT * FROM {$this->t} ORDER BY name COLLATE NOCASE")->fetchAll();
            foreach ($this->cache as &$s) $s['json'] = $s['payload'] !== null && $s['payload'] !== '' ? json_decode($s['payload'], true) : null;
            unset($s);
        }
        return $this->cache;
    }

    public function podleNazvu(string $nazev): ?array
    {
        $k = Format::klic($nazev);
        foreach ($this->vsechny() as $s) if (Format::klic($s['name']) === $k) return $s;
        return null;
    }

    public function existuje(string $nazev): bool { return $this->podleNazvu($nazev) !== null; }

    /** Hodnota ze zdroje pro proměnnou {Zdroj:cesta}, nebo null. Zatím nestažený zdroj dává prázdný text. */
    public function hodnota(string $nazev, string $cesta): ?string
    {
        $s = $this->podleNazvu($nazev);
        if (!$s) return null;
        if ($s['json'] === null) return '';
        $segs = array_values(array_filter(array_map('trim', explode('.', $cesta)), fn($x) => $x !== ''));
        if (!$segs) return null;
        $r = Hledani::najdi($s['json'], $segs);
        return $r ? Hledani::naText($r[0]) : null;
    }

    /** Adresa bez hodnot parametrů, které vypadají jako klíč (do protokolu a chybových hlášek). */
    public static function bezpecnaAdresa(string $url): string
    {
        return preg_replace('/([?&](?:key|apikey|api_key|appid|token|access_token|secret)=)[^&#]+/i', '$1…', $url);
    }

    /** Stáhne zdroj a uloží data. @return array{ok: bool, changed: bool, error: ?string} */
    public function stahni(array $s): array
    {
        $ted = ($this->cas)();
        $chyba = null; $data = null;
        try {
            $csv = ($s['format'] ?? 'json') === 'csv';
            [$kod, $telo] = ($this->http)($csv ? Hledani::csvUrl($s['url']) : $s['url'], $csv);
            if ($csv) {
                if ($kod >= 400) throw new RuntimeException("HTTP $kod");
                $data = Hledani::csvNaRadky($telo, (bool)($s['csv_header'] ?? 1));
            } else {
                $data = json_decode($telo, true);
                if ($kod >= 400) {
                    $msg = is_array($data) && isset($data['error']) && is_string($data['error']) ? $data['error'] : mb_substr(trim(strip_tags($telo)), 0, 150);
                    throw new RuntimeException("HTTP $kod" . ($msg !== '' ? ": $msg" : ''));
                }
                if (!is_array($data)) throw new RuntimeException('Odpověď není platný JSON.');
                if (array_key_exists('success', $data) && $data['success'] === false) {
                    throw new RuntimeException('Zdroj hlásí chybu: ' . (is_string($data['error'] ?? null) ? $data['error'] : 'bez popisu'));
                }
            }
        } catch (Throwable $e) {
            $chyba = mb_substr($e->getMessage(), 0, 500);
        }
        if ($chyba !== null) {
            $this->db->q("UPDATE {$this->t} SET fetched_at = ?, error = ? WHERE id = ?", [$ted, $chyba, (int)$s['id']]);
            // do protokolu jen při přechodu do chyby (ne při každém pokusu)
            if (empty($s['error'])) $this->protokol->zapis('warn', "Zdroj „{$s['name']}“ se nepodařilo stáhnout: $chyba. Texty používají poslední stažená data.");
            $this->vsechny(true);
            return ['ok' => false, 'changed' => false, 'error' => $chyba];
        }
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = md5($payload);
        $zmena = $hash !== (string)($s['hash'] ?? '');
        $this->db->q("UPDATE {$this->t} SET fetched_at = ?, ok_at = ?, error = NULL, payload = ?, hash = ? WHERE id = ?", [$ted, $ted, $payload, $hash, (int)$s['id']]);
        if (!empty($s['error'])) $this->protokol->zapis('info', "Zdroj „{$s['name']}“ je opět dostupný.");
        $this->vsechny(true);
        return ['ok' => true, 'changed' => $zmena, 'error' => null];
    }

    /** Stáhne zdroje, kterým uplynul interval. Volá cron. Vrací true, když se některá data změnila. */
    public function stahniPo(): bool
    {
        $zmena = false;
        foreach ($this->vsechny(true) as $s) {
            if (($this->cas)() - (int)$s['fetched_at'] < max(1, (int)$s['interval_min']) * 60 - 30) continue;
            $zmena = $this->stahni($s)['changed'] || $zmena;
        }
        return $zmena;
    }

    /** Strom hodnot zdroje pro výběr v editoru. */
    public function strom(array $s): array { return is_array($s['json'] ?? null) ? Hledani::strom($s['json']) : []; }

    /** @return array{0: int, 1: string} */
    private function curl(string $url, bool $csv): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [$csv ? 'Accept: text/csv, text/plain, */*' : 'Accept: application/json'],
            CURLOPT_USERAGENT => $this->aplikace,
        ]);
        $telo = curl_exec($ch);
        $kod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        if ($telo === false) throw new RuntimeException('Zdroj neodpovídá: ' . self::bezpecnaAdresa($cerr));
        return [$kod, (string)$telo];
    }
}
