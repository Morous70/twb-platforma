<?php
declare(strict_types=1);
// JSON: bannery pro jednu nebo více zón (?zona=1 nebo ?zony=1,2,3). Používá ho asynchronní skript i servery (PHP, JSON API).
// Volitelně: loc (stránka), ref (odkud návštěvník přišel), ua (prohlížeč návštěvníka při volání ze serveru),
// sw (šířka okna), sirky=300,728 (šířky míst ve stejném pořadí jako zóny; pro jednu zónu sirka=…).
use Reklamy\{App, Vydej};

/** @var App $app */
$app = require dirname(__DIR__) . '/lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
Vydej::bezCache();
Vydej::cors();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
$out = ['zony' => []];
try {
    $v = new Vydej($app);
    $zony = Vydej::zonyZParametru($_GET);
    $sirky = Vydej::sirkyZParametru($_GET);
    $k = $v->kontext($_GET, $zony[0] ?? 0);
    foreach ($zony as $i => $id) {
        $z = $v->zona($id);
        if (!$z) { $out['zony'][] = ['zona' => $id, 'html' => '', 'banner' => null]; continue; }
        $r = $v->proZonu($z, $k, [], $sirky[$i] ?? 0);
        $out['zony'][] = ['zona' => $id, 'html' => $r['html'], 'banner' => $r['banner'] ? $v->udaje($r['banner'], $id) : null];
    }
    Vydej::ulozCookies($k);
} catch (Throwable $e) {
    if (isset($v)) $v->chyba($e, 'data.php');
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
