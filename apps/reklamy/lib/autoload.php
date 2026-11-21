<?php
declare(strict_types=1);
// Zavaděč tříd: moduly platformy (modules/ v balíčku, při vývoji ../../modules) a třídy aplikace Reklamy\… z lib/.

$__moduly = is_dir(dirname(__DIR__) . '/modules') ? dirname(__DIR__) . '/modules' : dirname(__DIR__, 3) . '/modules';
require_once $__moduly . '/jadro/boot.php';
unset($__moduly);

spl_autoload_register(static function (string $trida): void {
    if (!str_starts_with($trida, 'Reklamy\\')) return;
    $rel = str_replace('\\', '/', substr($trida, 8));
    if (!preg_match('~^[A-Za-z][A-Za-z0-9_/]*$~', $rel)) return;
    $soubor = __DIR__ . '/' . $rel . '.php';
    if (is_file($soubor)) require_once $soubor;
});
