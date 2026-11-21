<?php
declare(strict_types=1);
namespace TWB\Prihlaseni;

use TWB\Jadro\Protokol;

/**
 * Stav přihlášení v relaci: přihlášení, odhlášení po nečinnosti, ochrana CSRF.
 * Pracuje s polem (obvykle $_SESSION), takže jde testovat bez skutečné relace PHP.
 */
final class Relace
{
    /** @var callable(): void */
    private $obnovId;

    /**
     * @param array<string, mixed> $data  úložiště relace (předává se odkazem)
     * @param (callable(): void)|null $obnovId  vygeneruje nové ID relace po přihlášení (výchozí session_regenerate_id)
     */
    public function __construct(private array &$data, private Protokol $protokol, ?callable $obnovId = null)
    {
        $this->obnovId = $obnovId ?? static function (): void { if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true); };
    }

    /** Spustí relaci PHP s bezpečnými parametry cookie. */
    public static function spust(string $jmeno): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        session_name($jmeno);
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
        session_start();
    }

    public function prihlas(string $jak): void
    {
        ($this->obnovId)();
        $this->data['auth'] = true;
        $this->data['auth_last'] = time();
        $this->protokol->zapis('info', "Přihlášení: $jak.");
    }

    public function odhlas(): void { unset($this->data['auth'], $this->data['auth_last']); }

    /**
     * Je uživatel přihlášený? Při nečinnosti delší než $limitSekund ho odhlásí (0 = bez omezení).
     * Pasivní požadavek (automatické obnovování stránky) čas aktivity neprodlužuje.
     */
    public function jePrihlasen(int $limitSekund = 0, bool $pasivni = false): bool
    {
        if (empty($this->data['auth'])) return false;
        $posledni = (int)($this->data['auth_last'] ?? time());
        if ($limitSekund > 0 && time() - $posledni > $limitSekund) {
            unset($this->data['auth'], $this->data['auth_last']);
            $this->data['login_error'] = 'Byli jste odhlášeni z důvodu nečinnosti.';
            $this->protokol->zapis('info', 'Odhlášení z důvodu nečinnosti.');
            return false;
        }
        if (!$pasivni || empty($this->data['auth_last'])) $this->data['auth_last'] = time();
        return true;
    }

    /** Zbývající sekundy do odhlášení (null = bez omezení). */
    public function zbyvaSekund(int $limitSekund): ?int
    {
        if ($limitSekund <= 0) return null;
        return max(0, $limitSekund - (time() - (int)($this->data['auth_last'] ?? time())));
    }

    /** Hláška k zobrazení na přihlašovací stránce (ukáže se jen jednou). */
    public function vezmiChybu(): ?string
    {
        $e = $this->data['login_error'] ?? null;
        unset($this->data['login_error']);
        return is_string($e) ? $e : null;
    }

    public function csrfToken(): string { return $this->data['csrf'] ??= bin2hex(random_bytes(16)); }

    public function csrfOver(mixed $token): bool
    {
        return is_string($token) && $token !== '' && isset($this->data['csrf']) && hash_equals((string)$this->data['csrf'], $token);
    }
}
