<?php
// Počasí pro slidy: prvek „Počasí“ (3 vzhledy) a proměnné {Počasí:teplota}, {Počasí:Brno.zítra.max} …
//
// Poskytovatel je vyměnitelný: v WEATHER_PROVIDERS stačí doplnit funkci, která vrátí jednotná data
//   ['name' => název místa u poskytovatele,
//    'now'  => ['temp', 'feels', 'desc', 'icon', 'humidity', 'wind'],
//    'days' => [['date' => 'Y-m-d', 'min', 'max', 'desc', 'icon'], …]]   (dnešek a další dny)
// a funkci s adresou ikony podle jejího kódu. Zbytek aplikace (cron, mezipaměť, slidy) se nemění.
//
// Data se ukládají do tabulky weather podle místa. Cron je obnovuje každých 15 minut; při výpadku
// slidy používají poslední stažená data a do protokolu jde jedno varování (stejně jako u zdrojů).

const WEATHER_PROVIDERS = [
    'openweather' => ['name' => 'OpenWeather', 'fetch' => 'ow_fetch', 'icon_url' => 'ow_icon_url', 'key_url' => 'https://home.openweathermap.org/api_keys'],
];
const WEATHER_INTERVAL_MIN = 15;
const WEATHER_RETRY_MIN = 15;      // po chybě (neznámé místo, výpadek) se zkouší znovu nejdřív za tuto dobu
const WEATHER_DAYS = 3;            // vzhled „Předpověď na dny“
const WEATHER_LOOKS = ['mini', 'card', 'days'];
define('WEATHER_ICON_DIR', DATA_DIR . '/weather-icons');

function weather_provider(): string {
    $p = (string)setting('weather_provider', 'openweather');
    return isset(WEATHER_PROVIDERS[$p]) ? $p : 'openweather';
}
function weather_api_key(): string { return trim((string)setting('weather_api_key', '')); }
function weather_default_place(): string { return trim((string)setting('weather_place', '')); }
function weather_configured(): bool { return weather_api_key() !== ''; }

/** Klíč místa v mezipaměti: malá písmena, jedna mezera, bez mezer kolem čárky. */
function weather_place_key(string $place): string {
    $p = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $place)));
    return mb_substr(preg_replace('/\s*,\s*/u', ',', $p), 0, 100);
}
/** Místo zadané u prvku/proměnné, jinak výchozí z Nastavení. */
function weather_place(?string $place): string {
    $p = trim((string)$place);
    return mb_substr($p !== '' ? $p : weather_default_place(), 0, 100);
}

function weather_row(string $place): ?array {
    $k = weather_place_key($place);
    if ($k === '') return null;
    try { $r = q('SELECT * FROM weather WHERE place_key = ?', [$k])->fetch(); } catch (Throwable $e) { return null; } // před převodem databáze
    if (!$r) return null;
    $r['data'] = $r['payload'] ? json_decode($r['payload'], true) : null;
    return $r;
}

/** Je čas data stáhnout (nikdy nestaženo, staré, nebo od chyby uplynula doba pro nový pokus)? */
function weather_due(?array $row): bool {
    if (!$row || !$row['fetched_at']) return true;
    $age = time() - strtotime($row['fetched_at']);
    return $age >= ($row['error'] ? WEATHER_RETRY_MIN : WEATHER_INTERVAL_MIN) * 60 - 30;
}

/**
 * Data pro místo z mezipaměti; když chybí nebo jsou stará, stáhnou se hned (editor, první vykreslení).
 * Vrací [data|null, chyba|null]. Při chybě se vrací poslední stažená data, pokud jsou.
 */
function weather_get(string $place, bool $fetch = true): array {
    $mem = &$GLOBALS['WEATHER_MEM'];
    $mem ??= [];
    $place = weather_place($place);
    if ($place === '') return [null, 'Není zadané místo (výchozí místo nastavíte v Nastavení → Počasí).'];
    $k = weather_place_key($place);
    if (isset($mem[$k])) return $mem[$k];
    $row = weather_row($place);
    if ($fetch && weather_configured() && weather_due($row)) { weather_fetch($place); $row = weather_row($place); }
    if (!weather_configured() && !$row) return $mem[$k] = [null, 'Chybí API klíč (Nastavení → Počasí).'];
    if (!$row || !$row['data']) return $mem[$k] = [null, $row['error'] ?? 'Počasí zatím není stažené.'];
    return $mem[$k] = [$row['data'], $row['error'] ?: null];
}

