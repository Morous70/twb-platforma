<?php
declare(strict_types=1);
namespace TWB\Data;

use DateTimeImmutable;

/** Napojení na modul `html` (rozhraní `TWB\Html\Promenne`). */
final class ProHtml implements \TWB\Html\Promenne
{
    public function __construct(private Promenne $promenne) {}
    public function vyres(string $text, DateTimeImmutable $t): array { return $this->promenne->vyres($text, $t, 0, true); }
}
