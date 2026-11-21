<?php
declare(strict_types=1);
namespace TWB\Prihlaseni;

/** Přihlášení přes Google (OpenID Connect, tok „authorization code“). */
final class Google
{
    /**
     * @param callable(string, string, array<string, mixed>): array{code: int, json: ?array} $http  (metoda, adresa, ['form' => …]) → odpověď
     */
    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $redirectUri,
        private $http,
    ) {}

    public function nastaveno(): bool { return $this->clientId !== '' && $this->clientSecret !== ''; }

    public function adresaPrihlaseni(string $state, string $nonce): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $this->clientId, 'redirect_uri' => $this->redirectUri, 'response_type' => 'code',
            'scope' => 'openid email', 'state' => $state, 'nonce' => $nonce, 'prompt' => 'select_account',
        ]);
    }

    /** Vymění kód za ID token a ověří ho. @return array{sub: string, email: string} */
    public function vymenKod(string $kod, string $nonce): array
    {
        $r = ($this->http)('POST', 'https://oauth2.googleapis.com/token', ['form' => [
            'code' => $kod, 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri, 'grant_type' => 'authorization_code',
        ]]);
        $idt = $r['json']['id_token'] ?? '';
        if (($r['code'] ?? 0) !== 200 || !is_string($idt) || substr_count($idt, '.') !== 2) {
            throw new \RuntimeException('Google nevrátil přihlašovací údaje' . (isset($r['json']['error']) ? ' (' . $r['json']['error'] . ')' : '') . '.');
        }
        // Token přichází přímo od Googlu přes HTTPS spolu s tajným klíčem aplikace, podpis se proto neověřuje (OpenID Connect Core 3.1.3.7).
        return $this->overToken($idt, $nonce);
    }

    /** Zkontroluje obsah ID tokenu (vydavatel, aplikace, platnost, nonce, ověřený e-mail). @return array{sub: string, email: string} */
    public function overToken(string $idt, string $nonce): array
    {
        if (substr_count($idt, '.') !== 2) throw new \RuntimeException('Neplatný ID token.');
        $c = json_decode(Base64Url::dekoduj(explode('.', $idt)[1]), true);
        if (!is_array($c)) throw new \RuntimeException('Neplatný ID token.');
        if (!in_array($c['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)) throw new \RuntimeException('ID token má jiného vydavatele.');
        if (($c['aud'] ?? '') !== $this->clientId) throw new \RuntimeException('ID token je pro jinou aplikaci.');
        if ((int)($c['exp'] ?? 0) < time()) throw new \RuntimeException('ID token vypršel.');
        if ($nonce === '' || !hash_equals($nonce, (string)($c['nonce'] ?? ''))) throw new \RuntimeException('Neplatný požadavek (nonce).');
        if (empty($c['sub']) || empty($c['email']) || !filter_var($c['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw new \RuntimeException('Google účet nemá ověřený e-mail.');
        }
        return ['sub' => (string)$c['sub'], 'email' => (string)$c['email']];
    }
}
