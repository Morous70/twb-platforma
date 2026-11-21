<?php
declare(strict_types=1);
// Načtení náhledu odkazu (Open Graph: og:title, og:description, og:image) – stejné údaje používá Facebook.

/**
 * Profily stahování. Některé weby (např. eBay za Akamai) shodí neznámého robota
 * uprostřed HTTP/2 spojení („stream was not closed cleanly“) nebo vrátí 403.
 * Proto se při chybě zkusí další profil: HTTP/1.1 a hlavičky jako u Facebooku, pak jako u prohlížeče.
 */
const FETCH_PROFILES = [
    ['ua' => 'Mozilla/5.0 (compatible; PlanovacPrispevku/1.0; facebookexternalhit/1.1)', 'http' => 0, 'headers' => []],
    ['ua' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', 'http' => CURL_HTTP_VERSION_1_1,
     'headers' => ['Accept: text/html,application/xhtml+xml,*/*;q=0.8', 'Accept-Language: cs,en;q=0.8,de;q=0.6']],
    ['ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36', 'http' => CURL_HTTP_VERSION_1_1,
     'headers' => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                   'Accept-Language: cs-CZ,cs;q=0.9,en;q=0.8,de;q=0.7', 'Upgrade-Insecure-Requests: 1']],
];

/**
 * Stáhne URL (max. $max bajtů), ručně sleduje přesměrování a odmítá interní adresy (ochrana SSRF).
 * $headOnly: stačí hlavička HTML (meta značky) – stahování skončí za </head>.
 */
function fetch_url(string $url, int $max = 2_000_000, int $timeout = 12, bool $headOnly = false): array {
    $err = null;
    foreach (FETCH_PROFILES as $n => $prof) {
        try {
            return fetch_url_once($url, $max, $timeout, $prof, $headOnly);
        } catch (FetchRetry $e) {
            $err = $e;   // síťová chyba nebo blokace robota → zkusit další profil
            if ($n > 0) log_line('Náhled odkazu: ' . (parse_url($url, PHP_URL_HOST) ?: $url) . ' – ' . $e->getMessage());
        }
    }
    throw new RuntimeException($err->getMessage());
}

/** Chyba, po které má smysl zkusit jiný profil stahování. */
class FetchRetry extends RuntimeException {}

function fetch_url_once(string $url, int $max, int $timeout, array $prof, bool $headOnly): array {
    for ($i = 0; $i < 5; $i++) {
        $p = parse_url($url);
        if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) throw new RuntimeException('Neplatná adresa.');
        $ip = gethostbyname($p['host']);
        if ($ip === $p['host'] && !filter_var($ip, FILTER_VALIDATE_IP)) throw new RuntimeException('Doménu se nepodařilo najít.');
        if (!cfg('fetch_allow_private') && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('Adresa není povolena.');
        $body = ''; $headers = []; $enough = false;
        $ch = curl_init($url);
        $opt = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT => $prof['ua'],
            CURLOPT_ENCODING => '',   // gzip/br podle toho, co umí cURL
            CURLOPT_RESOLVE => [$p['host'] . ':' . ($p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80)) . ':' . $ip],
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$headers) {
                if (preg_match('#^HTTP/#i', $h)) $headers = [];   // nová odpověď (např. po 100 Continue)
                $x = explode(':', $h, 2); if (count($x) === 2) $headers[strtolower(trim($x[0]))] = trim($x[1]);
                return strlen($h);
            },
            CURLOPT_WRITEFUNCTION => function ($c, $d) use (&$body, &$enough, $max, $headOnly) {
                $body .= $d;
                if (strlen($body) > $max || ($headOnly && stripos($body, '</head>') !== false)) { $enough = true; return 0; }
                return strlen($d);
            },
        ];
        if ($prof['headers']) $opt[CURLOPT_HTTPHEADER] = $prof['headers'];
        if ($prof['http']) $opt[CURLOPT_HTTP_VERSION] = $prof['http'];
        curl_setopt_array($ch, $opt);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($ok === false && !$enough) throw new FetchRetry('Stránku se nepodařilo načíst: ' . curl_error($ch));
        if ($code >= 300 && $code < 400 && !empty($headers['location'])) {
            $url = linkmeta_abs($headers['location'], $url);
            continue;
        }
        if (in_array($code, [403, 405, 406, 429, 503], true)) throw new FetchRetry("Server odpověděl chybou $code.");
        if ($code >= 400) throw new RuntimeException("Server odpověděl chybou $code.");
        return ['body' => $body, 'type' => strtolower($headers['content-type'] ?? ''), 'url' => $url];
    }
    throw new RuntimeException('Příliš mnoho přesměrování.');
}

