<?php
declare(strict_types=1);
// Obrázky z volných galerií do Knihovny médií (od 1.8.0, převzato z aplikace Kvízy, src/stockimg.php a src/image.php):
// Wikimedia Commons a Openverse bez registrace, Pixabay a Pexels s vlastním API klíčem.
// Společný tvar výsledku: ['provider', 'id', 'title', 'thumb', 'download', 'width', 'height', 'mime', 'page_url', 'ref', 'credit' => [...]]
// Zásady (stejné jako v Kvízech): obrázek se vždy stáhne k nám (Pixabay hotlinkování zakazuje), autora a licenci
// přebíráme tak, jak je uvádí zdroj, nic si nedomýšlíme, nesvobodné obrázky a licence NC / ND se nenabízejí.
// Testy bez sítě: cfg('stock_fixtures') = [část URL => soubor s odpovědí].

require_once __DIR__ . '/library.php';

const STOCK_PROVIDERS = [
    'commons' => ['name' => 'Wikimedia Commons', 'url' => 'https://commons.wikimedia.org/', 'needs_key' => false],
    'openverse' => ['name' => 'Openverse (Flickr, muzea…)', 'url' => 'https://openverse.org/', 'needs_key' => false],
    'pixabay' => ['name' => 'Pixabay', 'url' => 'https://pixabay.com/', 'needs_key' => true],
    'pexels' => ['name' => 'Pexels', 'url' => 'https://www.pexels.com/', 'needs_key' => true],
];

/** Největší stahovaný soubor ze zdroje (lze přepsat v config.php: stock_max_bytes). */
const STOCK_MAX_BYTES = 60 << 20;

/** Největší hrana stahovaného obrázku. Originály na Commons mají i desítky MB; tohle je s rezervou nad našimi variantami. */
const STOCK_MAX_EDGE = 2000;

/** Poskytovatelé, které lze v téhle instalaci použít (napojení bez klíče, nebo s vyplněným klíčem v Nastavení). */
function stock_providers(): array {
    $out = [];
    foreach (STOCK_PROVIDERS as $k => $p) if (!$p['needs_key'] || stock_key($k) !== '') $out[$k] = $p;
    return $out;
}

/** API klíč poskytovatele (Knihovna médií → Z internetu → Klíče; prázdný = nezapojeno). Používá se jen na serveru. */
function stock_key(string $provider): string {
    if (!in_array($provider, ['pixabay', 'pexels'], true)) return '';
    return trim((string)meta_get($provider . '_key', ''));
}

// ---------------------------------------------------------------- HTTP

/**
 * Stáhne URL a vrátí [tělo, http kód, chyba]. Záměrně bez knihoven: cURL, a když není, stream wrappery.
 *
 * User-Agent posíláme vždy – Wikimedia bez něj (nebo s obecným) vrací 403. Podle jejich politiky má obsahovat
 * kontakt: posíláme adresu téhle instalace, **nikdy e-mail uživatele**.
 * V testovacím režimu lze odpovědi podstrčit ze souborů (viz stock_test_fixture()), aby testy nechodily do sítě.
 */
function stock_http_get(string $url, int $maxBytes = 0, bool $binary = false, array $headers = []): array {
    if (($fx = stock_test_fixture($url)) !== null) return [$fx, 200, ''];
    $ua = 'SocialPlanner/' . APP_VERSION . ' (+' . rtrim((string)cfg('base_url', ''), '/') . ')';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $binary ? 60 : 20,
            CURLOPT_USERAGENT => $ua, CURLOPT_HTTPHEADER => array_merge(['Accept-Encoding: gzip'], $headers), CURLOPT_ENCODING => '',
        ]);
        if ($maxBytes > 0) { // přerušit stahování příliš velkého souboru, ať nevyčerpá paměť
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, fn($r, $dlTotal, $dlNow) => ($dlTotal > $maxBytes || $dlNow > $maxBytes) ? 1 : 0);
        }
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = $body === false ? (curl_errno($ch) === CURLE_ABORTED_BY_CALLBACK ? 'Obrázek je příliš velký.' : 'Spojení se nepodařilo: ' . curl_error($ch)) : '';
        return [$body === false ? '' : $body, $code, $err];
    }
    if (!ini_get('allow_url_fopen')) return ['', 0, 'Server neumí stahovat z internetu (chybí rozšíření cURL i allow_url_fopen).'];
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => "User-Agent: $ua\r\n" . implode('', array_map(fn($h) => $h . "\r\n", $headers)), 'timeout' => $binary ? 60 : 20, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx, 0, $maxBytes > 0 ? $maxBytes + 1 : null);
    if ($body === false) return ['', 0, 'Spojení se nepodařilo.'];
    if ($maxBytes > 0 && strlen($body) > $maxBytes) return ['', 0, 'Obrázek je příliš velký.'];
    $code = 0;
    foreach ($http_response_header ?? [] as $h) if (preg_match('~^HTTP/\S+\s+(\d+)~', $h, $m)) $code = (int)$m[1];
    return [$body, $code ?: 200, ''];
}

