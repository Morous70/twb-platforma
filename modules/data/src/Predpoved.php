<?php
declare(strict_types=1);
namespace TWB\Data;

use DateTimeImmutable;
use RuntimeException;
use Throwable;
use TWB\Jadro\{Db, Protokol};

/**
 * Počasí pro proměnné {{Počasí:teplota}}, {{Počasí:zítra.max}}, {{Počasí:Brno.popis}}. Poskytovatel OpenWeather (bezplatné API 2.5).
 * Místo = název („Praha,CZ“) nebo souřadnice („49.9884,17.4637“). Data se ukládají do tabulky `weather` podle místa; cron je
 * obnovuje, před zveřejněním se stáhnou, pokud jsou starší. Při výpadku se použijí poslední stažená data.
 */
final class Predpoved implements Pocasi
{
    public const INTERVAL_MIN = 15;
    public const OPAKOVANI_MIN = 15;
    private const POLE_TED = ['teplota' => 'temp', 'pocitova' => 'feels', 'popis' => 'desc', 'vlhkost' => 'humidity', 'vitr' => 'wind', 'misto' => 'place', 'cas' => 'time'];
    private const POLE_DEN = ['max' => 'max', 'min' => 'min', 'popis' => 'desc', 'den' => 'day', 'datum' => 'date'];
    private const DNY = ['dnes' => 0, 'zitra' => 1, 'pozitri' => 2];

    private string $t;
    /** @var callable(string): array{0: int, 1: string} */
    private $http;
    /** @var callable(): int */
    private $cas;
    /** @var callable(): string */
    private $klic;
    /** @var callable(): string */
    private $vychoziMisto;
    /** @var callable(): list<string> */
    private $pouzitaMista;
    /** @var array<string, array{0: ?array, 1: ?string}> */
    private array $pamet = [];

    /**
     * @param callable(): string $klic API klíč OpenWeather z nastavení aplikace
     * @param callable(): string $vychoziMisto výchozí místo z nastavení
     * @param callable(): list<string>|null $pouzitaMista místa použitá v textech aplikace (kromě výchozího), pro cron
     * @param callable(string): array{0: int, 1: string}|null $http stáhne adresu → [kód, tělo]; chyba spojení = RuntimeException
     */
    public function __construct(
        private Db $db,
        private Protokol $protokol,
        callable $klic,
        callable $vychoziMisto,
        ?callable $pouzitaMista = null,
        ?callable $http = null,
        ?callable $cas = null,
        private string $zakladniUrl = 'https://api.openweathermap.org',
        private string $aplikace = 'TWB',
        string $tabulka = 'weather',
    ) {
        $this->t = Db::identifikator($tabulka);
        $this->klic = $klic;
        $this->vychoziMisto = $vychoziMisto;
        $this->pouzitaMista = $pouzitaMista ?? fn() => [];
        $this->http = $http ?? [$this, 'curl'];
        $this->cas = $cas ?? 'time';
    }

    public function nastaveno(): bool { return trim(($this->klic)()) !== ''; }

