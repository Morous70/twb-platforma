<?php
declare(strict_types=1);
// Počasí pro proměnné {{Počasí:teplota}}, {{Počasí:zítra.max}}, {{Počasí:Brno.popis}} (převzato z TWB Plánovače médií).
// Poskytovatel OpenWeather (bezplatné API 2.5: weather + forecast). Místo = název („Praha,CZ“) nebo souřadnice („49.9884,17.4637“).
// Data se ukládají do tabulky weather podle místa; cron je obnovuje každých 15 minut a před zveřejněním příspěvku se
// stáhnou, pokud jsou starší. Při výpadku se použijí poslední stažená data a do logu jde jedno varování.

const WEATHER_INTERVAL_MIN = 15;
const WEATHER_RETRY_MIN = 15;
const WEATHER_KEY_URL = 'https://home.openweathermap.org/api_keys';

function weather_api_key(): string { return trim((string)meta_get('weather_api_key', '')); }
function weather_default_place(): string { return trim((string)meta_get('weather_place', '')); }
function weather_configured(): bool { return weather_api_key() !== ''; }

/** Souřadnice „49.9884,17.4637“ → [lat, lon], jinak null. */
function weather_coords(string $place): ?array {
    if (!preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*[,;]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $place, $m)) return null;
    $lat = (float)$m[1]; $lon = (float)$m[2];
    return abs($lat) <= 90 && abs($lon) <= 180 ? [$lat, $lon] : null;
}
function weather_place_key(string $place): string {
    if ($c = weather_coords($place)) return sprintf('%.4f,%.4f', $c[0], $c[1]);
    $p = mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $place)));
    return mb_substr((string)preg_replace('/\s*,\s*/u', ',', $p), 0, 100);
}
/** Místo zadané v proměnné, jinak výchozí z nastavení. */
function weather_place(?string $place): string {
    $p = trim((string)$place);
    return mb_substr($p !== '' ? $p : weather_default_place(), 0, 100);
}

function weather_row(string $place): ?array {
    $k = weather_place_key($place);
    if ($k === '') return null;
    $q = db()->prepare('SELECT * FROM weather WHERE place_key = ?');
    $q->execute([$k]);
    $r = $q->fetch();
    if (!$r) return null;
    $r['data'] = $r['payload'] ? json_decode($r['payload'], true) : null;
    return $r;
}
function weather_due(?array $row): bool {
    if (!$row || !$row['fetched_at']) return true;
    return time() - (int)$row['fetched_at'] >= ($row['error'] ? WEATHER_RETRY_MIN : WEATHER_INTERVAL_MIN) * 60 - 30;
}

/** Data pro místo z mezipaměti; když chybí nebo jsou stará, stáhnou se hned. Vrací [data|null, chyba|null]. */
function weather_get(string $place, bool $fetch = true): array {
    $mem = &$GLOBALS['WEATHER_MEM'];
    $mem ??= [];
    $place = weather_place($place);
    if ($place === '') return [null, 'Není zadané místo (výchozí místo nastavíte v Proměnné a data → Počasí).'];
    $k = weather_place_key($place);
    if (isset($mem[$k])) return $mem[$k];
    $row = weather_row($place);
    if ($fetch && weather_configured() && weather_due($row)) { weather_fetch($place); $row = weather_row($place); }
    if (!weather_configured() && !$row) return $mem[$k] = [null, 'Chybí API klíč (Proměnné a data → Počasí).'];
    if (!$row || !$row['data']) return $mem[$k] = [null, $row['error'] ?? 'Počasí zatím není stažené.'];
    return $mem[$k] = [$row['data'], $row['error'] ?: null];
}

/** Stáhne počasí pro místo a uloží ho. Vrací ['ok' => bool, 'error' => ?string, 'data' => ?array]. */
function weather_fetch(string $place): array {
    $pdo = db();
    $place = weather_place($place);
    $k = weather_place_key($place);
    if ($k === '') return ['ok' => false, 'error' => 'Není zadané místo.', 'data' => null];
    $now = time();
    $old = weather_row($place);
    unset($GLOBALS['WEATHER_MEM'][$k]);
    try {
        if (!weather_configured()) throw new RuntimeException('Chybí API klíč.');
        $data = ['place' => $place, 'at' => $now] + ow_fetch($place, weather_api_key());
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pdo->prepare('INSERT OR REPLACE INTO weather (place_key, place, payload, fetched_at, ok_at, error) VALUES (?,?,?,?,?,NULL)')->execute([$k, $place, $payload, $now, $now]);
        if ($old && $old['error'] && $old['ok_at']) log_line("Počasí pro „{$place}“ je opět dostupné.");
        return ['ok' => true, 'error' => null, 'data' => $data];
    } catch (Throwable $e) {
        $err = $e->getMessage();
        if ($old) $pdo->prepare('UPDATE weather SET fetched_at = ?, error = ? WHERE place_key = ?')->execute([$now, $err, $k]);
        else $pdo->prepare('INSERT INTO weather (place_key, place, payload, fetched_at, ok_at, error) VALUES (?,?,NULL,?,NULL,?)')->execute([$k, $place, $now, $err]);
        if ($old && $old['ok_at'] && !$old['error']) log_line("Počasí pro „{$place}“ se nepodařilo stáhnout: $err. Příspěvky používají poslední stažená data.");
        return ['ok' => false, 'error' => $err, 'data' => $old['data'] ?? null];
    }
}