/** Stáhne a dekóduje JSON. Vrací [data, chyba]. */
function stock_http_json(string $url): array {
    [$body, $code, $err] = stock_http_get($url);
    if ($err !== '') return [null, $err];
    if ($code !== 200) return [null, 'Zdroj odpověděl chybou ' . $code . '.'];
    $d = json_decode($body, true);
    return is_array($d) ? [$d, ''] : [null, 'Odpověď zdroje nejde přečíst.'];
}

/** Jen pro testy: `stock_fixtures` v config.php mapuje část URL na soubor s odpovědí (testy tak nechodí do sítě). */
function stock_test_fixture(string $url): ?string {
    foreach ((array)cfg('stock_fixtures', []) as $needle => $file) {
        if (str_contains($url, (string)$needle) && is_file($file)) return (string)file_get_contents($file);
    }
    return null;
}

// ---------------------------------------------------------------- společné vstupy

/** Vybere poskytovatele podle vložené URL. Null = neznámý zdroj. */
function stock_provider_for_url(string $url): ?string {
    $u = trim($url);
    if (preg_match('~^https?://([a-z-]+\.)?(wikimedia|wikipedia|wikibooks|wikisource)\.org/~i', $u)) return 'commons';
    if (preg_match('~^https?://([a-z-]+\.)?openverse\.(org|engineering)/~i', $u) || preg_match('~^https?://(www\.)?wordpress\.org/openverse/~i', $u)) return 'openverse';
    if (preg_match('~^https?://([a-z-]+\.)?pixabay\.com/~i', $u)) return 'pixabay';
    if (preg_match('~^https?://([a-z-]+\.)?pexels\.com/~i', $u)) return 'pexels';
    return null;
}

/** Vložená URL → jeden výsledek (viz tvar v hlavičce souboru). Vyhodí RuntimeException s českou hláškou. */
function stock_resolve_url(string $url): array {
    $p = stock_provider_for_url($url);
    if ($p === null) throw new RuntimeException('Tuhle adresu neznám. Vložte odkaz na stránku obrázku z Wikimedia Commons, Openverse, Pixabay nebo Pexels.');
    if (!isset(stock_providers()[$p])) throw new RuntimeException(STOCK_PROVIDERS[$p]['name'] . ' není zapojený – zapnete ho vložením API klíče v okně Z internetu → Klíče Pixabay / Pexels.');
    return match ($p) { 'openverse' => openverse_resolve($url), 'pixabay' => pixabay_resolve($url), 'pexels' => pexels_resolve($url), default => commons_resolve($url) };
}

/** Hledání u daného poskytovatele. */
function stock_search(string $provider, string $query, int $limit = 24): array {
    if (!isset(STOCK_PROVIDERS[$provider])) throw new RuntimeException('Tento zdroj obrázků není k dispozici.');
    if (!isset(stock_providers()[$provider])) throw new RuntimeException(STOCK_PROVIDERS[$provider]['name'] . ' není zapojený – vložte API klíč (odkaz „Klíče Pixabay / Pexels“ v okně Z internetu).');
    return match ($provider) { 'openverse' => openverse_search($query, $limit), 'pixabay' => pixabay_search($query, $limit), 'pexels' => pexels_search($query, $limit), default => commons_search($query, $limit) };
}

/**
 * Stáhne obrázek z výsledku do Knihovny médií: převod na JPEG (nejvýš 2 048 px, průhlednost na bílé pozadí, jako u nahrávání),
 * název, tagy, autor a licence ze zdroje. Stejný obrázek (podle stránky u zdroje) se podruhé nestahuje.
 * Vrací [id v knihovně, true = už tam byl].
 */
function stock_import(PDO $pdo, array $hit, array $tags = []): array {
    if ($hit['page_url'] !== '') {
        $q = $pdo->prepare("SELECT id FROM library WHERE source_url = ?"); $q->execute([$hit['page_url']]);
        if ($id = $q->fetchColumn()) return [(int)$id, true];
    }
    [$body, $code, $err] = stock_http_get($hit['download'], (int)cfg('stock_max_bytes', STOCK_MAX_BYTES), true);
    if ($err !== '') throw new RuntimeException($err);
    if ($code !== 200 || $body === '') throw new RuntimeException('Obrázek se nepodařilo stáhnout (kód ' . $code . ').');
    $fn = bin2hex(random_bytes(12)) . '.jpg';
    stock_save_jpeg($body, PUBLIC_DIR . '/uploads/' . $fn);
    $title = $hit['title'] !== '' ? $hit['title'] : 'Obrázek';
    library_register($pdo, $fn, 'image', $title . '.jpg', $tags);
    $pdo->prepare("UPDATE library SET credit = ?, source_url = ? WHERE file = ?")
        ->execute([img_credit_from($hit['credit']), $hit['page_url'] !== '' ? mb_substr($hit['page_url'], 0, 500) : null, $fn]);
    $q = $pdo->prepare("SELECT id FROM library WHERE file = ?"); $q->execute([$fn]);
    return [(int)$q->fetchColumn(), false];
}

