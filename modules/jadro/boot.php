<?php
declare(strict_types=1);
// Zavedení platformy: načte zavaděč tříd a zaregistruje složku modulů.
// Použití v aplikaci:  require '/cesta/k/modules/jadro/boot.php';  TWB\Jadro\Loader::registruj(__DIR__ . '/modules');
require_once __DIR__ . '/src/Loader.php';
TWB\Jadro\Loader::registruj(dirname(__DIR__));
