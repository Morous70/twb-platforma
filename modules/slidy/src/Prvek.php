<?php
declare(strict_types=1);
namespace TWB\Slidy;

use DateTimeImmutable;

/** Další typ prvku šablony (např. počasí). Základní typy – text, rect, image, balls – jsou přímo v modulu. */
interface Prvek
{
    /** Název typu v šabloně („weather“). */
    public function typ(): string;

    /** Očistí prvek z prohlížeče; vrací jen vlastnosti navíc (společné x, y, w, h, alpha … doplní Navrh). */
    public function vycisti(array $prvek, Navrh $navrh): array;

    /** Vyhodnocení pro čas $t; výsledek se uloží do klíče vykreslení a předá `kresli`. @return array<string,mixed> */
    public function vyhodnot(array $prvek, DateTimeImmutable $t): array;

    /** Nakreslí prvek na obraz. */
    public function kresli(\GdImage $im, array $prvek, array $vyhodnoceni, Text $text): void;
}