/** Stažená data → JPEG (delší hrana nejvýš 2 048 px, kvalita 90). Jiný soubor než obrázek odmítne. */
function stock_save_jpeg(string $data, string $path): void {
    $info = @getimagesizefromstring($data);
    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) throw new RuntimeException('Stažený soubor není obrázek v podporovaném formátu (JPG, PNG, GIF, WebP).');
    if ($info[0] * $info[1] > 60_000_000) throw new RuntimeException('Obrázek má příliš mnoho pixelů.');
    @ini_set('memory_limit', '512M');
    $src = @imagecreatefromstring($data);
    if (!$src) throw new RuntimeException('Obrázek nejde otevřít.');
    $w = imagesx($src); $h = imagesy($src);
    $k = min(1, 2048 / max($w, $h));
    $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    if (!imagejpeg($dst, "$path.tmp", 90)) throw new RuntimeException('Obrázek se nepodařilo uložit.');
    rename("$path.tmp", $path);
}

// ---------------------------------------------------------------- Wikimedia Commons

const COMMONS_API = 'https://commons.wikimedia.org/w/api.php';

/** Z URL (stránka souboru, originál, náhled i lokální „Soubor:“ na Wikipedii) vytáhne název „File:…“. */
function commons_title_from_url(string $url): ?string {
    $url = trim($url);
    if ($url === '') return null;
    $dec = fn(string $s): string => str_replace('_', ' ', rawurldecode($s));
    // /wiki/File:Foo.jpg, /wiki/Soubor:Foo.jpg, /wiki/Datei:Foo.jpg …
    if (preg_match('~/wiki/([^:/?#]+):([^?#]+)$~', $url, $m)) return 'File:' . $dec($m[2]);
    // /thumb/a/ab/Foo.jpg/640px-Foo.jpg → pravý název je segment před náhledem
    if (preg_match('~/thumb/[0-9a-f]/[0-9a-f]{2}/([^/]+)/~', $url, $m)) return 'File:' . $dec($m[1]);
    // /wikipedia/commons/a/ab/Foo.jpg
    if (preg_match('~upload\.wikimedia\.org/.+/[0-9a-f]/[0-9a-f]{2}/([^/?#]+)~', $url, $m)) return 'File:' . $dec($m[1]);
    // ?title=File:Foo.jpg nebo ?curid=… (na curid API neumí přímo, to odmítneme)
    if (preg_match('~[?&]title=([^&#]+)~', $url, $m)) {
        $t = $dec($m[1]);
        return str_contains($t, ':') ? 'File:' . substr($t, strpos($t, ':') + 1) : null;
    }
    return null;
}

/** Jeden obrázek z Commons podle vložené URL. */
function commons_resolve(string $url): array {
    $title = commons_title_from_url($url);
    if ($title === null) throw new RuntimeException('Z téhle adresy nejde poznat, o který soubor jde. Zkuste odkaz na stránku souboru (…/wiki/File:…).');
    $api = COMMONS_API . '?' . http_build_query([
        'action' => 'query', 'titles' => $title, 'prop' => 'imageinfo',
        'iiprop' => 'url|extmetadata|size|mime', 'iiurlwidth' => STOCK_MAX_EDGE,
        'format' => 'json', 'formatversion' => 2, 'origin' => '*',
    ]);
    [$d, $err] = stock_http_json($api);
    if ($err !== '') throw new RuntimeException($err);
    $page = $d['query']['pages'][0] ?? null;
    if (!$page || !empty($page['missing'])) throw new RuntimeException('Soubor na Wikimedia Commons neexistuje (nebo byl přejmenovaný).');
    $hits = commons_hits([$page]);
    if (!$hits) throw new RuntimeException('Tenhle soubor nejde použít (není to obrázek, nebo není pod svobodnou licencí).');
    return $hits[0];
}

