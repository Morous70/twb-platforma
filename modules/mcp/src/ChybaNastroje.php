<?php
declare(strict_types=1);
namespace TWB\Mcp;

/** Chyba nástroje se zprávou pro uživatele (neplatný vstup, nenalezený záznam). Klient ji dostane jako výsledek s isError. */
final class ChybaNastroje extends \RuntimeException {}