/** Stáhne počasí pro místo u nastaveného poskytovatele a uloží ho. Vrací ['ok' => bool, 'error' => ?string, 'data' => ?array]. */
function weather_fetch(string $place): array {
    $place = weather_place($place);
    $k = weather_place_key($place);
    if ($k === '') return ['ok' => false, 'error' => 'Není zadané místo.', 'data' => null];
    $prov = weather_provider();
    $now = date('Y-m-d H:i:s');
    $old = weather_row($place);
    unset($GLOBALS['WEATHER_MEM'][$k]);
    try {
        if (!weather_configured()) throw new RuntimeException('Chybí API klíč.');
        $data = (WEATHER_PROVIDERS[$prov]['fetch'])($place, weather_api_key());
        $data = ['place' => $place, 'provider' => $prov, 'at' => date(DATE_ATOM)] + $data;
        foreach (weather_icon_codes($data) as $code) weather_icon_file($code, $prov); // ikony předem, vykreslení pak nečeká
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($old) q('UPDATE weather SET place = ?, provider = ?, payload = ?, fetched_at = ?, ok_at = ?, error = NULL WHERE place_key = ?', [$place, $prov, $payload, $now, $now, $k]);
        else q('INSERT INTO weather (place_key, place, provider, payload, fetched_at, ok_at, error) VALUES (?,?,?,?,?,?,NULL)', [$k, $place, $prov, $payload, $now, $now]);
        if ($old && $old['error'] && $old['ok_at']) app_log('info', "Počasí pro „{$place}“ je opět dostupné.");
        return ['ok' => true, 'error' => null, 'data' => $data];
    } catch (Throwable $e) {
        $err = $e->getMessage();
        if ($old) q('UPDATE weather SET fetched_at = ?, error = ? WHERE place_key = ?', [$now, $err, $k]);
        else q('INSERT INTO weather (place_key, place, provider, payload, fetched_at, ok_at, error) VALUES (?,?,?,NULL,?,NULL,?)', [$k, $place, $prov, $now, $err]);
        // do protokolu jen při přechodu do chyby a jen u místa, které už fungovalo (ne při psaní názvu v editoru)
        if ($old && $old['ok_at'] && !$old['error']) app_log('warning', "Počasí pro „{$place}“ se nepodařilo stáhnout: $err. Slidy používají poslední stažená data.");
        return ['ok' => false, 'error' => $err, 'data' => $old['data'] ?? null];
    }
}

// ---------- místa použitá ve slidech a HTML dokumentech ----------
const WEATHER_VAR_RE = '/\{\s*po[cč]as[ií]\s*:([^{}|]*)/iu';

/** Místa, pro která se má počasí stahovat: výchozí + z prvků Počasí a proměnných {Počasí:…}. */
function weather_places_used(): array {
    $places = [];
    $add = function (string $p) use (&$places) { $p = weather_place($p); if ($p !== '') $places[weather_place_key($p)] ??= $p; };
    if (weather_default_place() !== '') $add('');
    foreach (q("SELECT kind, content FROM media WHERE kind IN ('slide', 'html')")->fetchAll() as $m) {
        $c = json_decode((string)$m['content'], true);
        if (!is_array($c)) continue;
        if ($m['kind'] === 'slide') {
            foreach ((array)($c['design']['elements'] ?? []) as $e) if (is_array($e) && ($e['type'] ?? '') === 'weather') $add((string)($e['place'] ?? ''));
        }
        // proměnné {Počasí:…} v jakémkoli textu (texty, varianty, podmínky, zdroj HTML dokumentu)
        array_walk_recursive($c, function ($v) use ($add) {
            if (is_string($v) && preg_match_all(WEATHER_VAR_RE, $v, $mm)) foreach ($mm[1] as $arg) { $p = weather_parse_arg($arg); if ($p) $add($p['place']); }
        });
    }
    return array_values($places);
}

