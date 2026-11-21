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

    public function jeRobot(): bool
    {
        return $this->prohlizec === '' || (bool)preg_match(self::ROBOTI, $this->prohlizec);
    }

    /** Počítat zobrazení a prokliky? (ne u náhledu a robotů) */
    public function zapocitat(): bool { return $this->pocitat && !$this->jeRobot(); }

    public function ted(): int { return $this->cas ?: time(); }
}
