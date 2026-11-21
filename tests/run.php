<?php
// Spouštěč testů modulů: php tests/run.php [modul ...]
declare(strict_types=1);
$koren = dirname(__DIR__);
$moduly = array_slice($argv, 1) ?: array_map('basename', glob("$koren/tests/*", GLOB_ONLYDIR) ?: []);
sort($moduly);
$selhalo = 0; $souboru = 0;
foreach ($moduly as $m) {
    foreach (glob("$koren/tests/$m/*_test.php") ?: [] as $soubor) {
        $souboru++;
        echo "\n▶ $m / " . basename($soubor) . "\n";
        passthru('php ' . escapeshellarg($soubor) . ' 2>&1', $kod);
        if ($kod !== 0) $selhalo++;
    }
}
echo "\n" . ($selhalo ? "SELHALO souborů: $selhalo z $souboru\n" : "Vše v pořádku ($souboru souborů s testy).\n");
exit($selhalo ? 1 : 0);
