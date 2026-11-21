<?php
declare(strict_types=1);
namespace TWB\Jadro;

/** Adaptér: předá zápis stávající funkci aplikace (např. app_log nebo log_line). */
final class ProtokolFunkce implements Protokol
{
    /** @param callable(string, string): void $fn */
    public function __construct(private $fn) {}

    public function zapis(string $uroven, string $zprava): void { ($this->fn)($uroven, $zprava); }
}
