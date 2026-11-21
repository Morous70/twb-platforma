<?php
declare(strict_types=1);
namespace TWB\Jadro;

/** Protokol událostí. Úrovně: info, warn, error. */
interface Protokol
{
    public function zapis(string $uroven, string $zprava): void;
}