/** Hledání obrázků na Commons (jen bitmapy, bez videí a dokumentů). */
function commons_search(string $query, int $limit = 24): array {
    $query = trim($query);
    if ($query === '') return [];
    $api = COMMONS_API . '?' . http_build_query([
        'action' => 'query', 'generator' => 'search', 'gsrsearch' => 'filetype:bitmap ' . $query,
        'gsrnamespace' => 6, 'gsrlimit' => max(1, min(50, $limit)), 'prop' => 'imageinfo',
        'iiprop' => 'url|extmetadata|size|mime', 'iiurlwidth' => 320,
        'format' => 'json', 'formatversion' => 2, 'origin' => '*',
    ]);
    [$d, $err] = stock_http_json($api);
    if ($err !== '') throw new RuntimeException($err);
    return commons_hits($d['query']['pages'] ?? []);
}

/** Odpověď API → naše výsledky. Přeskočí nepoužitelné (nesvobodné, neobrázkové). */
function commons_hits(array $pages): array {
    $out = [];
    foreach ($pages as $p) {
        $ii = $p['imageinfo'][0] ?? null;
        if (!$ii) continue;
        $ex = $ii['extmetadata'] ?? [];
        $val = fn(string $k): string => commons_plain((string)($ex[$k]['value'] ?? ''));
        if (strcasecmp($val('NonFree'), 'true') === 0) continue;         // nesvobodné (lokální soubory wikipedií)
        if (!str_starts_with((string)($ii['mime'] ?? ''), 'image/')) continue;
        $name = preg_replace('~^File:~i', '', (string)($p['title'] ?? ''));
        $title = (string)($ex['ObjectName']['value'] ?? '');
        $title = commons_plain($title) !== '' ? commons_plain($title) : pathinfo($name, PATHINFO_FILENAME);
        // „Attribution“ má podle dokumentace nahradit Artist + Credit, je-li vyplněné
        $attribution = $val('Attribution');
        $author = $attribution !== '' ? $attribution : $val('Artist');
        [$license, $licenseOther] = commons_license($val('License'), $val('LicenseShortName'));
        if ($license === '') continue; // licenci nedokážeme určit → radši nenabízet, než ji domýšlet
        $out[] = [
            'provider' => 'commons',
            'ref' => (string)($ii['descriptionurl'] ?? ''),
            'id' => (string)($p['title'] ?? ''),
            'title' => mb_substr($title, 0, 200),
            'thumb' => (string)($ii['thumburl'] ?? $ii['url'] ?? ''),
            'download' => (string)($ii['thumburl'] ?? $ii['url'] ?? ''), // náhled v STOCK_MAX_EDGE, ne originál (bývá i desítky MB)
            'width' => (int)($ii['thumbwidth'] ?? $ii['width'] ?? 0),
            'height' => (int)($ii['thumbheight'] ?? $ii['height'] ?? 0),
            'mime' => (string)($ii['mime'] ?? ''),
            'page_url' => (string)($ii['descriptionurl'] ?? ''),
            'credit' => [
                'author' => mb_substr($author, 0, 120),
                'source' => 'Wikimedia Commons',
                'source_url' => (string)($ii['descriptionurl'] ?? ''),
                'license' => $license,
                'license_other' => $licenseOther,
                'license_url' => $val('LicenseUrl'),
            ],
        ];
    }
    return $out;
}

/**
 * Hodnoty z extmetadata bývají HTML (odkazy, span s jazykem, i vnořené obrázky) – uděláme z nich čistý text.
 */
function commons_plain(string $html): string {
    if ($html === '') return '';
    $t = preg_replace('~<br\s*/?>|</(p|div|li)>~i', ' ', $html);
    $t = html_entity_decode(strip_tags((string)$t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string)preg_replace('~\s+~u', ' ', $t));
}

/**
 * Licence z Commons → náš výběr (IMG_LICENSES) + doplňkové pole (verze u CC, přesný název u „Jiné“).
 * $key je strojový klíč (např. „cc-by-sa-4.0“), $short čitelný název („CC BY-SA 4.0“).
 * Vrací ['', ''], když licenci nedokážeme určit.
 */
function commons_license(string $key, string $short): array {
    $k = strtolower(trim($key));
    $s = trim($short);
    if ($k === '' && $s === '') return ['', ''];
    // bez strojového klíče ho odvodíme z čitelného názvu („CC BY-SA 3.0“ → „cc-by-sa-3.0“)
    if ($k === '' && preg_match('~^cc[ -]by(?:[ -]sa)?(?:\s+[0-9.]+)?~i', $s)) $k = strtolower((string)preg_replace('~\s+~', '-', $s));
    if (preg_match('~^cc-by-sa(?:-([0-9.]+))?~', $k, $m)) return ['cc-by-sa', $m[1] ?? commons_version($s)];
    if (preg_match('~^cc-by(?:-([0-9.]+))?~', $k, $m)) return ['cc-by', $m[1] ?? commons_version($s)];
    if (str_starts_with($k, 'cc0') || str_starts_with($k, 'pd') || $k === 'publicdomain'
        || preg_match('~^(cc0|public domain|volné dílo)~ui', $s)) return ['cc0', ''];
    // ostatní svobodné licence (GFDL, FAL, vlastní povolení…) – uložíme pod „Jiné“ s původním názvem
    return ['jine', mb_substr($s !== '' ? $s : $key, 0, 120)];
}

