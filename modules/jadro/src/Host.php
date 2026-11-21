<?php
declare(strict_types=1);
namespace TWB\Jadro;

/**
 * Hostitel: vše, co moduly smějí od aplikace použít. Nic dalšího o aplikaci neví.
 */
final class Host
{
    public readonly Registr $registr;

    /** @param array<string, mixed> $cfg */
    public function __construct(
        public readonly Db $db,
        public readonly Nastaveni $nastaveni,
        public readonly Protokol $protokol,
        public readonly string $datovaSlozka,
        public readonly array $cfg = [],
    ) {
        $this->registr = new Registr();
    }

    public function cfg(string $klic, mixed $vychozi = null): mixed { return $this->cfg[$klic] ?? $vychozi; }
}
