<?php
declare(strict_types=1);
namespace TWB\Slidy;

use DateTimeImmutable;

/** Proměnné v textech a podmínky „zobrazit jen když“. Zařídí aplikace (modul data). */
interface Promenne
{
    /**
     * Dosadí proměnné do textu.
     * @return array{0: string, 1: list<string>} [text, názvy neznámých proměnných]
     */
    public function vyres(string $text, DateTimeImmutable $t): array;

    /**
     * Platí všechny podmínky? Podmínka: ['l' => levá strana, 'op' => =, !=, >, <, >=, <=, contains, empty, notempty, 'r' => pravá strana].
     * @param list<array{l:string,op:string,r:string}> $podminky
     */
    public function splneno(array $podminky, DateTimeImmutable $t): bool;
}
