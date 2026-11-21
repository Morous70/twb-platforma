<?php
declare(strict_types=1);
namespace Reklamy;

/**
 * Okolnosti jednoho zobrazení reklamy: stránka, odkud návštěvník přišel, prohlížeč, jazyk, čas.
 * Z nich se rozhoduje o cílení a o tom, zda se zobrazení počítá (roboti ne).
 */
final class Kontext
{
    /** Roboti a nástroje, jejichž zobrazení a prokliky se nepočítají. */
    private const ROBOTI = '~bot\b|crawl|spider|slurp|mediapartners|facebookexternalhit|embedly|preview|headless|lighthouse|pingdom|uptime|monitor|curl/|wget|python-|go-http|java/|okhttp|httpclient|scrapy|semrush|ahrefs|seznambot|petalbot|bingpreview~i';

    /** @var array<int, true> bannery, které už na stránce jsou (stejný banner se neopakuje) */
    public array $vynechat = [];
    /** Návštěvník (cookie twbr_v), prázdné = neznámý nebo cookies vypnuté. */
    public string $navstevnik = '';
    /** Nový návštěvník – endpoint mu pošle cookie. */
    public bool $novyNavstevnik = false;
    /** @var array<int, array{0:int,1:int}> frekvence: kampaň => [počet zobrazení, začátek okna] (cookie twbr_f) */
    public array $frekvence = [];
    public bool $zmenaFrekvence = false;
    /** Cookies návštěvníků povolené (nastavení visitor_cookies) a dostupné (požadavek z prohlížeče). */
    public bool $cookies = false;

    public function __construct(
        public readonly string $stranka = '',
        public readonly string $odkud = '',
        public readonly string $prohlizec = '',
        public readonly string $jazyk = '',
        public readonly string $ip = '',
        public readonly int $cas = 0,
        public readonly bool $pocitat = true,
    ) {}

    /** Kontext z HTTP požadavku. Stránku posílá skript (loc), jinak se vezme z hlavičky Referer. @param array<string, mixed> $get @param array<string, mixed> $server */
    public static function zPozadavku(array $get, array $server, bool $pocitat = true): self
    {
        $s = static fn($v) => is_string($v) ? mb_substr(trim($v), 0, 2000) : '';
        return new self(
            stranka: $s($get['loc'] ?? '') ?: $s($server['HTTP_REFERER'] ?? ''),
            odkud: $s($get['ref'] ?? ''),
            prohlizec: $s($get['ua'] ?? '') ?: $s($server['HTTP_USER_AGENT'] ?? ''),
            jazyk: $s($server['HTTP_ACCEPT_LANGUAGE'] ?? ''),
            ip: $s($server['REMOTE_ADDR'] ?? ''),
            cas: (int)($server['REQUEST_TIME'] ?? 0) ?: time(),
            pocitat: $pocitat,
        );
    }

    /** Načte cookies návštěvníka (identifikátor a frekvenci). @param array<string, mixed> $cookie */
    public function nactiCookies(array $cookie): void
    {
        $this->cookies = true;
        $v = $cookie['twbr_v'] ?? '';
        if (is_string($v) && preg_match('/^[a-f0-9]{32}$/', $v)) $this->navstevnik = $v;
        $f = $cookie['twbr_f'] ?? '';
        if (is_string($f) && strlen($f) <= 2000) {
            foreach (explode('.', $f) as $c) {
                if (preg_match('/^(\d{1,9})-(\d{1,6})-(\d{1,10})$/', $c, $m)) $this->frekvence[(int)$m[1]] = [(int)$m[2], (int)$m[3]];
            }
        }
    }

    /** Návštěvník pro přiřazení konverzí: existující, nebo nový (jen když jsou cookies povolené). */
    public function navstevnik(): string
    {
        if ($this->navstevnik === '' && $this->cookies) {
            $this->navstevnik = bin2hex(random_bytes(16));
            $this->novyNavstevnik = true;
        }
        return $this->navstevnik;
    }

    /** Kolikrát návštěvník viděl kampaň v posledních $hodin hodinách (podle cookie). */
    public function videl(int $kampan, int $hodin): int
    {
        [$n, $od] = $this->frekvence[$kampan] ?? [0, 0];
        return $od > $this->ted() - $hodin * 3600 ? $n : 0;
    }

    /** Zaznamená zobrazení kampaně do frekvence; okno začne prvním zobrazením. */
    public function zapisFrekvenci(int $kampan, int $hodin): void
    {
        [$n, $od] = $this->frekvence[$kampan] ?? [0, 0];
        if ($od <= $this->ted() - $hodin * 3600) { $n = 0; $od = $this->ted(); }
        $this->frekvence[$kampan] = [$n + 1, $od];
        $this->zmenaFrekvence = true;
    }

    /** Hodnota cookie twbr_f (jen platné záznamy, nejvýš 60 kampaní). */
    public function cookieFrekvence(int $maxHodin = 24 * 30): string
    {
        $out = [];
        foreach ($this->frekvence as $k => [$n, $od]) if ($od > $this->ted() - $maxHodin * 3600) $out[] = "$k-$n-$od";
        return implode('.', array_slice($out, -60));
    }

    /** Zařízení podle prohlížeče: mobil, tablet, pocitac. */
    public function zarizeni(): string
    {
        $u = $this->prohlizec;
        if (preg_match('~iPad|Tablet|PlayBook|Silk|Kindle|(Android(?!.*Mobile))~i', $u)) return 'tablet';
        if (preg_match('~Mobi|iPhone|iPod|Android|BlackBerry|IEMobile|Opera Mini|Windows Phone~i', $u)) return 'mobil';
        return 'pocitac';
    }

    /** Hlavní jazyk návštěvníka (cs, sk, en …) z Accept-Language, prázdné = neznámý. */
    public function jazyk(): string
    {
        return preg_match('/^\s*([a-z]{2,3})\b/i', $this->jazyk, $m) ? strtolower($m[1]) : '';
    }

    public function jeRobot(): bool
    {
        return $this->prohlizec === '' || (bool)preg_match(self::ROBOTI, $this->prohlizec);
    }

    /** Počítat zobrazení a prokliky? (ne u náhledu a robotů) */
    public function zapocitat(): bool { return $this->pocitat && !$this->jeRobot(); }

    public function ted(): int { return $this->cas ?: time(); }
}