    /** Souřadnice „49.9884,17.4637“ → [lat, lon], jinak null. */
    public static function souradnice(string $misto): ?array
    {
        if (!preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*[,;]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $misto, $m)) return null;
        $lat = (float)$m[1]; $lon = (float)$m[2];
        return abs($lat) <= 90 && abs($lon) <= 180 ? [$lat, $lon] : null;
    }

    public static function klicMista(string $misto): string
    {
        if ($c = self::souradnice($misto)) return sprintf('%.4f,%.4f', $c[0], $c[1]);
        $p = mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $misto)));
        return mb_substr((string)preg_replace('/\s*,\s*/u', ',', $p), 0, 100);
    }

    /** Místo zadané v proměnné, jinak výchozí z nastavení. */
    public function misto(?string $misto): string
    {
        $p = trim((string)$misto);
        return mb_substr($p !== '' ? $p : trim(($this->vychoziMisto)()), 0, 100);
    }

    public function radek(string $misto): ?array
    {
        $k = self::klicMista($misto);
        if ($k === '') return null;
        $r = $this->db->q("SELECT * FROM {$this->t} WHERE place_key = ?", [$k])->fetch();
        if (!$r) return null;
        $r['data'] = $r['payload'] ? json_decode($r['payload'], true) : null;
        return $r;
    }

    public function jeCasStahnout(?array $radek): bool
    {
        if (!$radek || !$radek['fetched_at']) return true;
        return ($this->cas)() - (int)$radek['fetched_at'] >= ($radek['error'] ? self::OPAKOVANI_MIN : self::INTERVAL_MIN) * 60 - 30;
    }

    /** Data pro místo z mezipaměti; když chybí nebo jsou stará, stáhnou se hned. @return array{0: ?array, 1: ?string} [data, chyba] */
    public function ziskej(string $misto, bool $stahnout = true): array
    {
        $misto = $this->misto($misto);
        if ($misto === '') return [null, 'Není zadané místo (výchozí místo nastavíte v Proměnné a data → Počasí).'];
        $k = self::klicMista($misto);
        if (isset($this->pamet[$k])) return $this->pamet[$k];
        $radek = $this->radek($misto);
        if ($stahnout && $this->nastaveno() && $this->jeCasStahnout($radek)) { $this->stahni($misto); $radek = $this->radek($misto); }
        if (!$this->nastaveno() && !$radek) return $this->pamet[$k] = [null, 'Chybí API klíč (Proměnné a data → Počasí).'];
        if (!$radek || !$radek['data']) return $this->pamet[$k] = [null, $radek['error'] ?? 'Počasí zatím není stažené.'];
        return $this->pamet[$k] = [$radek['data'], $radek['error'] ?: null];
    }

    /** Stáhne počasí pro místo a uloží ho. @return array{ok: bool, error: ?string, data: ?array} */
    public function stahni(string $misto): array
    {
        $misto = $this->misto($misto);
        $k = self::klicMista($misto);
        if ($k === '') return ['ok' => false, 'error' => 'Není zadané místo.', 'data' => null];
        $ted = ($this->cas)();
        $stare = $this->radek($misto);
        unset($this->pamet[$k]);
        try {
            if (!$this->nastaveno()) throw new RuntimeException('Chybí API klíč.');
            $data = ['place' => $misto, 'at' => $ted] + $this->openWeather($misto, trim(($this->klic)()));
            $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->db->q("REPLACE INTO {$this->t} (place_key, place, payload, fetched_at, ok_at, error) VALUES (?,?,?,?,?,NULL)", [$k, $misto, $payload, $ted, $ted]);
            if ($stare && $stare['error'] && $stare['ok_at']) $this->protokol->zapis('info', "Počasí pro „{$misto}“ je opět dostupné.");
            return ['ok' => true, 'error' => null, 'data' => $data];
        } catch (Throwable $e) {
            $chyba = $e->getMessage();
            if ($stare) $this->db->q("UPDATE {$this->t} SET fetched_at = ?, error = ? WHERE place_key = ?", [$ted, $chyba, $k]);
            else $this->db->q("INSERT INTO {$this->t} (place_key, place, payload, fetched_at, ok_at, error) VALUES (?,?,NULL,?,NULL,?)", [$k, $misto, $ted, $chyba]);
            if ($stare && $stare['ok_at'] && !$stare['error']) $this->protokol->zapis('warn', "Počasí pro „{$misto}“ se nepodařilo stáhnout: $chyba. Hodnoty zůstávají podle posledního stažení.");
            return ['ok' => false, 'error' => $chyba, 'data' => $stare['data'] ?? null];
        }
    }

    /** Místa, pro která se má počasí stahovat: výchozí + místa z textů aplikace. @return list<string> */
    public function pouzitaMista(): array
    {
        $mista = [];
        $pridej = function (string $p) use (&$mista) { $p = $this->misto($p); if ($p !== '') $mista[self::klicMista($p)] ??= $p; };
        if (trim(($this->vychoziMisto)()) !== '') $pridej('');
        foreach (($this->pouzitaMista)() as $m) $pridej($m);
        return array_values($mista);
    }

    /** Místa použitá v textu: z proměnných {{Počasí:Místo.…}} / {Počasí:…}. @return list<string> */
    public static function mistaVTextu(string $text): array
    {
        $o = [];
        if (preg_match_all('/\{\{?\s*po[cč]as[ií]\s*:([^{}|]*)/iu', $text, $mm)) {
            foreach ($mm[1] as $arg) { $p = self::rozbor($arg); if ($p) $o[] = $p['place']; }
        }
        return $o;
    }

    /** Obnoví počasí, kterému uplynul interval. Volá cron. Stará nepoužívaná místa z mezipaměti smaže. */
    public function obnovPo(): void
    {
        if (!$this->nastaveno()) return;
        $pouzita = $this->pouzitaMista();
        foreach ($pouzita as $p) if ($this->jeCasStahnout($this->radek($p))) $this->stahni($p);
        $klice = array_map([self::class, 'klicMista'], $pouzita);
        foreach ($this->db->q("SELECT place_key, fetched_at FROM {$this->t}")->fetchAll() as $r) {
            if (!in_array($r['place_key'], $klice, true) && ($this->cas)() - (int)$r['fetched_at'] > 86400) $this->db->q("DELETE FROM {$this->t} WHERE place_key = ?", [$r['place_key']]);
        }
    }

    // ---------- hodnoty pro proměnné ----------
    /** Argument {{Počasí:…}}: [místo.]pole nebo [místo.]den.pole → ['place', 'day' (null = teď), 'field'] nebo null. */
    public static function rozbor(string $arg): ?array
    {
        $segs = array_map('trim', explode('.', trim($arg)));
        if (!$segs || $segs === ['']) return null;
        $posledni = Format::klic(array_pop($segs));
        if ($segs) {
            $dk = Format::klic(end($segs));
            $den = self::DNY[$dk] ?? (preg_match('/^den([1-6])$/', $dk, $m) ? (int)$m[1] - 1 : null);
            if ($den !== null && isset(self::POLE_DEN[$posledni])) { array_pop($segs); return ['place' => implode('.', $segs), 'day' => $den, 'field' => self::POLE_DEN[$posledni]]; }
        }
        if (!isset(self::POLE_TED[$posledni])) return null;
        return ['place' => implode('.', $segs), 'day' => null, 'field' => self::POLE_TED[$posledni]];
    }

    public static function cele(float $v): string { $r = (int)round($v); return (string)($r === 0 ? 0 : $r); }
    public static function velke(string $s): string { return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1); }

    /** Den předpovědi podle data (den zveřejnění + $n dní). */
    public static function den(array $data, int $n, DateTimeImmutable $t): ?array
    {
        $datum = $t->setTime(12, 0)->modify("+$n day")->format('Y-m-d');
        foreach ($data['days'] ?? [] as $d) if (($d['date'] ?? '') === $datum) return $d;
        return null;
    }

    public static function popisekDne(string $datum, DateTimeImmutable $t): string
    {
        if ($datum === $t->format('Y-m-d')) return 'Dnes';
        if ($datum === $t->modify('+1 day')->format('Y-m-d')) return 'Zítra';
        return self::velke(Format::DNY[(int)(new DateTimeImmutable($datum))->format('N')]);
    }

    /** Hodnota {{Počasí:…}}: null = neznámé pole, '' = data zatím nejsou. „Teď“ = poslední stažená data. */
    public function hodnota(string $arg, DateTimeImmutable $t): ?string
    {
        $p = self::rozbor($arg);
        if (!$p) return null;
        [$data] = $this->ziskej($p['place']);
        if (!$data) return '';
        if ($p['day'] === null) {
            $n = $data['now'] ?? [];
            return match ($p['field']) {
                'temp', 'feels', 'wind' => self::cele((float)($n[$p['field']] ?? 0)),
                'humidity' => (string)(int)($n['humidity'] ?? 0),
                'desc' => self::velke((string)($n['desc'] ?? '')),
                'place' => self::souradnice((string)($data['place'] ?? '')) ? (string)($data['name'] ?? '') : (string)($data['place'] ?? ''),
                'time' => isset($data['at']) ? date('G:i', (int)$data['at']) : '',
            };
        }
        $d = self::den($data, $p['day'], $t);
        if (!$d) return '';
        return match ($p['field']) {
            'min', 'max' => self::cele((float)$d[$p['field']]),
            'desc' => self::velke((string)($d['desc'] ?? '')),
            'day' => self::popisekDne($d['date'], $t),
            'date' => (new DateTimeImmutable($d['date']))->format('j. n.'),
        };
    }

    // ---------- OpenWeather ----------
    public static function adresaIkony(string $kod): string { return 'https://openweathermap.org/img/wn/' . rawurlencode($kod) . '@2x.png'; }

    private function ow(string $cesta, string $misto, string $klic): array
    {
        $c = self::souradnice($misto);
        $q = $c ? ['lat' => $c[0], 'lon' => $c[1]] : ['q' => $misto];
        $url = rtrim($this->zakladniUrl, '/') . "/data/2.5/$cesta?" . http_build_query($q + ['appid' => $klic, 'units' => 'metric', 'lang' => 'cz']);
        [$kod, $telo] = ($this->http)($url);
        $j = json_decode($telo, true);
        if ($kod === 401) throw new RuntimeException('OpenWeather nepřijal API klíč (nový klíč začne fungovat až do 2 hodin po založení).');
        if ($kod === 404) throw new RuntimeException("Místo „{$misto}“ OpenWeather nezná. Zkuste např. „Praha,CZ“ nebo souřadnice „49.9884,17.4637“.");
        if ($kod === 429) throw new RuntimeException('Překročen limit dotazů OpenWeather.');
        if ($kod >= 400 || !is_array($j)) throw new RuntimeException("OpenWeather vrátil chybu HTTP $kod" . (is_array($j) && isset($j['message']) ? ': ' . $j['message'] : '') . '.');
        return $j;
    }

    /** Jednotná data: ['name', 'now' => [temp, feels, desc, icon, humidity, wind], 'days' => [[date, min, max, desc, icon], …]]. */
    private function openWeather(string $misto, string $klic): array
    {
        $cur = $this->ow('weather', $misto, $klic);
        $fc = $this->ow('forecast', $misto, $klic);
        $tz = (int)($fc['city']['timezone'] ?? $cur['timezone'] ?? 0);
        $w0 = $cur['weather'][0] ?? [];
        $now = [
            'temp' => (float)($cur['main']['temp'] ?? 0), 'feels' => (float)($cur['main']['feels_like'] ?? $cur['main']['temp'] ?? 0),
            'desc' => (string)($w0['description'] ?? ''), 'icon' => (string)($w0['icon'] ?? ''),
            'humidity' => (int)($cur['main']['humidity'] ?? 0), 'wind' => (float)($cur['wind']['speed'] ?? 0),
        ];
        $days = [];
        foreach ((array)($fc['list'] ?? []) as $it) {
            $dt = (int)($it['dt'] ?? 0) + $tz;
            $date = gmdate('Y-m-d', $dt);
            $hour = (int)gmdate('G', $dt);
            $d = &$days[$date];
            $d['date'] = $date;
            $d['min'] = min($d['min'] ?? INF, (float)($it['main']['temp_min'] ?? $it['main']['temp'] ?? 0));
            $d['max'] = max($d['max'] ?? -INF, (float)($it['main']['temp_max'] ?? $it['main']['temp'] ?? 0));
            $dist = abs($hour - 13);
            if (!isset($d['_dist']) || $dist < $d['_dist']) {
                $d['_dist'] = $dist;
                $d['icon'] = preg_replace('/n$/', 'd', (string)($it['weather'][0]['icon'] ?? ''));
                $d['desc'] = (string)($it['weather'][0]['description'] ?? '');
            }
            unset($d);
        }
        $dnes = gmdate('Y-m-d', (int)($cur['dt'] ?? ($this->cas)()) + $tz);
        if (!isset($days[$dnes])) $days[$dnes] = ['date' => $dnes, 'min' => $now['temp'], 'max' => $now['temp'], 'icon' => $now['icon'], 'desc' => $now['desc']];
        $days[$dnes]['min'] = min($days[$dnes]['min'], $now['temp']);
        $days[$dnes]['max'] = max($days[$dnes]['max'], $now['temp']);
        $days[$dnes]['icon'] = $now['icon'] ?: $days[$dnes]['icon'];
        $days[$dnes]['desc'] = $now['desc'] ?: $days[$dnes]['desc'];
        ksort($days);
        $out = [];
        foreach ($days as $date => $d) { if ($date < $dnes) continue; unset($d['_dist']); $out[] = $d; }
        return ['name' => (string)($cur['name'] ?? $misto), 'now' => $now, 'days' => array_slice($out, 0, 6)];
    }

    /** @return array{0: int, 1: string} */
    private function curl(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => $this->aplikace,
        ]);
        $telo = curl_exec($ch);
        $kod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if ($telo === false) throw new RuntimeException('Služba počasí neodpovídá' . ($err !== '' ? ': ' . preg_replace('/appid=[^&\s]+/', 'appid=…', $err) : '') . '.');
        return [$kod, (string)$telo];
    }
}