/** Převede relativní adresu na absolutní vůči $base. */
function linkmeta_abs(string $u, string $base): string {
    if (preg_match('#^https?://#i', $u)) return $u;
    $b = parse_url($base);
    $root = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($u, '//')) return $b['scheme'] . ':' . $u;
    if (str_starts_with($u, '/')) return $root . $u;
    $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
    return $root . $dir . $u;
}

/** Vrací ['title','description','image','site','url'] z HTML stránky. */
function link_meta(string $url): array {
    try {
        return parse_meta(fetch_url($url, 3_000_000, 12, true));
    } catch (RuntimeException $e) {
        // Weby za ochranou proti robotům (eBay, Amazon…) často blokují celé sítě hostingů.
        // Facebook je pustí, takže si náhled necháme načíst od něj (stejný údaj, jaký pak ukáže příspěvek).
        if (preg_match('/Neplatná adresa|Adresa není povolena|Doménu se nepodařilo/', $e->getMessage())) throw $e;
        try {
            $m = link_meta_facebook($url);
            if ($m) return $m;
        } catch (RuntimeException $fb) {
            log_line('Náhled odkazu přes Facebook: ' . $fb->getMessage());
        }
        throw new RuntimeException($e->getMessage() . ' Web nejspíš blokuje automatické načítání; Facebook si náhled při zveřejnění načte sám.');
    }
}

/** Náhled odkazu, jak ho vidí Facebook (Graph API, scrape=true s tokenem aplikace). Null = Facebook nic nenašel. */
function link_meta_facebook(string $url): ?array {
    $id = (string)cfg('app_id', ''); $secret = (string)cfg('app_secret', '');
    if ($id === '' || $secret === '' || str_starts_with($id, 'VASE')) return null;
    $base = rtrim((string)cfg('graph_base', 'https://graph.facebook.com'), '/') . '/' . cfg('graph_version', 'v23.0') . '/';
    $ch = curl_init($base);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_POSTFIELDS => http_build_query(['id' => $url, 'scrape' => 'true', 'access_token' => $id . '|' . $secret]),
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) throw new RuntimeException('Síťová chyba: ' . curl_error($ch));
    $d = json_decode((string)$raw, true);
    if (!is_array($d)) throw new RuntimeException('Neplatná odpověď Graph API.');
    if (isset($d['error'])) throw new RuntimeException(($d['error']['message'] ?? 'Chyba Graph API') . ' (code ' . ($d['error']['code'] ?? '?') . ')');
    $title = trim((string)($d['title'] ?? ''));
    $img = $d['image'][0]['url'] ?? (is_string($d['image'] ?? null) ? $d['image'] : '');
    if ($title === '' && $img === '') return null;
    $final = (string)($d['url'] ?? $url);
    return [
        'title'       => $title,
        'description' => trim((string)($d['description'] ?? '')),
        'image'       => (string)$img,
        'site'        => (string)($d['site_name'] ?? (parse_url($final, PHP_URL_HOST) ?: '')),
        'url'         => $final,
    ];
}

function parse_meta(array $r): array {
    $html = $r['body'];
    $cs = 'utf-8';
    if (preg_match('/charset=([\w-]+)/i', $r['type'], $m) || preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', $html, $m)) $cs = strtolower($m[1]);
    if ($cs !== 'utf-8' && $cs !== 'utf8') {
        $c = false;
        try { $c = @iconv($cs, 'UTF-8//IGNORE', $html); } catch (Throwable $e) { /* neznámé kódování */ }
        if ($c === false) { try { $c = @mb_convert_encoding($html, 'UTF-8', $cs); } catch (Throwable $e) { $c = false; } }
        if ($c !== false) $html = $c;
    }
    $meta = [];
    $title = '';
    if (preg_match_all('/<meta\s[^>]*>/i', $html, $tags)) {
        foreach ($tags[0] as $t) {
            if (!preg_match('/(?:property|name)\s*=\s*["\']([^"\']+)["\']/i', $t, $k)) continue;
            if (!preg_match('/content\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $t, $v)) continue;
            $meta[strtolower($k[1])] ??= html_entity_decode($v[1] !== '' ? $v[1] : ($v[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $img = $meta['og:image'] ?? $meta['og:image:url'] ?? $meta['twitter:image'] ?? '';
    return [
        'title'       => $meta['og:title'] ?? $meta['twitter:title'] ?? $title,
        'description' => $meta['og:description'] ?? $meta['twitter:description'] ?? $meta['description'] ?? '',
        'image'       => $img !== '' ? linkmeta_abs($img, $r['url']) : '',
        'site'        => $meta['og:site_name'] ?? (parse_url($r['url'], PHP_URL_HOST) ?: ''),
        'url'         => $r['url'],
    ];
}