/** Místa, pro která se má počasí stahovat: výchozí + z proměnných {{Počasí:Místo.…}} v nezveřejněných příspěvcích a šablonách. */
function weather_places_used(): array {
    $places = [];
    $add = function (string $p) use (&$places) { $p = weather_place($p); if ($p !== '') $places[weather_place_key($p)] ??= $p; };
    if (weather_default_place() !== '') $add('');
    $texts = db()->query("SELECT message || ' ' || COALESCE(first_comment, '') FROM posts WHERE status IN ('draft', 'scheduled', 'failed', 'partial')
                          UNION ALL SELECT message FROM templates")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($texts as $txt) {
        if (preg_match_all('/\{\{\s*po[cč]as[ií]\s*:([^{}|]*)/iu', (string)$txt, $mm)) {
            foreach ($mm[1] as $arg) { $p = weather_parse_arg($arg); if ($p) $add($p['place']); }
        }
    }
    return array_values($places);
}

/** Obnoví počasí, kterému uplynul interval. Volá cron. Stará nepoužívaná místa z mezipaměti smaže. */
function weather_refresh_due(): void {
    if (!weather_configured()) return;
    $used = weather_places_used();
    foreach ($used as $p) if (weather_due(weather_row($p))) weather_fetch($p);
    $keys = array_map('weather_place_key', $used);
    $del = db()->prepare('DELETE FROM weather WHERE place_key = ?');
    foreach (db()->query('SELECT place_key, fetched_at FROM weather')->fetchAll() as $r) {
        if (!in_array($r['place_key'], $keys, true) && time() - (int)$r['fetched_at'] > 86400) $del->execute([$r['place_key']]);
    }
}

// ---------- OpenWeather ----------
function ow_base(): string { return rtrim((string)cfg('openweather_url', 'https://api.openweathermap.org'), '/'); }
function ow_icon_url(string $code): string { return 'https://openweathermap.org/img/wn/' . rawurlencode($code) . '@2x.png'; }

function weather_http(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'SocialPlanner/' . APP_VERSION,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    if ($body === false) throw new RuntimeException('Služba počasí neodpovídá' . ($err !== '' ? ': ' . preg_replace('/appid=[^&\s]+/', 'appid=…', $err) : '') . '.');
    return [$code, (string)$body];
}

function ow_get(string $path, string $place, string $key): array {
    $c = weather_coords($place);
    $q = $c ? ['lat' => $c[0], 'lon' => $c[1]] : ['q' => $place];
    $url = ow_base() . "/data/2.5/$path?" . http_build_query($q + ['appid' => $key, 'units' => 'metric', 'lang' => 'cz']);
    [$code, $body] = weather_http($url);
    $j = json_decode($body, true);
    if ($code === 401) throw new RuntimeException('OpenWeather nepřijal API klíč (nový klíč začne fungovat až do 2 hodin po založení).');
    if ($code === 404) throw new RuntimeException("Místo „{$place}“ OpenWeather nezná. Zkuste např. „Praha,CZ“ nebo souřadnice „49.9884,17.4637“.");
    if ($code === 429) throw new RuntimeException('Překročen limit dotazů OpenWeather.');
    if ($code >= 400 || !is_array($j)) throw new RuntimeException("OpenWeather vrátil chybu HTTP $code" . (is_array($j) && isset($j['message']) ? ': ' . $j['message'] : '') . '.');
    return $j;
}

/** Jednotná data: ['name', 'now' => [temp, feels, desc, icon, humidity, wind], 'days' => [[date, min, max, desc, icon], …]]. */
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
    $days[$today]['min'] = min($days[$today]['min'], $now['temp']);
    $days[$today]['max'] = max($days[$today]['max'], $now['temp']);
    $days[$today]['icon'] = $now['icon'] ?: $days[$today]['icon'];
    $days[$today]['desc'] = $now['desc'] ?: $days[$today]['desc'];
    ksort($days);
    $out = [];
    foreach ($days as $date => $d) { if ($date < $today) continue; unset($d['_dist']); $out[] = $d; }
    return ['name' => (string)($cur['name'] ?? $place), 'now' => $now, 'days' => array_slice($out, 0, 6)];
}

// ---------- hodnoty pro proměnné ----------
const WEATHER_NOW_FIELDS = ['teplota' => 'temp', 'pocitova' => 'feels', 'popis' => 'desc', 'vlhkost' => 'humidity', 'vitr' => 'wind', 'misto' => 'place', 'cas' => 'time'];
const WEATHER_DAY_FIELDS = ['max' => 'max', 'min' => 'min', 'popis' => 'desc', 'den' => 'day', 'datum' => 'date'];
const WEATHER_DAY_KEYS = ['dnes' => 0, 'zitra' => 1, 'pozitri' => 2];

/** Argument {{Počasí:…}}: [místo.]pole nebo [místo.]den.pole → ['place', 'day' (null = teď), 'field'] nebo null. */
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
/** Den předpovědi podle data (den zveřejnění + $n dní). */
function weather_day(array $data, int $n, DateTimeImmutable $t): ?array {
    $date = $t->setTime(12, 0)->modify("+$n day")->format('Y-m-d');
    foreach ($data['days'] ?? [] as $d) if (($d['date'] ?? '') === $date) return $d;
    return null;
}
function weather_day_label(string $date, DateTimeImmutable $t): string {
    if ($date === $t->format('Y-m-d')) return 'Dnes';
    if ($date === $t->modify('+1 day')->format('Y-m-d')) return 'Zítra';
    return weather_ucfirst(DT_DAYS[(int)(new DateTimeImmutable($date))->format('N')]);
}
/** Hodnota {{Počasí:…}}: null = neznámé pole, '' = data zatím nejsou. „Teď“ = poslední stažená data (u zveřejnění čerstvá). */
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
            'place' => weather_coords((string)($data['place'] ?? '')) ? (string)($data['name'] ?? '') : (string)($data['place'] ?? ''),
            'time' => isset($data['at']) ? date('G:i', (int)$data['at']) : '',
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