/** Obnoví počasí, kterému uplynul interval. Volá cron. Stará nepoužívaná místa z mezipaměti smaže. */
function weather_refresh_due(): void {
    if (!weather_configured()) return;
    $used = weather_places_used();
    foreach ($used as $p) if (weather_due(weather_row($p))) weather_fetch($p);
    $keys = array_map('weather_place_key', $used);
    foreach (q('SELECT place_key, fetched_at FROM weather')->fetchAll() as $r) {
        if (!in_array($r['place_key'], $keys, true) && (!$r['fetched_at'] || time() - strtotime($r['fetched_at']) > 86400)) q('DELETE FROM weather WHERE place_key = ?', [$r['place_key']]);
    }
}

// ---------- ikony ----------
function weather_icon_codes(array $data): array {
    $c = [$data['now']['icon'] ?? ''];
    foreach ($data['days'] ?? [] as $d) $c[] = $d['icon'] ?? '';
    return array_values(array_unique(array_filter($c, fn($x) => preg_match('/^[0-9a-z]{2,4}$/', (string)$x))));
}
/** Cesta k uložené ikoně; chybí-li, stáhne se. Vrací null, když ikonu nejde získat. */
function weather_icon_file(string $code, ?string $prov = null): ?string {
    if (!preg_match('/^[0-9a-z]{2,4}$/', $code)) return null;
    $prov ??= weather_provider();
    $f = WEATHER_ICON_DIR . "/$prov-$code.png";
    if (is_file($f)) return $f;
    static $failed = [];
    if (isset($failed["$prov-$code"])) return null;
    if (!is_dir(WEATHER_ICON_DIR)) @mkdir(WEATHER_ICON_DIR, 0775, true);
    $body = weather_http((WEATHER_PROVIDERS[$prov]['icon_url'])($code))[1] ?? null;
    if (!is_string($body) || !@imagecreatefromstring($body)) { $failed["$prov-$code"] = true; return null; }
    file_put_contents("$f.tmp", $body);
    rename("$f.tmp", $f);
    return $f;
}

/** GET s časovými limity. Vrací [kód HTTP, tělo]; při nedostupnosti výjimka. */
function weather_http(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'TWB-PlanovacMedii/' . (defined('APP_VERSION') ? APP_VERSION : '1'),
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    if ($body === false) throw new RuntimeException('Služba počasí neodpovídá' . ($err !== '' ? ": $err" : '') . '.');
    return [$code, (string)$body];
}

// ---------- OpenWeather (bezplatné API 2.5: aktuální počasí + předpověď po 3 hodinách na 5 dní) ----------
function ow_base(): string { return rtrim((string)cfg('openweather_url', 'https://api.openweathermap.org'), '/'); }
function ow_icon_url(string $code): string { return rtrim((string)cfg('openweather_icon_url', 'https://openweathermap.org/img/wn'), '/') . "/$code@4x.png"; }

function ow_get(string $path, string $place, string $key): array {
    $url = ow_base() . "/data/2.5/$path?" . http_build_query(['q' => $place, 'appid' => $key, 'units' => 'metric', 'lang' => 'cz']);
    [$code, $body] = weather_http($url);
    $j = json_decode($body, true);
    if ($code === 401) throw new RuntimeException('OpenWeather nepřijal API klíč (nový klíč začne fungovat až do 2 hodin po založení).');
    if ($code === 404) throw new RuntimeException("Místo „{$place}“ OpenWeather nezná. Zkuste např. „Praha,CZ“.");
    if ($code === 429) throw new RuntimeException('Překročen limit dotazů OpenWeather.');
    if ($code >= 400 || !is_array($j)) throw new RuntimeException("OpenWeather vrátil chybu HTTP $code" . (is_array($j) && isset($j['message']) ? ': ' . $j['message'] : '') . '.');
    return $j;
}

