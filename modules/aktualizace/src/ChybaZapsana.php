<?php
declare(strict_types=1);
namespace TWB\Aktualizace;

/** Chyba, která už je zapsaná v protokolu (při zachycení se nezapisuje znovu). */
final class ChybaZapsana extends \RuntimeException {}
