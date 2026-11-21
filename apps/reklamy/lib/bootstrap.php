<?php
declare(strict_types=1);
// Zavedení aplikace TWB Reklamy: zavaděč tříd a konfigurace. Vrací instanci Reklamy\App.
require_once __DIR__ . '/autoload.php';

// Vestavěný server PHP (jen testy a vývoj) smí načíst jinou konfiguraci.
$__cfg = PHP_SAPI === 'cli-server' && getenv('REKLAMY_CONFIG') ? (string)getenv('REKLAMY_CONFIG') : dirname(__DIR__) . '/config.php';
$__app = Reklamy\App::zaved(dirname(__DIR__), $__cfg);
unset($__cfg);
return $__app;