function ow_fetch(string $place, string $key): array {
    $cur = ow_get('weather', $place, $key);
    $fc = ow_get('forecast', $place, $key);
    $tz = (int)($fc['city']['timezone'] ?? $cur['timezone'] ?? 0);
    $w0 = $cur['weather'][0] ?? [];
    $now = [
        'temp' => (float)($cur['main']['temp'] ?? 0), 'feels' => (float)($cur['main']['feels_like'] ?? $cur['main']['temp'] ?? 0),
        'desc' => (string)($w0['description'] ?? ''), 'icon' => (string)($w0['icon'] ?? ''),
        'humidity' => (int)($cur['main']['humidity'] ?? 0), 'wind' => (float)($cur['wind']['speed'] ?? 0),
    ];
    // dny podle místního data; ikona a popis z času nejblíž poledni
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
    $today = gmdate('Y-m-d', (int)($cur['dt'] ?? time()) + $tz);
    if (!isset($days[$today])) $days[$today] = ['date' => $today, 'min' => $now['temp'], 'max' => $now['temp'], 'icon' => $now['icon'], 'desc' => $now['desc']];
    // dnešek: zahrnout i aktuální teplotu, ikona podle současného počasí
    $days[$today]['min'] = min($days[$today]['min'], $now['temp']);
    $days[$today]['max'] = max($days[$today]['max'], $now['temp']);
    $days[$today]['icon'] = $now['icon'] ?: $days[$today]['icon'];
    $days[$today]['desc'] = $now['desc'] ?: $days[$today]['desc'];
    ksort($days);
    $out = [];
    foreach ($days as $date => $d) {
        if ($date < $today) continue;
        unset($d['_dist']);
        $out[] = $d;
    }
    return ['name' => (string)($cur['name'] ?? $place), 'now' => $now, 'days' => array_slice($out, 0, 6)];
}

// ---------- hodnoty pro proměnné ----------
const WEATHER_NOW_FIELDS = ['teplota' => 'temp', 'pocitova' => 'feels', 'popis' => 'desc', 'vlhkost' => 'humidity', 'vitr' => 'wind', 'misto' => 'place', 'cas' => 'time'];
const WEATHER_DAY_FIELDS = ['max' => 'max', 'min' => 'min', 'popis' => 'desc', 'den' => 'day', 'datum' => 'date'];
const WEATHER_DAY_KEYS = ['dnes' => 0, 'zitra' => 1, 'pozitri' => 2];

/** Rozloží argument {Počasí:…}: [místo.]pole nebo [místo.]den.pole. Vrací ['place', 'day' (null = teď), 'field'] nebo null. */
function weather_parse_arg(string $arg): ?array {
    $segs = array_map('trim', explode('.', trim($arg)));
    if (!$segs || $segs === ['']) return null;
    $last = norm_key(array_pop($segs));
    if ($segs) {
        $dk = norm_key(end($segs));
        $day = WEATHER_DAY_KEYS[$dk] ?? (preg_match('/^den([1-6])$/', $dk, $m) ? (int)$m[1] - 1 : null);
        if ($day !== null && isset(WEATHER_DAY_FIELDS[$last])) { array_pop($segs); return ['place' => implode('.', $segs), 'day' => $day, 'field' => WEATHER_DAY_FIELDS[$last]]; }
    }
    if (!isset(WEATHER_NOW_FIELDS[$last])) return null;
    return ['place' => implode('.', $segs), 'day' => null, 'field' => WEATHER_NOW_FIELDS[$last]];
}

function weather_int(float $v): string { $r = (int)round($v); return (string)($r === 0 ? 0 : $r); }
function weather_ucfirst(string $s): string { return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1); }
/** Den předpovědi podle data (dnešek + $n dní); data mohou být staršího data, proto podle data, ne podle pořadí. */
function weather_day(array $data, int $n, DateTimeImmutable $t): ?array {
    $date = $t->setTime(12, 0)->modify("+$n day")->format('Y-m-d');
    foreach ($data['days'] ?? [] as $d) if (($d['date'] ?? '') === $date) return $d;
    return null;
}
function weather_day_label(string $date, DateTimeImmutable $t): string {
    $today = $t->format('Y-m-d');
    if ($date === $today) return 'Dnes';
    if ($date === $t->modify('+1 day')->format('Y-m-d')) return 'Zítra';
    return weather_ucfirst(CZ_DAYS[(int)(new DateTimeImmutable($date))->format('N')]);
}

