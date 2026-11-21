<?php
declare(strict_types=1);
// JSON: bannery pro jednu nebo více zón (?zona=1 nebo ?zony=1,2,3). Používá ho asynchronní skript i servery (PHP, JSON API).
// Volitelně: loc (stránka), ref (odkud návštěvník přišel), ua (prohlížeč návštěvníka při volání ze serveru).
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
    $k = $v->kontext($_GET, $zony[0] ?? 0);
    foreach ($zony as $id) {
        $z = $v->zona($id);
        if (!$z) { $out['zony'][] = ['zona' => $id, 'html' => '', 'banner' => null]; continue; }
        $r = $v->proZonu($z, $k);
        $out['zony'][] = ['zona' => $id, 'html' => $r['html'], 'banner' => $r['banner'] ? $v->udaje($r['banner'], $id) : null];
    }
} catch (Throwable $e) {
    if (isset($v)) $v->chyba($e, 'data.php');
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
