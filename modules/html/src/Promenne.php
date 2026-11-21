<?php
declare(strict_types=1);
namespace TWB\Html;

use DateTimeImmutable;

/** Dosazování proměnných do textu dodává aplikace (modul `data`). */
interface Promenne
{
    /** @return array{0: string, 1: list<string>} [text s dosazenými hodnotami, neznámé proměnné] */
    public function vyres(string $text, DateTimeImmutable $t): array;
}