/** Hodnota proměnné {Počasí:…}: null = neznámé pole, '' = data zatím nejsou. */
function weather_value(string $arg, DateTimeImmutable $t): ?string {
    $p = weather_parse_arg($arg);
    if (!$p) return null;
    [$data] = weather_get($p['place']);
    if (!$data) return '';
    if ($p['day'] === null) {
        $n = $data['now'] ?? [];
        return match ($p['field']) {
            'temp', 'feels', 'wind' => weather_int((float)($n[$p['field']] ?? 0)),
            'humidity' => (string)(int)($n['humidity'] ?? 0),
            'desc' => weather_ucfirst((string)($n['desc'] ?? '')),
            'place' => (string)($data['place'] ?? ''),
            'time' => isset($data['at']) ? date('G:i', strtotime($data['at'])) : '',
        };
    }
    $d = weather_day($data, $p['day'], $t);
    if (!$d) return '';
    return match ($p['field']) {
        'min', 'max' => weather_int((float)$d[$p['field']]),
        'desc' => weather_ucfirst((string)($d['desc'] ?? '')),
        'day' => weather_day_label($d['date'], $t),
        'date' => (new DateTimeImmutable($d['date']))->format('j. n.'),
    };
}

// ---------- prvek Počasí ve slidu ----------
/** Vzorová data pro náhled v editoru, když skutečná data ještě nejsou. */
function weather_sample(DateTimeImmutable $t): array {
    $days = [];
    foreach ([[18, 9, '02d', 'skoro jasno'], [15, 8, '10d', 'slabý déšť'], [13, 6, '04d', 'zataženo']] as $i => [$mx, $mn, $ic, $ds]) {
        $days[] = ['date' => $t->modify("+$i day")->format('Y-m-d'), 'max' => $mx, 'min' => $mn, 'icon' => $ic, 'desc' => $ds];
    }
    return ['place' => 'Ukázka', 'now' => ['temp' => 16, 'feels' => 15, 'desc' => 'skoro jasno', 'icon' => '02d', 'humidity' => 62, 'wind' => 3], 'days' => $days];
}

/**
 * Rozvržení prvku Počasí: seznam částí s přesnou polohou (stejně je kreslí server i editor).
 *   ['k' => 'icon', 'x', 'y', 's', 'code']        ikona ve čtverci o straně s
 *   ['k' => 'text', 'x', 'y', 'size', 'text']     text zleva od x, y = účaří
 */