/** Vytáhne verzi licence z čitelného názvu („CC BY-SA 4.0“ → „4.0“). */
function commons_version(string $short): string {
    return preg_match('~([0-9]+\.[0-9]+)~', $short, $m) ? $m[1] : '';
}

// ---------------------------------------------------------------- Openverse (WordPress.org – Flickr, Wikimedia, muzea…)

const OPENVERSE_API = 'https://api.openverse.org/v1/images/';
/**
 * Bereme jen licence, které dovolují úpravu i běžné použití: obrázky vždy ořezáváme (ND by to zakazovala)
 * a o „nekomerčnosti“ použití kvízu nevíme nic (NC radši vynecháváme).
 */
const OPENVERSE_LICENSES = 'by,by-sa,cc0,pdm';

/** ID obrázku (UUID) z odkazu na openverse.org (i s jazykem v cestě) nebo z adresy API. */
function openverse_id_from_url(string $url): ?string {
    return preg_match('~/(?:image|images)/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})~i', $url, $m) ? strtolower($m[1]) : null;
}

function openverse_resolve(string $url): array {
    $id = openverse_id_from_url($url);
    if ($id === null) throw new RuntimeException('Z téhle adresy nejde poznat, o který obrázek na Openverse jde. Zkopírujte odkaz ze stránky obrázku (…/image/…).');
    [$d, $err] = stock_http_json(OPENVERSE_API . $id . '/');
    if ($err !== '') throw new RuntimeException($err);
    $hits = openverse_hits([$d]);
    if (!$hits) throw new RuntimeException('Tenhle obrázek nejde použít – jeho licence nedovoluje úpravy nebo běžné použití (např. „nekomerční“ či „bez úprav“).');
    // obrázky z Wikimedia Commons má Openverse jen jako odkaz na originál (často desítky MB) – stáhneme je přes Commons
    // (náhled v rozumné velikosti, autor a licence přímo ze zdroje, deduplikace se stejným obrázkem vloženým z Commons)
    if (preg_match('~^https?://upload\.wikimedia\.org/~i', $hits[0]['download'])) {
        try { return commons_resolve($hits[0]['download']); } catch (RuntimeException $e) { /* zůstane Openverse */ }
    }
    return $hits[0];
}

function openverse_search(string $query, int $limit = 24): array {
    $query = trim($query);
    if ($query === '') return [];
    [$d, $err] = stock_http_json(OPENVERSE_API . '?' . http_build_query(['q' => $query, 'license' => OPENVERSE_LICENSES, 'page_size' => max(1, min(20, $limit))]));
    if ($err !== '') throw new RuntimeException($err);
    return openverse_hits($d['results'] ?? []);
}

/** Odpověď API → naše výsledky. Licence mimo OPENVERSE_LICENSES (NC, ND…) se nenabídnou. */
function openverse_hits(array $rows): array {
    $map = ['by' => 'cc-by', 'by-sa' => 'cc-by-sa', 'cc0' => 'cc0', 'pdm' => 'cc0'];
    $out = [];
    foreach ($rows as $r) {
        $lic = strtolower((string)($r['license'] ?? ''));
        if (!isset($map[$lic]) || empty($r['url'])) continue;
        $page = (string)($r['foreign_landing_url'] ?? '');
        $src = (string)($r['source'] ?? $r['provider'] ?? '');
        $out[] = [
            'provider' => 'openverse',
            'ref' => 'https://openverse.org/image/' . ($r['id'] ?? ''), // stránka u původního zdroje (Flickr…) se vložit nedá, Openverse ano
            'id' => (string)($r['id'] ?? ''),
            'title' => mb_substr(trim((string)($r['title'] ?? '')) !== '' ? trim((string)$r['title']) : 'obrázek z Openverse', 0, 200),
            'thumb' => (string)($r['thumbnail'] ?? $r['url']),
            'download' => (string)$r['url'],
            'width' => (int)($r['width'] ?? 0), 'height' => (int)($r['height'] ?? 0),
            'mime' => '',
            'page_url' => $page !== '' ? $page : 'https://openverse.org/image/' . ($r['id'] ?? ''),
            'credit' => [
                'author' => mb_substr(trim((string)($r['creator'] ?? '')), 0, 120),
                'source' => ($src !== '' ? ucfirst($src) . ' přes ' : '') . 'Openverse',
                'source_url' => $page,
                'license' => $map[$lic],
                'license_other' => in_array($lic, ['by', 'by-sa'], true) ? (string)($r['license_version'] ?? '') : '',
                'license_url' => (string)($r['license_url'] ?? ''),
            ],
        ];
    }
    return $out;
}

