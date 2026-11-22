<?php
// Testovací webový server: aplikace TWB Media galerie na vestavěném serveru PHP s vlastní konfigurací a dočasnou složkou dat.
declare(strict_types=1);

final class TestServer
{
    /** @var resource */
    private $proc;
    public readonly string $url;
    public readonly string $data;
    public readonly string $config;

    /** @param array<string, mixed> $cfg */
    public function __construct(array $cfg = [])
    {
        $this->data = docasna('web');
        mkdir($this->data . '/data', 0775, true);
        $port = 0;
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        $this->url = "http://127.0.0.1:$port";
        $this->config = $this->data . '/config.php';
        file_put_contents($this->config, '<?php return ' . var_export($cfg + ['base_url' => $this->url, 'data_dir' => $this->data . '/data', 'debug' => true, 'cron_key' => 'tajny-cron-klic-123', 'mail_to_file' => $this->data . '/mail'], true) . ';');
        $env = getenv() + ['GALERIE_CONFIG' => $this->config];
        $env['GALERIE_CONFIG'] = $this->config;
        $this->proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', KOREN . '/apps/twb-galerie'], [0 => ['pipe', 'r'], 1 => ['file', $this->data . '/server.log', 'a'], 2 => ['file', $this->data . '/server.log', 'a']], $pipes, KOREN . '/apps/twb-galerie', $env);
        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $e, $es, 0.1);
            if ($c) { fclose($c); return; }
            usleep(100000);
        }
        throw new RuntimeException('Testovací server se nespustil.');
    }

    public function zastav(): void
    {
        $st = proc_get_status($this->proc);
        if ($st['running']) {
            if (function_exists('posix_kill')) posix_kill($st['pid'], 15); else proc_terminate($this->proc);
        }
        proc_close($this->proc);
    }

    /** Databáze serveru (pro kontrolu výsledku). */
    public function db(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->data . '/data/galerie.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        return $pdo;
    }
}

/** Prohlížeč: cookies, CSRF z poslední stránky. */
final class Prohlizec
{
    private string $jar;
    public string $csrf = '';
    public string $ua = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
    /** @var list<string> další hlavičky dalšího požadavku */
    public array $hlavicky = [];
    public string $posledniHlavicky = '';

    public function __construct(private TestServer $s) { $this->jar = $s->data . '/cookies-' . bin2hex(random_bytes(3)); }

    /** Soubor s cookies (pro předání přihlášení do Playwrightu). */
    public function jar(): string { return $this->jar; }

    /**
     * @param array<string, mixed>|null $post
     * @return array{code:int, body:string, location:?string, type:string}
     */
    public function jdi(string $cesta, ?array $post = null, bool $csrf = true, ?string $json = null, ?string $telo = null): array
    {
        $ch = curl_init(str_starts_with($cesta, 'http') ? $cesta : $this->s->url . '/' . ltrim($cesta, '/'));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => $this->ua]);
        $hl = $this->hlavicky;
        $this->hlavicky = [];
        if ($json !== null) {
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json]);
            $hl[] = 'Content-Type: application/json';
        } elseif ($telo !== null) {
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $telo]);
            $hl[] = 'Content-Type: text/xml';
        } elseif ($post !== null) {
            if ($csrf && !isset($post['csrf'])) $post['csrf'] = $this->csrf;
            $multipart = (bool)array_filter($post, fn($v) => $v instanceof CURLFile);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $multipart ? $post : http_build_query($post)]);
        }
        if ($hl) curl_setopt($ch, CURLOPT_HTTPHEADER, $hl);
        $r = curl_exec($ch);
        if ($r === false) throw new RuntimeException('Požadavek selhal: ' . curl_error($ch));
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $hlavicky = substr($r, 0, $hs);
        $body = substr($r, $hs);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $loc = preg_match('/^Location:\s*(\S+)/mi', $hlavicky, $m) ? $m[1] : null;
        $type = preg_match('/^Content-Type:\s*([^\r\n;]+)/mi', $hlavicky, $m) ? $m[1] : '';
        $this->posledniHlavicky = $hlavicky;
        if (preg_match('/name="csrf" value="([0-9a-f]+)"/', $body, $m)) $this->csrf = $m[1];
        foreach (['Fatal error', 'Warning:', 'Deprecated:', 'Notice:', 'Uncaught', 'ErrorException'] as $spatne) {
            if (str_contains($body, $spatne)) throw new RuntimeException("Stránka $cesta obsahuje chybu PHP: " . mb_substr(strip_tags($body), 0, 600));
        }
        if ($code >= 500) throw new RuntimeException("Stránka $cesta vrátila HTTP $code: " . mb_substr(strip_tags($body), 0, 600));
        return ['code' => $code, 'body' => $body, 'location' => $loc, 'type' => $type];
    }

    /** POST a sleduj přesměrování (PRG). */
    public function odesli(string $cesta, array $post): array
    {
        $r = $this->jdi($cesta, $post);
        if (in_array($r['code'], [302, 303], true) && $r['location']) return $this->jdi($r['location']) + ['presmerovano' => $r['location']];
        return $r;
    }
}
