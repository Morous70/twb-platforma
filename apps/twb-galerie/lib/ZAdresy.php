<?php
declare(strict_types=1);
namespace MediaGalerie;

/**
 * Stažení souboru z veřejné adresy (API „nahrát z adresy“). Ochrana proti zneužití serveru (SSRF):
 * jen http(s) na portech 80/443, adresa se přeloží a vnitřní, rezervované a místní IP se odmítnou,
 * spojení jde přesně na ověřenou IP (CURLOPT_RESOLVE – bez podvržení DNS), přesměrování nejvýš 3 a každé se ověří znovu,
 * velikost se hlídá během stahování.
 */
final class ZAdresy
{
    public const MAX_PRESMEROVANI = 3;

    /** @var ?callable(string): array{data:string, typ:string, nazev:string} */
    private $stahni;

    /** @param ?callable(string): array{data:string, typ:string, nazev:string} $stahni náhrada stahování (jen testy) */
    public function __construct(private int $maxVelikost, ?callable $stahni = null)
    {
        $this->stahni = $stahni;
    }

    /**
     * Stáhne adresu do souboru $cil. Vrací navržený název souboru (z adresy nebo Content-Disposition, s příponou podle typu).
     */
    public function stahni(string $adresa, string $cil): string
    {
        if ($this->stahni) {
            $v = ($this->stahni)($adresa);
            if (strlen($v['data']) > $this->maxVelikost) throw new Chyba('Soubor na adrese je příliš velký.');
            file_put_contents($cil, $v['data']);
            return self::nazev($v['nazev'] !== '' ? $v['nazev'] : $adresa, $v['typ']);
        }
        if (!function_exists('curl_init')) throw new Chyba('PHP na serveru nemá rozšíření curl.');
        $url = $adresa;
        for ($i = 0; $i <= self::MAX_PRESMEROVANI; $i++) {
            [$host, $port, $ip] = self::over($url);
            $fh = fopen($cil, 'wb');
            if ($fh === false) throw new Chyba('Nelze zapsat dočasný soubor.');
            $hlavicky = [];
            $stazeno = 0;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RESOLVE => ["$host:$port:" . (str_contains($ip, ':') ? "[$ip]" : $ip)],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 300,
                CURLOPT_USERAGENT => App::NAZEV,
                CURLOPT_FILE => $fh,
                CURLOPT_HEADERFUNCTION => function ($ch, string $h) use (&$hlavicky) {
                    if (preg_match('/^HTTP\//', $h)) $hlavicky = [];
                    if (str_contains($h, ':')) { [$k, $v] = explode(':', $h, 2); $hlavicky[strtolower(trim($k))] = trim($v); }
                    return strlen($h);
                },
                CURLOPT_NOPROGRESS => false,
                CURLOPT_XFERINFOFUNCTION => function ($ch, $celkem, $staz) use (&$stazeno) {
                    $stazeno = (int)$staz;
                    return ($celkem > $this->maxVelikost || $staz > $this->maxVelikost) ? 1 : 0;
                },
            ]);
            if (defined('CURLOPT_PROTOCOLS_STR')) curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'http,https');
            $ok = curl_exec($ch);
            $kod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $chyba = curl_error($ch);
            fclose($fh);
            if ($ok === false) {
                @unlink($cil);
                if ($stazeno > $this->maxVelikost || str_contains($chyba, 'callback')) throw new Chyba('Soubor na adrese je příliš velký.');
                throw new Chyba('Stažení se nezdařilo: ' . $chyba);
            }
            if ($kod >= 300 && $kod < 400 && !empty($hlavicky['location'])) {
                $url = self::spoj($url, $hlavicky['location']);
                continue;
            }
            if ($kod !== 200) { @unlink($cil); throw new Chyba("Adresa vrátila HTTP {$kod}."); }
            $nazev = '';
            if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $hlavicky['content-disposition'] ?? '', $m)) $nazev = rawurldecode($m[1]);
            return self::nazev($nazev !== '' ? $nazev : $url, (string)($hlavicky['content-type'] ?? ''));
        }
        @unlink($cil);
        throw new Chyba('Příliš mnoho přesměrování.');
    }

    /**
     * Ověří adresu a vrátí [hostitel, port, IP]. IP musí být veřejná (ne vnitřní síť, localhost, link-local, rezervované rozsahy).
     * @param ?callable(string): list<string> $preklad převod jména na IP (jen testy)
     * @return array{0:string, 1:int, 2:string}
     */
    public static function over(string $url, ?callable $preklad = null): array
    {
        $p = parse_url($url);
        $schema = strtolower((string)($p['scheme'] ?? ''));
        $host = strtolower(trim((string)($p['host'] ?? ''), '[]'));
        if (!in_array($schema, ['http', 'https'], true) || $host === '' || isset($p['user']) || isset($p['pass'])) throw new Chyba('Adresa musí začínat http:// nebo https://.');
        $port = (int)($p['port'] ?? ($schema === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443], true)) throw new Chyba('Adresa smí používat jen běžné porty 80 a 443.');
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($preklad ? $preklad($host) : self::preloz($host));
        if (!$ip) throw new Chyba("Adresu {$host} nejde přeložit.");
        foreach ($ip as $a) {
            if (!self::verejna($a)) throw new Chyba('Adresa vede do vnitřní sítě, takovou stáhnout nejde.');
        }
        return [$host, $port, $ip[0]];
    }

    public static function verejna(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return false;
        // IPv4 zabalená do IPv6 (::ffff:10.0.0.1) a sdílený prostor 100.64/10 PHP filtr nepozná
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) return self::verejna($m[1]);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $n = ip2long($ip);
            if (($n & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) return false;
        }
        return true;
    }

    /** @return list<string> */
    private static function preloz(string $host): array
    {
        $out = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $z) {
            if (!empty($z['ip'])) $out[] = (string)$z['ip'];
            if (!empty($z['ipv6'])) $out[] = (string)$z['ipv6'];
        }
        if (!$out && ($v4 = @gethostbynamel($host))) $out = $v4;
        return $out;
    }

    private static function spoj(string $zaklad, string $kam): string
    {
        if (preg_match('~^https?://~i', $kam)) return $kam;
        $p = parse_url($zaklad);
        $koren = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if (str_starts_with($kam, '/')) return $koren . $kam;
        return $koren . rtrim(dirname($p['path'] ?? '/'), '/') . '/' . $kam;
    }

    /** Název souboru z adresy; když nemá známou příponu, doplní se podle Content-Type. */
    public static function nazev(string $zdroj, string $typ): string
    {
        $nazev = str_contains($zdroj, '://') ? rawurldecode(basename((string)parse_url($zdroj, PHP_URL_PATH))) : $zdroj;
        $nazev = trim((string)preg_replace('/[\/\\\\\x00-\x1F]+/', '_', $nazev));
        if ($nazev === '' || $nazev === '.') $nazev = 'soubor';
        $pripona = strtolower(pathinfo($nazev, PATHINFO_EXTENSION));
        if (!isset(\TWB\Galerie\Galerie::POVOLENE[$pripona])) {
            $typ = strtolower(trim(explode(';', $typ)[0]));
            $podleTypu = array_search($typ, ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
                'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'pdf' => 'application/pdf'], true);
            if ($podleTypu !== false) $nazev .= '.' . $podleTypu;
        }
        return mb_substr($nazev, 0, 200);
    }
}