function weather_layout(array $e, array $data, DateTimeImmutable $t): array {
    $font = font_file($e['font'], $e['weight']);
    $pad = $e['bg']['on'] ? $e['bg']['padding'] : 0;
    $ix = $e['x'] + $pad; $iy = $e['y'] + $pad;
    $iw = max(1, $e['w'] - 2 * $pad); $ih = max(1, $e['h'] - 2 * $pad);
    $tw = fn(string $s, float $size) => text_width($s, $size, $font);
    $alignX = fn(float $w, float $x0, float $width) => match ($e['align']) { 'left' => $x0, 'right' => $x0 + $width - $w, default => $x0 + ($width - $w) / 2 };
    $n = $data['now'] ?? [];
    $deg = fn($v) => weather_int((float)$v) . ' °C';
    $parts = [];

    if ($e['look'] === 'mini') {
        // ikona + teplota v jednom řádku
        $s = $ih; $T = $ih * 0.56; $gap = $ih * 0.04;
        $txt = $deg($n['temp'] ?? 0);
        $W = $s + $gap + $tw($txt, $T);
        $k = min(1, $iw / $W);
        $s *= $k; $T = floor($T * $k); $gap *= $k;
        $W = $s + $gap + $tw($txt, $T);
        $x = $alignX($W, $ix, $iw);
        $cy = $iy + $ih / 2;
        $parts[] = ['k' => 'icon', 'x' => $x, 'y' => $cy - $s / 2, 's' => $s, 'code' => (string)($n['icon'] ?? '')];
        $parts[] = ['k' => 'text', 'x' => $x + $s + $gap, 'y' => $cy + $T * 0.36, 'size' => $T, 'text' => $txt];
    } elseif ($e['look'] === 'card') {
        // 1. řádek ikona + velká teplota, 2. popis, 3. pocitově · vlhkost · vítr
        $s = $ih * 0.52; $T1 = $ih * 0.36; $T2 = $ih * 0.13; $T3 = $ih * 0.09;
        $t1 = $deg($n['temp'] ?? 0);
        $t2 = weather_ucfirst((string)($n['desc'] ?? ''));
        $t3 = 'Pocitově ' . $deg($n['feels'] ?? 0) . ' · Vlhkost ' . (int)($n['humidity'] ?? 0) . ' % · Vítr ' . weather_int((float)($n['wind'] ?? 0)) . ' m/s';
        $gap = $ih * 0.03;
        $wid = fn() => max($s + $gap + $tw($t1, $T1), $tw($t2, $T2), $tw($t3, $T3));
        $k = min(1, $iw / max(1, $wid()));
        $s *= $k; $T1 = floor($T1 * $k); $T2 = floor($T2 * $k); $T3 = floor($T3 * $k); $gap *= $k;
        $h1 = $s; $h2 = $T2 * 1.3; $h3 = $T3 * 1.3;
        $H = $h1 + $gap + $h2 + $h3;
        $y = $iy + ($ih - $H) / 2;
        $w1 = $s + $gap + $tw($t1, $T1);
        $x1 = $alignX($w1, $ix, $iw);
        $parts[] = ['k' => 'icon', 'x' => $x1, 'y' => $y, 's' => $s, 'code' => (string)($n['icon'] ?? '')];
        $parts[] = ['k' => 'text', 'x' => $x1 + $s + $gap, 'y' => $y + $h1 / 2 + $T1 * 0.36, 'size' => $T1, 'text' => $t1];
        $y += $h1 + $gap;
        $parts[] = ['k' => 'text', 'x' => $alignX($tw($t2, $T2), $ix, $iw), 'y' => $y + $h2 / 2 + $T2 * 0.36, 'size' => $T2, 'text' => $t2];
        $y += $h2;
        $parts[] = ['k' => 'text', 'x' => $alignX($tw($t3, $T3), $ix, $iw), 'y' => $y + $h3 / 2 + $T3 * 0.36, 'size' => $T3, 'text' => $t3];
    } else {
        // předpověď: 3 sloupce (den, ikona, max / min)
        $cols = [];
        for ($i = 0; $i < WEATHER_DAYS; $i++) {
            $d = weather_day($data, $i, $t);
            $cols[] = $d ? [weather_day_label($d['date'], $t), (string)$d['icon'], weather_int((float)$d['max']) . '° / ' . weather_int((float)$d['min']) . '°'] : ['', '', ''];
        }
        $cw = $iw / WEATHER_DAYS;
        $TL = $ih * 0.17; $s = $ih * 0.5; $TT = $ih * 0.18;
        $maxW = 0;
        foreach ($cols as [$l, , $tt]) $maxW = max($maxW, $tw($l, $TL), $tw($tt, $TT));
        $k = min(1, $cw * 0.92 / max(1, $maxW, $s));
        $TL = floor($TL * $k); $s *= $k; $TT = floor($TT * $k);
        $hL = $TL * 1.3; $hT = $TT * 1.3;
        $H = $hL + $s + $hT;
        $y0 = $iy + ($ih - $H) / 2;
        foreach ($cols as $i => [$l, $ic, $tt]) {
            $cx = $ix + $cw * $i + $cw / 2;
            if ($l === '') continue;
            $parts[] = ['k' => 'text', 'x' => $cx - $tw($l, $TL) / 2, 'y' => $y0 + $hL / 2 + $TL * 0.36, 'size' => $TL, 'text' => $l];
            $parts[] = ['k' => 'icon', 'x' => $cx - $s / 2, 'y' => $y0 + $hL, 's' => $s, 'code' => $ic];
            $parts[] = ['k' => 'text', 'x' => $cx - $tw($tt, $TT) / 2, 'y' => $y0 + $hL + $s + $hT / 2 + $TT * 0.36, 'size' => $TT, 'text' => $tt];
        }
    }
    foreach ($parts as &$p) {
        if ($p['k'] === 'text') $p['w'] = $tw($p['text'], $p['size']); // šířka podle serveru (editor podle ní dorovná písmo)
        foreach (['x', 'y', 's', 'size', 'w'] as $f) if (isset($p[$f])) $p[$f] = round($p[$f], 1);
    }
    unset($p);
    return $parts;
}

