<?php
declare(strict_types=1);
namespace Reklamy;

/**
 * Společné kroky veřejných souborů doručování (složka d/): kontext požadavku, zóna, výběr a vykreslení banneru,
 * hlavičky. Doručování nikdy nesmí rozbít stránku, na které je reklama – chyby se zapíšou do protokolu a vrátí se prázdný výsledek.
 */
final class Vydej
{
    public const MAX_ZON = 20;
    /** Izolace HTML banerů: dokument dostane vlastní (prázdný) původ, smí skripty, okna a přechod celé stránky po kliknutí. */
    public const SANDBOX = "sandbox allow-scripts allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation allow-forms";
    public const GIF = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\x00\x00\x00!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";

    public readonly bool $pripraveno;

    public function __construct(public readonly App $app)
    {
        $this->pripraveno = $app->pripravDoruceni();
    }

    /**
     * Kontext z požadavku. Náhled z administrace (podepsaný parametr nahled) se nepočítá.
     * Cookies návštěvníka (frekvence, přiřazení konverzí) jen z prohlížeče – volání ze serveru (parametr ua) je nemá.
     * @param array<string, mixed> $get
     */
    public function kontext(array $get, int $zona = 0): Kontext
    {
        $nahled = isset($get['nahled']) && $this->app->dorucovani()->overPodpis("nahled|$zona", $get['nahled']);
        $k = Kontext::zPozadavku($get, $_SERVER, !$nahled);
        if ($this->cookiesPovolene() && !isset($get['ua'])) $k->nactiCookies($_COOKIE);
        return $k;
    }

    /** Cookies návštěvníků povolené v nastavení (výchozí ano). */
    public function cookiesPovolene(): bool { return $this->pripraveno && $this->app->nastaveni()->dej('visitor_cookies', '1') === '1'; }

    /** Pošle cookies návštěvníka (nový identifikátor, změněná frekvence). Volat před výstupem. */
    public static function ulozCookies(Kontext $k): void
    {
        if (!$k->cookies || headers_sent()) return;
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $o = ['path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => $https ? 'None' : 'Lax'];
        if ($k->novyNavstevnik) setcookie('twbr_v', $k->navstevnik, ['expires' => time() + 365 * 86400] + $o);
        if ($k->zmenaFrekvence) setcookie('twbr_f', $k->cookieFrekvence(), ['expires' => time() + 30 * 86400] + $o);
    }

    /** Podpis náhledu zóny pro administraci. */
    public function podpisNahledu(int $zona): string { return $this->app->dorucovani()->podpis("nahled|$zona"); }

    /** @return ?array<string, mixed> */
    public function zona(mixed $id): ?array
    {
        if (!$this->pripraveno || !is_scalar($id) || !ctype_digit((string)$id)) return null;
        return $this->app->db->q('SELECT * FROM zones WHERE id = ?', [(int)$id])->fetch() ?: null;
    }

    /**
     * Vybere banner pro zónu, započítá zobrazení a vrátí HTML pro stránku.
     * @param list<string> $typy
     * @return array{banner: ?array<string, mixed>, html: string}
     */
    public function proZonu(array $zona, Kontext $k, array $typy = []): array
    {
        $b = $this->app->dorucovani()->vyber($zona, $k, $typy);
        if (!$b) return ['banner' => null, 'html' => Vykresleni::nahradni($zona)];
        $this->app->dorucovani()->zobrazeni((int)$b['id'], (int)$zona['id'], $k, (int)$b['campaign_id']);
        return ['banner' => $b, 'html' => $this->app->vykresleni()->html($b, (int)$zona['id'])];
    }

    /** Veřejné údaje o banneru pro JSON a XML-RPC. @return array<string, mixed> */
    public function udaje(array $b, int $zona): array
    {
        $v = $this->app->vykresleni();
        return [
            'id' => (int)$b['id'], 'kampan' => (int)$b['campaign_id'], 'typ' => (string)$b['type'],
            'sirka' => (int)$b['width'], 'vyska' => (int)$b['height'], 'alt' => (string)($b['alt'] ?? ''),
            'klik' => !empty($b['target_url']) ? $v->klik($b, $zona) : null,
            'obrazek' => in_array($b['type'], Bannery::OBRAZKOVE, true) ? $v->obrazek($b) : null,
            'dokument' => in_array($b['type'], Bannery::OBRAZKOVE, true) ? null : $v->dokument($b, $zona),
        ];
    }

    public static function bezCache(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
    }

    /** CORS pro volání ze stránek s reklamou (i s cookies). */
    public static function cors(): void
    {
        $o = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($o !== '' && preg_match('~^https?://[^/\s]+$~i', $o)) {
            header('Access-Control-Allow-Origin: ' . $o);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        } else {
            header('Access-Control-Allow-Origin: *');
        }
    }

    /** Chyba doručování: do protokolu (jednou za minutu stejná), návštěvník nic nevidí. */
    public function chyba(\Throwable $e, string $kde): void
    {
        try {
            $klic = 'delivery_error_' . substr(md5($kde . $e->getMessage()), 0, 8);
            $n = $this->app->nastaveni();
            if ((int)$n->dej($klic, 0) < time() - 60) {
                $n->uloz($klic, (string)time());
                $this->app->log('error', "Doručování ($kde): " . $e->getMessage());
            }
        } catch (\Throwable) {
        }
    }

    /** Seznam zón z parametru „zony“ (1,2,3) nebo „zona“. @param array<string, mixed> $get @return list<int> */
    public static function zonyZParametru(array $get): array
    {
        $s = is_string($get['zony'] ?? null) ? $get['zony'] : (is_scalar($get['zona'] ?? null) ? (string)$get['zona'] : '');
        $out = [];
        foreach (explode(',', $s) as $c) if (ctype_digit(trim($c))) $out[] = (int)trim($c);
        return array_slice($out, 0, self::MAX_ZON);
    }

    /** Je adresa bezpečná pro přesměrování? */
    public static function http(string $u): bool { return (bool)preg_match('~^https?://[^\s]+$~i', $u); }
}