// ---------------------------------------------------------------- Pixabay (potřebuje API klíč v okně Z internetu → Klíče Pixabay / Pexels)

const PIXABAY_API = 'https://pixabay.com/api/';
const PIXABAY_LICENSE_URL = 'https://pixabay.com/service/license-summary/';

/** Číselné ID z odkazu na stránku obrázku (…/photos/kvetina-louka-123456/). */
function pixabay_id_from_url(string $url): ?string {
    return preg_match('~pixabay\.com/(?:[a-z]{2}/)?(?:photos|illustrations|vectors|images|photo)/(?:[^/?#]*-)?(\d{3,})/?~i', $url, $m) ? $m[1] : null;
}

/** Volání API s klíčem. Klíč nesmí uniknout do hlášek ani logu – chyby proto formulujeme sami. */
function pixabay_call(array $params): array {
    $key = stock_key('pixabay');
    if ($key === '') throw new RuntimeException('Pixabay není zapojený – vložte API klíč v okně Z internetu → Klíče Pixabay / Pexels.');
    [$body, $code, $err] = stock_http_get(PIXABAY_API . '?' . http_build_query(['key' => $key] + $params));
    if ($err !== '') throw new RuntimeException($err);
    if ($code === 400 && stripos($body, 'key') !== false) throw new RuntimeException('Pixabay odmítl API klíč – zkontrolujte ho v okně Z internetu → Klíče Pixabay / Pexels.');
    if ($code === 429) throw new RuntimeException('Pixabay: příliš mnoho požadavků za minutu, zkuste to za chvíli.');
    if ($code !== 200) throw new RuntimeException('Pixabay odpověděl chybou ' . $code . '.');
    $d = json_decode($body, true);
    if (!is_array($d)) throw new RuntimeException('Odpověď Pixabay nejde přečíst.');
    return $d;
}

function pixabay_resolve(string $url): array {
    $id = pixabay_id_from_url($url);
    if ($id === null) throw new RuntimeException('Z téhle adresy nejde poznat, o který obrázek na Pixabay jde. Zkopírujte odkaz ze stránky obrázku.');
    $hits = pixabay_hits(pixabay_call(['id' => $id])['hits'] ?? []);
    if (!$hits) throw new RuntimeException('Obrázek na Pixabay nebyl nalezen.');
    return $hits[0];
}

function pixabay_search(string $query, int $limit = 24): array {
    $query = trim($query);
    if ($query === '') return [];
    return pixabay_hits(pixabay_call(['q' => mb_substr($query, 0, 100), 'image_type' => 'all', 'safesearch' => 'true', 'lang' => 'cs', 'per_page' => max(3, min(200, $limit))])['hits'] ?? []);
}

function pixabay_hits(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        if (empty($r['largeImageURL']) && empty($r['webformatURL'])) continue;
        $tags = trim((string)($r['tags'] ?? ''));
        $page = (string)($r['pageURL'] ?? '');
        $out[] = [
            'provider' => 'pixabay',
            'ref' => $page,
            'id' => (string)($r['id'] ?? ''),
            'title' => mb_substr($tags !== '' ? $tags : 'obrázek z Pixabay', 0, 200),
            'thumb' => (string)($r['webformatURL'] ?? $r['previewURL'] ?? ''),
            'download' => (string)($r['largeImageURL'] ?? $r['webformatURL']), // do 1280 px; Pixabay zakazuje hotlinkování – stahujeme k sobě
            'width' => (int)($r['imageWidth'] ?? 0), 'height' => (int)($r['imageHeight'] ?? 0),
            'mime' => '',
            'page_url' => $page,
            'credit' => [
                'author' => mb_substr(trim((string)($r['user'] ?? '')), 0, 120),
                'source' => 'Pixabay', 'source_url' => $page,
                'license' => 'pixabay', 'license_other' => '', 'license_url' => PIXABAY_LICENSE_URL,
            ],
        ];
    }
    return $out;
}

// ---------------------------------------------------------------- Pexels (jen s API klíčem)
// Podmínky API: viditelně odkazovat na Pexels („Výsledky poskytuje Pexels“ v okně hledání) a uvádět fotografa.
// Stahování k sobě nevadí; licence Pexels dovoluje použití i úpravy zdarma.
const PEXELS_API = 'https://api.pexels.com/v1/';
const PEXELS_LICENSE_URL = 'https://www.pexels.com/license/';