/** Vyhodnocení prvku pro design_eval: jen části k vykreslení (čas stažení ani chyba nemění obrázek, slide se kvůli nim nepřegeneruje). */
function weather_eval(array $e, DateTimeImmutable $t): array {
    [$data] = weather_get($e['place']);
    return ['parts' => $data ? weather_layout($e, $data, $t) : []];
}

/** Stav prvku pro editor: místo, čas stažení, chyba; bez dat ukázkové rozvržení (sample). */
function weather_editor_info(array $e, DateTimeImmutable $t): array {
    [$data, $err] = weather_get($e['place']);
    $r = ['place' => weather_place($e['place']), 'error' => $err, 'at' => $data['at'] ?? null, 'sample' => !$data];
    if (!$data) $r['parts'] = weather_layout($e, weather_sample($t), $t);
    return $r;
}

function draw_weather($im, array $e, array $parts): void {
    if (!$parts) return;
    if ($e['bg']['on']) gd_rrect($im, $e['x'], $e['y'], $e['x'] + $e['w'], $e['y'] + $e['h'], $e['bg']['radius'], gd_color($im, $e['bg']['color'], $e['bg']['alpha'] * $e['alpha']));
    $font = font_file($e['font'], $e['weight']);
    $col = gd_color($im, $e['color'], $e['alpha']);
    foreach ($parts as $p) {
        if ($p['k'] === 'icon') {
            $f = $p['code'] !== '' ? weather_icon_file($p['code']) : null;
            $src = $f ? @imagecreatefrompng($f) : null;
            if ($src) {
                imagealphablending($src, true); imagesavealpha($src, true);
                draw_fit($im, $src, $p['x'], $p['y'], $p['s'], $p['s'], 'contain', $e['alpha']);
            }
        } else {
            if ($e['shadow']['on']) gd_line($im, $p['size'] * 0.75, $p['x'] + $e['shadow']['dx'], $p['y'] + $e['shadow']['dy'], gd_color($im, $e['shadow']['color'], $e['shadow']['alpha'] * $e['alpha']), $font, $p['text']);
            gd_line($im, $p['size'] * 0.75, $p['x'], $p['y'], $col, $font, $p['text']);
        }
    }
}

function sanitize_weather_element(array $e): array {
    $sh = $e['shadow'] ?? []; $tb = $e['bg'] ?? [];
    return [
        'look' => in_array($e['look'] ?? '', WEATHER_LOOKS, true) ? $e['look'] : 'mini',
        'place' => mb_substr(trim((string)($e['place'] ?? '')), 0, 100),
        'font' => font_exists((string)($e['font'] ?? '')) ? $e['font'] : 'montserrat',
        'weight' => (int)clampf($e['weight'] ?? 700, 100, 900, 700),
        'color' => hexcolor($e['color'] ?? null, '#ffffff'),
        'align' => in_array($e['align'] ?? '', ['left', 'center', 'right'], true) ? $e['align'] : 'center',
        'shadow' => ['on' => !empty($sh['on']), 'color' => hexcolor($sh['color'] ?? null, '#000000'), 'alpha' => clampf($sh['alpha'] ?? 0.5, 0, 1, 0.5),
                     'dx' => clampf($sh['dx'] ?? 4, -60, 60, 4), 'dy' => clampf($sh['dy'] ?? 4, -60, 60, 4)],
        'bg' => ['on' => !empty($tb['on']), 'color' => hexcolor($tb['color'] ?? null, '#000000'), 'alpha' => clampf($tb['alpha'] ?? 0.35, 0, 1, 0.35),
                 'padding' => clampf($tb['padding'] ?? 32, 0, 300, 32), 'radius' => clampf($tb['radius'] ?? 24, 0, 500, 24)],
    ];
}
