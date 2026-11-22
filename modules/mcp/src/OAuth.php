<?php
declare(strict_types=1);
namespace TWB\Mcp;

use TWB\Jadro\{Db, Protokol};

/**
 * OAuth 2.1 autorizační server pro MCP konektory (Claude, ChatGPT):
 *  - dynamická registrace klientů (RFC 7591), jen veřejní klienti bez tajemství,
 *  - authorization code + PKCE S256 (povinné), refresh tokeny s rotací,
 *  - metadata RFC 8414 (autorizační server) a RFC 9728 (chráněný zdroj), zrušení tokenu RFC 7009.
 * Tokeny i kódy se ukládají jen jako SHA-256. Jedno „připojení“ = jeden souhlas uživatele s klientem;
 * jeho odebráním přestanou platit všechny jeho tokeny. Převzato z MCP Social Planneru (oauth.php).
 *
 * Modul nesahá na $_SERVER ani na relaci: souhlas (přihlášení uživatele, stránku) řeší aplikace a zavolá vydejKod().
 */
final class OAuth
{
    public const PRISTUP_TTL = 3600;          // 1 hodina
    public const OBNOVA_TTL = 90 * 86400;      // 90 dní (při každém použití se obnoví)
    public const KOD_TTL = 300;                // 5 minut
    public const MAX_KLIENTU = 100;

    /** @var callable(): int */
    private $ted;

    /**
     * @param string $vydavatel  adresa aplikace bez lomítka na konci (issuer), např. https://galerie.kazbo.eu
     * @param string $zdroj  adresa MCP serveru (resource), např. https://galerie.kazbo.eu/mcp.php
     * @param array{authorize:string, token:string, register:string, revoke:string} $adresy  adresy koncových bodů
     * @param array<string, string> $rozsahy  rozsah => popis pro souhlas; první je vždy udělen (čtení)
     * @param string $predpona  předpona tokenů (access {p}a_, refresh {p}r_, klient {p}c_)
     * @param (callable(): int)|null $ted  čas (testy)
     */
    public function __construct(
        private Db $db,
        private Protokol $protokol,
        private string $vydavatel,
        private string $zdroj,
        private array $adresy,
        private array $rozsahy,
        private string $nazevZdroje,
        private string $predpona = 'mcp',
        private ?string $dokumentace = null,
        ?callable $ted = null,
    ) {
        if (!$rozsahy) throw new \InvalidArgumentException('Zadejte aspoň jeden rozsah oprávnění.');
        foreach (['authorize', 'token', 'register', 'revoke'] as $k) if (!isset($adresy[$k])) throw new \InvalidArgumentException("Chybí adresa koncového bodu „{$k}“.");
        $this->ted = $ted ?? static fn(): int => time();
    }

    private function ted(): int { return ($this->ted)(); }

    public function vydavatel(): string { return $this->vydavatel; }
    public function zdroj(): string { return $this->zdroj; }
    /** @return array<string, string> */
    public function rozsahy(): array { return $this->rozsahy; }

    // ---------- metadata ----------

    /** RFC 8414: /.well-known/oauth-authorization-server @return array<string, mixed> */
    public function metadataServeru(): array
    {
        return [
            'issuer' => $this->vydavatel,
            'authorization_endpoint' => $this->adresy['authorize'],
            'token_endpoint' => $this->adresy['token'],
            'registration_endpoint' => $this->adresy['register'],
            'revocation_endpoint' => $this->adresy['revoke'],
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => array_merge(array_keys($this->rozsahy), ['offline_access']),
        ] + ($this->dokumentace !== null ? ['service_documentation' => $this->dokumentace] : []);
    }

    /** RFC 9728: /.well-known/oauth-protected-resource @return array<string, mixed> */
    public function metadataZdroje(): array
    {
        return [
            'resource' => $this->zdroj,
            'authorization_servers' => [$this->vydavatel],
            'scopes_supported' => array_keys($this->rozsahy),
            'bearer_methods_supported' => ['header'],
            'resource_name' => $this->nazevZdroje,
        ] + ($this->dokumentace !== null ? ['resource_documentation' => $this->dokumentace] : []);
    }

