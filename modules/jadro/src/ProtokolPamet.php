<?php
declare(strict_types=1);
namespace TWB\Jadro;

/** Protokol v paměti (pro testy). */
final class ProtokolPamet implements Protokol
{
    /** @var list<array{string, string}> */
    public array $zaznamy = [];

    public function zapis(string $uroven, string $zprava): void { $this->zaznamy[] = [$uroven, $zprava]; }
}
