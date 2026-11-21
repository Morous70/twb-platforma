<?php
declare(strict_types=1);
// Sestavení balíčků aplikace pro TWB Update Server.
//   php tools/sestavit.php <aplikace> [--kontrola] [--zamek] [--bez-lintu] [--cil=dist]
//     --kontrola  jen ověří modules.lock a vypíše obsah, nic nesestavuje
//     --zamek     přepíše modules.lock podle verzí z module.json (vědomé potvrzení nových verzí)
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/lib/Sestavovac.php';

$args = array_slice($argv, 1);
$volby = array_filter($args, fn($a) => str_starts_with($a, '--'));
$app = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')))[0] ?? null;
if ($app === null) { fwrite(STDERR, "Použití: php tools/sestavit.php <aplikace> [--kontrola] [--zamek] [--bez-lintu] [--cil=dist]\n"); exit(2); }
$cil = null;
foreach ($volby as $v) if (str_starts_with($v, '--cil=')) $cil = substr($v, 6);
$koren = dirname(__DIR__);
$s = new Sestavovac($koren, null, !in_array('--bez-lintu', $volby, true));
try {
    if (in_array('--zamek', $volby, true)) { $s->aktualizujZamek($app); echo "modules.lock aktualizován.\n"; }
    $chyby = $s->over($app);
    if ($chyby) { fwrite(STDERR, "Chyby:\n- " . implode("\n- ", $chyby) . "\n"); exit(1); }
    $a = $s->nacti($app);
    if (in_array('--kontrola', $volby, true)) {
        echo "Aplikace $app {$a['verze']}: moduly " . ($a['lock'] ? implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($a['lock']), $a['lock'])) : '(žádné)') . "\n";
        echo 'FULL: ' . count($s->soubory($app, true)) . " souborů, UPDATE: " . count($s->soubory($app, false)) . " souborů\n";
        exit(0);
    }
    $r = $s->sestav($app, $cil ?? "$koren/dist");
    foreach (['full', 'update'] as $d) {
        printf("%-6s %s  %d souborů, %s B\n       sha256 %s\n", strtoupper($d), $r[$d]['soubor'], $r[$d]['souboru'], number_format($r[$d]['size'], 0, ',', ' '), $r[$d]['sha256']);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
