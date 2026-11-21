<?php
declare(strict_types=1);
namespace TWB\Data;

/** Vzdálené zdroje dat (JSON/CSV): {Allwyn:sportka.superjackpot}. */
interface Zdroje
{
    /** Je zdroj tohoto názvu zaveden? (název bez ohledu na velikost písmen) */
    public function existuje(string $nazev): bool;

    /** Hodnota na cestě uvnitř zdroje; null = neznámá cesta. */
    public function hodnota(string $nazev, string $cesta): ?string;
}
