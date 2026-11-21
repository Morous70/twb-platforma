<?php
declare(strict_types=1);
namespace TWB\Data;

use DateTimeImmutable;

/** Napojení na modul `slidy` (rozhraní `TWB\Slidy\Promenne`): ve slidech platí {x} i {{x}}. Modul `slidy` se načítá jen, když se použije. */
final class ProSlidy implements \TWB\Slidy\Promenne
{
    public function __construct(private Promenne $promenne) {}
    public function vyres(string $text, DateTimeImmutable $t): array { return $this->promenne->vyres($text, $t, 0, true); }
    public function splneno(array $podminky, DateTimeImmutable $t): bool { return $this->promenne->splneno($podminky, $t); }
}
