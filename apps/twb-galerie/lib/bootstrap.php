<?php
declare(strict_types=1);
// Zavedení aplikace TWB Media galerie: zavaděč tříd a konfigurace. Vrací instanci MediaGalerie\App.
require_once __DIR__ . '/autoload.php';

// Vestavěný server PHP (jen testy a vývoj) smí načíst jinou konfiguraci.
$__cfg = PHP_SAPI === 'cli-server' && getenv('GALERIE_CONFIG') ? (string)getenv('GALERIE_CONFIG') : dirname(__DIR__) . '/config.php';
$__app = MediaGalerie\App::zaved(dirname(__DIR__), $__cfg);
unset($__cfg);
return $__app;