/** Číselné ID z odkazu na stránku fotky (…/photo/nazev-123456/, …/cs-cz/foto/…-123456/) nebo na soubor images.pexels.com/photos/123456/… */
function pexels_id_from_url(string $url): ?string {
    if (preg_match('~images\.pexels\.com/photos/(\d+)/~i', $url, $m)) return $m[1];
    return preg_match('~pexels\.com/(?:[a-z]{2}(?:-[a-z]{2})?/)?(?:photo|foto|fotografie|photos)/(?:[^/?#]*-)?(\d{3,})/?(?:[?#]|$)~i', $url, $m) ? $m[1] : null;
}

/** Volání API s klíčem v hlavičce. Klíč nesmí uniknout do hlášek – chyby formulujeme sami. */
function pexels_call(string $path, array $params = []): array {
    $key = stock_key('pexels');
    if ($key === '') throw new RuntimeException('Pexels není zapojený – vložte API klíč v okně Z internetu → Klíče Pixabay / Pexels.');
    [$body, $code, $err] = stock_http_get(PEXELS_API . $path . ($params ? '?' . http_build_query($params) : ''), 0, false, ['Authorization: ' . $key]);
    if ($err !== '') throw new RuntimeException($err);
    if ($code === 401 || $code === 403) throw new RuntimeException('Pexels odmítl API klíč – zkontrolujte ho v okně Z internetu → Klíče Pixabay / Pexels.');
    if ($code === 404) throw new RuntimeException('Fotka na Pexels nebyla nalezena.');
    if ($code === 429) throw new RuntimeException('Pexels: vyčerpaný limit požadavků (200 za hodinu), zkuste to později.');
    if ($code !== 200) throw new RuntimeException('Pexels odpověděl chybou ' . $code . '.');
    $d = json_decode($body, true);
    if (!is_array($d)) throw new RuntimeException('Odpověď Pexels nejde přečíst.');
    return $d;
}

function pexels_resolve(string $url): array {
    $id = pexels_id_from_url($url);
    if ($id === null) throw new RuntimeException('Z téhle adresy nejde poznat, o kterou fotku na Pexels jde. Zkopírujte odkaz ze stránky fotky.');
    $hits = pexels_hits([pexels_call('photos/' . $id)]);
    if (!$hits) throw new RuntimeException('Fotka na Pexels nebyla nalezena.');
    return $hits[0];
}

function pexels_search(string $query, int $limit = 24): array {
    $query = trim($query);
    if ($query === '') return [];
    return pexels_hits(pexels_call('search', ['query' => mb_substr($query, 0, 100), 'per_page' => max(3, min(80, $limit)), 'locale' => 'cs-CZ'])['photos'] ?? []);
}

function pexels_hits(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        $src = (array)($r['src'] ?? []);
        $orig = (string)($src['original'] ?? '');
        if ($orig === '' || empty($r['id'])) continue;
        $w = (int)($r['width'] ?? 0); $h = (int)($r['height'] ?? 0);
        // originály mívají přes 6000 px – necháme si poslat zmenšený (delší hrana STOCK_MAX_EDGE)
        $dl = $orig . (str_contains($orig, '?') ? '&' : '?') . 'auto=compress&cs=tinysrgb&' . ($h > $w ? 'h=' : 'w=') . STOCK_MAX_EDGE;
        $page = (string)($r['url'] ?? ('https://www.pexels.com/photo/' . $r['id'] . '/'));
        $alt = trim((string)($r['alt'] ?? ''));
        $out[] = [
            'provider' => 'pexels',
            'ref' => $page,
            'id' => (string)$r['id'],
            'title' => mb_substr($alt !== '' ? $alt : 'fotka z Pexels', 0, 200),
            'thumb' => (string)($src['medium'] ?? $src['small'] ?? $src['tiny'] ?? $orig),
            'download' => $dl,
            'width' => $w, 'height' => $h,
            'mime' => '',
            'page_url' => $page,
            'credit' => [
                'author' => mb_substr(trim((string)($r['photographer'] ?? '')), 0, 120),
                'source' => 'Pexels', 'source_url' => $page,
                'license' => 'pexels', 'license_other' => '', 'license_url' => PEXELS_LICENSE_URL,
            ],
        ];
    }
    return $out;
}

// ---------------------------------------------------------------- autor a licence (z Kvízů, src/image.php)

const IMG_LICENSES = [
    'cc0'      => 'CC0 (volné dílo)',
    'cc-by'    => 'CC BY',
    'cc-by-sa' => 'CC BY-SA',
    'vlastni'  => 'Vlastní foto',
    'placena'  => 'Placená licence',
    'pixabay'  => 'Pixabay licence',
    'pexels'   => 'Pexels licence',
    'ai'       => 'Vygenerováno AI',
    'jine'     => 'Jiné',
];

