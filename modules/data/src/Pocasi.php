<?php
declare(strict_types=1);
namespace TWB\Data;

use DateTimeImmutable;

/** Předpověď počasí: {Počasí:teplota}, {Počasí:zítra.max}. */
interface Pocasi
{
    /** Hodnota podle argumentu pro daný čas; null = neznámý údaj. */
    public function hodnota(string $arg, DateTimeImmutable $t): ?string;
}