    /** Hlavička WWW-Authenticate pro odpověď 401 (klient z ní najde metadata a spustí přihlášení). */
    public function vyzva(string $adresaMetadat, bool $neplatnyToken = false): string
    {
        return 'Bearer resource_metadata="' . $adresaMetadat . '"'
            . ($neplatnyToken ? ', error="invalid_token", error_description="The access token is invalid or expired"' : '')
            . ', scope="' . implode(' ', array_keys($this->rozsahy)) . '"';
    }

    // ---------- registrace klienta ----------

    /** Povolené návratové adresy: https, nebo http jen na localhost (desktopové aplikace). Bez fragmentu. */
    public static function platnyNavrat(string $u): bool
    {
        $p = parse_url($u);
        if (!$p || empty($p['scheme']) || empty($p['host']) || isset($p['fragment']) || isset($p['user'])) return false;
        if (strtolower($p['scheme']) === 'https') return true;
        return strtolower($p['scheme']) === 'http' && in_array(strtolower($p['host']), ['localhost', '127.0.0.1', '[::1]'], true);
    }

    /** RFC 7591. @param array<string, mixed> $vstup @return array<string, mixed> */
    public function registruj(array $vstup): array
    {
        $navraty = $vstup['redirect_uris'] ?? null;
        if (!is_array($navraty) || !$navraty || count($navraty) > 10 || !array_is_list($navraty)) throw new ChybaOAuth('invalid_redirect_uri', 'redirect_uris: očekává se 1–10 adres.');
        foreach ($navraty as $u) {
            if (!is_string($u) || strlen($u) > 500 || !self::platnyNavrat($u)) throw new ChybaOAuth('invalid_redirect_uri', 'Nepovolená návratová adresa: ' . (is_string($u) ? mb_substr($u, 0, 200) : '?'));
        }
        if ((string)($vstup['token_endpoint_auth_method'] ?? 'none') !== 'none') {
            throw new ChybaOAuth('invalid_client_metadata', 'Podporováni jsou jen veřejní klienti (token_endpoint_auth_method = none).');
        }
        $jmeno = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', is_string($vstup['client_name'] ?? null) ? $vstup['client_name'] : ''));
        $jmeno = mb_substr($jmeno !== '' ? $jmeno : 'MCP klient', 0, 80);
        $ted = $this->ted();
        // úklid: registrace bez připojení starší než den; strop počtu klientů
        $this->db->q('DELETE FROM oauth_clients WHERE created_at < ? AND client_id NOT IN (SELECT client_id FROM oauth_grants WHERE revoked_at IS NULL)', [$ted - 86400]);
        if ((int)$this->db->q('SELECT COUNT(*) FROM oauth_clients')->fetchColumn() >= self::MAX_KLIENTU) {
            throw new ChybaOAuth('temporarily_unavailable', 'Příliš mnoho registrovaných klientů, zkuste to později.', 429);
        }
        $id = $this->predpona . 'c_' . bin2hex(random_bytes(16));
        $this->db->q('INSERT INTO oauth_clients (client_id, name, redirect_uris, created_at) VALUES (?, ?, ?, ?)',
            [$id, $jmeno, json_encode(array_values($navraty), JSON_UNESCAPED_SLASHES), $ted]);
        $this->protokol->zapis('info', "OAuth: registrace klienta „{$jmeno}“.");
        return [
            'client_id' => $id, 'client_id_issued_at' => $ted, 'client_name' => $jmeno,
            'redirect_uris' => array_values($navraty), 'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'],
        ];
    }

    /** @return array{client_id:string, name:string, redirect_uris:list<string>, created_at:int}|null */
    public function klient(string $id): ?array
    {
        if ($id === '' || strlen($id) > 100) return null;
        $k = $this->db->q('SELECT * FROM oauth_clients WHERE client_id = ?', [$id])->fetch();
        if (!$k) return null;
        $k['redirect_uris'] = json_decode((string)$k['redirect_uris'], true) ?: [];
        $k['created_at'] = (int)$k['created_at'];
        return $k;
    }

    // ---------- souhlas a kód ----------

    /**
     * Ověří požadavek na souhlas (authorize). Vrací [klient, návratová adresa, PKCE výzva, rozsah].
     * Neznámý klient nebo návratová adresa = ChybaOAuth s http 400 (aplikace ukáže stránku, nepřesměruje);
     * ostatní chyby mají http 302 (aplikace přesměruje zpět s error).
     * @param array<string, mixed> $p
     * @return array{0:array<string, mixed>, 1:string, 2:string, 3:list<string>}
     */
    public function overPozadavek(array $p): array
    {
        $s = fn(string $k): string => is_string($p[$k] ?? null) ? trim($p[$k]) : '';
        $klient = $this->klient($s('client_id'));
        if (!$klient) throw new ChybaOAuth('invalid_client', 'Neznámý klient. Registrace mohla vypršet – přidejte konektor znovu.');
        $navrat = $s('redirect_uri');
        if ($navrat === '' && count($klient['redirect_uris']) === 1) $navrat = $klient['redirect_uris'][0];
        if (!in_array($navrat, $klient['redirect_uris'], true)) throw new ChybaOAuth('invalid_request', 'Návratová adresa neodpovídá registraci klienta.');
        if ($s('response_type') !== 'code') throw new ChybaOAuth('unsupported_response_type', 'Podporováno je jen response_type=code.', 302);
        $vyzva = $s('code_challenge');
        if ($s('code_challenge_method') !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $vyzva)) {
            throw new ChybaOAuth('invalid_request', 'Vyžaduje se PKCE (code_challenge_method=S256).', 302);
        }
        return [$klient, $navrat, $vyzva, $this->rozsah($s('scope'))];
    }

    /** Normalizuje požadovaný rozsah; první rozsah (čtení) je vždy. Bez scope = vše, co server nabízí. @return list<string> */
    public function rozsah(string $pozadovany): array
    {
        $vse = array_keys($this->rozsahy);
        $r = array_values(array_intersect($vse, preg_split('/\s+/', trim($pozadovany), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        if (!$r) $r = $vse;
        if (!in_array($vse[0], $r, true)) array_unshift($r, $vse[0]);
        return $r;
    }

    /** Po souhlasu: vytvoří připojení a jednorázový kód. Vrací kód (surový). @param array<string, mixed> $klient @param list<string> $rozsah */
    public function vydejKod(array $klient, string $navrat, string $vyzva, array $rozsah): string
    {
        $rozsah = array_values(array_intersect(array_keys($this->rozsahy), $rozsah)) ?: [array_key_first($this->rozsahy)];
        $ted = $this->ted();
        $this->db->q('INSERT INTO oauth_grants (client_id, client_name, redirect_host, scope, created_at, uses) VALUES (?, ?, ?, ?, ?, 0)',
            [$klient['client_id'], $klient['name'], (string)parse_url($navrat, PHP_URL_HOST), implode(' ', $rozsah), $ted]);
        $gid = $this->db->posledniId();
        $kod = bin2hex(random_bytes(32));
        $this->db->q('INSERT INTO oauth_codes (code_hash, grant_id, client_id, redirect_uri, challenge, expires) VALUES (?, ?, ?, ?, ?, ?)',
            [hash('sha256', $kod), $gid, $klient['client_id'], $navrat, $vyzva, $ted + self::KOD_TTL]);
        $this->protokol->zapis('info', "OAuth: povoleno připojení „{$klient['name']}“ (" . implode(' ', $rozsah) . ').');
        return $kod;
    }

    /** Adresa návratu s parametry (code nebo error, state, iss). @param array<string, string> $parametry */
    public function navrat(string $navrat, array $parametry): string
    {
        $parametry = array_filter($parametry, fn($v) => $v !== '') + ['iss' => $this->vydavatel];
        return $navrat . (str_contains($navrat, '?') ? '&' : '?') . http_build_query($parametry);
    }

    // ---------- tokeny ----------

    /** Token endpoint (grant_type authorization_code nebo refresh_token). @param array<string, mixed> $vstup @return array<string, mixed> */
    public function token(array $vstup): array
    {
        $s = fn(string $k): string => is_string($vstup[$k] ?? null) ? $vstup[$k] : '';
        $druh = $s('grant_type');
        $klientId = $s('client_id');
        $ted = $this->ted();
        if ($druh === 'authorization_code') {
            $c = $this->db->q('SELECT * FROM oauth_codes WHERE code_hash = ?', [hash('sha256', $s('code'))])->fetch();
            if ($c) $this->db->q('DELETE FROM oauth_codes WHERE code_hash = ?', [$c['code_hash']]);   // kód jen jednou
            if (!$c || (int)$c['expires'] < $ted) throw new ChybaOAuth('invalid_grant', 'Kód je neplatný nebo vypršel.');
            if ($klientId !== '' && $klientId !== $c['client_id']) throw new ChybaOAuth('invalid_grant', 'Kód patří jinému klientovi.');
            if (($vstup['redirect_uri'] ?? $c['redirect_uri']) !== $c['redirect_uri']) throw new ChybaOAuth('invalid_grant', 'redirect_uri nesouhlasí.');
            $overovac = $s('code_verifier');
            $spocteno = rtrim(strtr(base64_encode(hash('sha256', $overovac, true)), '+/', '-_'), '=');
            if ($overovac === '' || !hash_equals((string)$c['challenge'], $spocteno)) throw new ChybaOAuth('invalid_grant', 'PKCE: code_verifier nesouhlasí.');
            $g = $this->pripojeniPodleId((int)$c['grant_id']) ?? throw new ChybaOAuth('invalid_grant', 'Připojení bylo zrušeno.');
            // opakované „Připojit“ ze stejné aplikace: starší připojení téže aplikace zrušit (až teď, kdy nové opravdu funguje)
            $stara = $this->db->q('SELECT id FROM oauth_grants WHERE revoked_at IS NULL AND id <> ? AND client_name = ? AND redirect_host = ?',
                [$g['id'], $g['client_name'], $g['redirect_host']])->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($stara as $old) $this->zrusPripojeni((int)$old, false);
            return $this->vydejTokeny((int)$g['id'], (string)$c['client_id'], (string)$g['scope']);
        }
        if ($druh === 'refresh_token') {
            $t = $this->db->q("SELECT * FROM oauth_tokens WHERE token_hash = ? AND kind = 'refresh'", [hash('sha256', $s('refresh_token'))])->fetch();
            if (!$t || (int)$t['expires'] < $ted) throw new ChybaOAuth('invalid_grant', 'Refresh token je neplatný nebo vypršel.');
            if ($klientId !== '' && $klientId !== $t['client_id']) throw new ChybaOAuth('invalid_grant', 'Token patří jinému klientovi.');
            $this->db->q('DELETE FROM oauth_tokens WHERE token_hash = ?', [$t['token_hash']]);   // rotace
            $g = $this->pripojeniPodleId((int)$t['grant_id']) ?? throw new ChybaOAuth('invalid_grant', 'Připojení bylo zrušeno.');
            return $this->vydejTokeny((int)$g['id'], (string)$t['client_id'], (string)$g['scope']);
        }
        throw new ChybaOAuth('unsupported_grant_type', 'Podporováno: authorization_code, refresh_token.');
    }

    /** @return array<string, mixed> */
    private function vydejTokeny(int $gid, string $klientId, string $rozsah): array
    {
        $ted = $this->ted();
        $pristup = $this->predpona . 'a_' . bin2hex(random_bytes(32));
        $obnova = $this->predpona . 'r_' . bin2hex(random_bytes(32));
        $this->db->q('INSERT INTO oauth_tokens (token_hash, kind, grant_id, client_id, expires) VALUES (?, ?, ?, ?, ?)', [hash('sha256', $pristup), 'access', $gid, $klientId, $ted + self::PRISTUP_TTL]);
        $this->db->q('INSERT INTO oauth_tokens (token_hash, kind, grant_id, client_id, expires) VALUES (?, ?, ?, ?, ?)', [hash('sha256', $obnova), 'refresh', $gid, $klientId, $ted + self::OBNOVA_TTL]);
        $this->db->q('DELETE FROM oauth_tokens WHERE expires < ?', [$ted]);
        $this->db->q('DELETE FROM oauth_codes WHERE expires < ?', [$ted]);
        return ['access_token' => $pristup, 'token_type' => 'Bearer', 'expires_in' => self::PRISTUP_TTL, 'refresh_token' => $obnova, 'scope' => $rozsah];
    }

    /** Je to přístupový token tohoto serveru (podle předpony)? */
    public function jeToken(string $token): bool { return str_starts_with($token, $this->predpona . 'a_'); }

    /** Ověří přístupový token; vrací připojení (s polem scopes), nebo null. Čas použití se zapíše nejvýš jednou za minutu. @return array<string, mixed>|null */
    public function over(string $token): ?array
    {
        if ($token === '' || strlen($token) > 200) return null;
        $g = $this->db->q("SELECT g.* FROM oauth_tokens t JOIN oauth_grants g ON g.id = t.grant_id
            WHERE t.token_hash = ? AND t.kind = 'access' AND t.expires >= ? AND g.revoked_at IS NULL", [hash('sha256', $token), $this->ted()])->fetch();
        if (!$g) return null;
        $this->db->q('UPDATE oauth_grants SET uses = uses + 1' . ((int)$g['last_used'] < $this->ted() - 60 ? ', last_used = ' . $this->ted() : '') . ' WHERE id = ?', [$g['id']]);
        $g['scopes'] = explode(' ', (string)$g['scope']);
        return $g;
    }

    /** @return array<string, mixed>|null */
    private function pripojeniPodleId(int $id): ?array
    {
        return $this->db->q('SELECT * FROM oauth_grants WHERE id = ? AND revoked_at IS NULL', [$id])->fetch() ?: null;
    }

    public function zrusPripojeni(int $id, bool $zapsat = true): void
    {
        $g = $this->pripojeniPodleId($id);
        $this->db->q('UPDATE oauth_grants SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [$this->ted(), $id]);
        $this->db->q('DELETE FROM oauth_tokens WHERE grant_id = ?', [$id]);
        $this->db->q('DELETE FROM oauth_codes WHERE grant_id = ?', [$id]);
        if ($g && $zapsat) $this->protokol->zapis('info', "OAuth: odebráno připojení „{$g['client_name']}“.");
    }

    /** Zrušení tokenu klientem (RFC 7009) – zruší celé připojení. */
    public function zrusToken(string $token): void
    {
        if ($token === '') return;
        $gid = $this->db->q('SELECT grant_id FROM oauth_tokens WHERE token_hash = ?', [hash('sha256', $token)])->fetchColumn();
        if ($gid) $this->zrusPripojeni((int)$gid);
    }

    /** Aktivní připojení (nejdřív naposledy použitá). Nedokončená a bez platných tokenů zmizí. @return list<array<string, mixed>> */
    public function pripojeni(): array
    {
        $ted = $this->ted();
        $this->db->q('UPDATE oauth_grants SET revoked_at = ? WHERE revoked_at IS NULL AND created_at < ?
            AND NOT EXISTS (SELECT 1 FROM oauth_tokens t WHERE t.grant_id = oauth_grants.id AND t.expires >= ?)
            AND NOT EXISTS (SELECT 1 FROM oauth_codes c WHERE c.grant_id = oauth_grants.id AND c.expires >= ?)', [$ted, $ted - 600, $ted, $ted]);
        return $this->db->q('SELECT id, client_name, redirect_host, scope, created_at, last_used, uses FROM oauth_grants WHERE revoked_at IS NULL ORDER BY COALESCE(last_used, created_at) DESC, id DESC')->fetchAll();
    }

    // ---------- HTTP pomůcky (bez $_SERVER: aplikace předá pole) ----------

    /**
     * Token z hlavičky Authorization: Bearer … (Apache ji někdy předá jen v REDIRECT_HTTP_AUTHORIZATION, jinak pomůže getallheaders()).
     * @param array<string, mixed> $server  obvykle $_SERVER @param array<string, string> $hlavicky  obvykle getallheaders()
     */
    public static function bearer(array $server, array $hlavicky = []): string
    {
        $h = (string)($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($h === '') foreach ($hlavicky as $k => $v) if (strcasecmp((string)$k, 'Authorization') === 0) $h = (string)$v;
        return preg_match('/^Bearer\s+(\S+)$/i', trim($h), $m) ? $m[1] : '';
    }
}