/** Vysvětlivka k licenci – jen pro výběr ve formuláři administrace, na veřejné stránce se nezobrazuje. */
const IMG_LICENSE_HINTS = [
    'cc0'      => 'volné dílo, autora uvádět nemusíte',
    'cc-by'    => 'volné k použití, ale musíte uvést autora; verzi (např. 4.0) lze doplnit do pole níže',
    'cc-by-sa' => 'uveďte autora a dílo sdílejte pod stejnou licencí; verzi (např. 4.0) lze doplnit do pole níže',
    'vlastni'  => 'fotka, kterou jste pořídili vy',
    'placena'  => 'koupená fotka / fotobanka',
    'pixabay'  => 'obsah z Pixabay – lze použít i upravit zdarma, autora uvádět nemusíte',
    'pexels'   => 'fotka z Pexels – lze použít i upravit zdarma, uvedení fotografa je vítané',
    'ai'       => 'obsah vytvořený umělou inteligencí, autora obvykle neuvádíte',
    'jine'     => 'název licence vyplňte do pole pod výběrem',
];

/** Licence, u kterých má smysl doplňkové pole pod výběrem (verze u CC, přesný název u Jiné). */
const IMG_LICENSE_WITH_EXTRA = ['cc-by', 'cc-by-sa', 'jine'];

/** Rozbalí uložený JSON na pole se všemi klíči (chybějící doplní prázdným řetězcem). */
function img_credit_decode(?string $json): array {
    $d = $json ? json_decode($json, true) : null;
    $d = is_array($d) ? $d : [];
    return [
        'author' => (string)($d['author'] ?? ''), 'source' => (string)($d['source'] ?? ''),
        'license' => (string)($d['license'] ?? ''), 'license_other' => (string)($d['license_other'] ?? ''),
        // odkazy (od 1.24.0): vyplňují se samy při vložení obrázku z internetu, ručně je zadávat nemusíte
        'source_url' => (string)($d['source_url'] ?? ''), 'license_url' => (string)($d['license_url'] ?? ''),
    ];
}

/** Povolíme jen http(s) odkazy – ostatní schémata (javascript:, data:) by byla v odkazu na veřejné stránce nebezpečná. */
function img_credit_url(string $url): string {
    $url = trim($url);
    if ($url === '' || mb_strlen($url) > 500) return '';
    return preg_match('~^https?://[^\s<>"]+$~i', $url) ? $url : '';
}

function img_credit_from(?array $src): ?string {
    $src = $src ?? [];
    $c = [
        'author' => mb_substr(trim((string)($src['author'] ?? '')), 0, 120),
        'source' => mb_substr(trim((string)($src['source'] ?? '')), 0, 200),
        'license' => (string)($src['license'] ?? ''),
        'license_other' => mb_substr(trim((string)($src['license_other'] ?? '')), 0, 120),
        'source_url' => img_credit_url((string)($src['source_url'] ?? '')),
        'license_url' => img_credit_url((string)($src['license_url'] ?? '')),
    ];
    if (!isset(IMG_LICENSES[$c['license']])) $c['license'] = '';
    if (!in_array($c['license'], IMG_LICENSE_WITH_EXTRA, true)) $c['license_other'] = '';
    if ($c['author'] === '' && $c['source'] === '' && $c['license'] === '') return null;
    return json_encode($c, JSON_UNESCAPED_UNICODE);
}

/**
 * Čitelný popisek „Autor · Zdroj · Licence“ (vynechá prázdné části). Prázdný řetězec, když není co zobrazit.
 * U CC BY / CC BY-SA se připojí volitelná verze (např. „CC BY-SA 4.0“) a automaticky poznámka „(upraveno)“ –
 * aplikace totiž každý nahraný obrázek ořeže/zmenší/převede, takže jde vždy o upravenou verzi originálu.
 */
function img_credit_text(?string $json): string {
    return implode(' · ', array_column(img_credit_parts($json), 'text'));
}

/**
 * Jednotlivé části uvedení („Autor“, „Zdroj“, „Licence“) jako [['text' => …, 'url' => … nebo ''], …].
 * Sdílí je textová i klikací podoba, aby se nemohly rozejít.
 */
function img_credit_parts(?string $json): array {
    $c = img_credit_decode($json);
    $parts = [];
    if ($c['author'] !== '') $parts[] = ['text' => $c['author'], 'url' => ''];
    if ($c['source'] !== '') $parts[] = ['text' => $c['source'], 'url' => $c['source_url']];
    $lic = '';
    if ($c['license'] === 'jine') {
        $lic = $c['license_other'];
    } elseif ($c['license'] !== '') {
        $lic = IMG_LICENSES[$c['license']] ?? '';
        if (in_array($c['license'], ['cc-by', 'cc-by-sa'], true) && $lic !== '') {
            if ($c['license_other'] !== '') $lic .= ' ' . $c['license_other'];
            $lic .= ' (upraveno)';
        }
    }
    if ($lic !== '') $parts[] = ['text' => $lic, 'url' => $c['license_url']];
    return $parts;
}
