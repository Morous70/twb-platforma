<?php
declare(strict_types=1);
// XML-RPC pro servery (kompatibilní s metodou openads.view z Revive/OpenX):
//   openads.view(struct remote_info, string what („zone:ID“ nebo „ID“), int campaignid, string target, string source, bool withtext, array context)
//   → struct {html, bannerid, campaignid, zoneid, width, height, alt, contenttype, clickurl, imageurl}
use Reklamy\{App, Vydej, XmlRpc, Kontext};

/** @var App $app */
$app = require dirname(__DIR__) . '/lib/bootstrap.php';
header('Content-Type: text/xml; charset=utf-8');
Vydej::bezCache();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit(XmlRpc::chyba(1, 'Použijte POST s XML-RPC voláním.'));
}
try {
    [$metoda, $p] = XmlRpc::ctiVolani((string)file_get_contents('php://input', false, null, 0, 1048577));
} catch (InvalidArgumentException $e) {
    exit(XmlRpc::chyba(2, $e->getMessage()));
}
if ($metoda === 'system.listMethods') exit(XmlRpc::odpoved(['openads.view', 'system.listMethods']));
if ($metoda !== 'openads.view' && $metoda !== 'twb.view') exit(XmlRpc::chyba(3, "Neznámá metoda $metoda."));
try {
    $v = new Vydej($app);
    $info = is_array($p[0] ?? null) ? $p[0] : [];
    $co = (string)($p[1] ?? '');
    $zonaId = preg_match('/^(?:zone:)?(\d+)$/i', trim($co), $m) ? (int)$m[1] : 0;
    $z = $v->zona((string)$zonaId);
    if (!$z) exit(XmlRpc::odpoved(['html' => '', 'bannerid' => 0, 'campaignid' => 0, 'zoneid' => $zonaId]));
    $s = static fn($x) => is_scalar($x) ? mb_substr((string)$x, 0, 2000) : '';
    $k = new Kontext(
        stranka: $s($info['page_url'] ?? $info['loc'] ?? ''),
        odkud: $s($info['referer'] ?? ''),
        prohlizec: $s($info['user_agent'] ?? $info['HTTP_USER_AGENT'] ?? ''),
        jazyk: $s($info['accept_language'] ?? $info['HTTP_ACCEPT_LANGUAGE'] ?? ''),
        ip: $s($info['remote_addr'] ?? ''),
        cas: time(),
    );
    $r = $v->proZonu($z, $k);
    $b = $r['banner'];
    $u = $b ? $v->udaje($b, $zonaId) : null;
    echo XmlRpc::odpoved([
        'html' => $r['html'], 'bannerid' => $b ? (int)$b['id'] : 0, 'campaignid' => $b ? (int)$b['campaign_id'] : 0, 'zoneid' => $zonaId,
        'width' => $b ? (int)$b['width'] : 0, 'height' => $b ? (int)$b['height'] : 0, 'alt' => $b ? (string)($b['alt'] ?? '') : '',
        'contenttype' => $b ? ($b['type'] === 'html' ? 'html' : 'image') : '', 'clickurl' => $u['klik'] ?? '', 'imageurl' => $u['obrazek'] ?? '',
    ]);
} catch (Throwable $e) {
    if (isset($v)) $v->chyba($e, 'xmlrpc.php');
    echo XmlRpc::chyba(4, 'Reklamu se nepodařilo načíst.');
}
