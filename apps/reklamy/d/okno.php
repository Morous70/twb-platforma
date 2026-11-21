<?php
declare(strict_types=1);
// Vyskakovací okno: <script src=".../d/okno.php?zona=ID"></script>
// Parametry: sirka, vyska (výchozí rozměr zóny), kdy=klik (výchozí, při prvním kliknutí na stránce – prohlížeče jinak okno blokují) | ihned,
//            pod=1 (otevřít pod aktuální okno), jednou=1 (jen jednou za relaci prohlížeče).
use Reklamy\{App, Vydej};

/** @var App $app */
$app = require dirname(__DIR__) . '/lib/bootstrap.php';
header('Content-Type: application/javascript; charset=utf-8');
Vydej::bezCache();
try {
    $v = new Vydej($app);
    $z = $v->zona($_GET['zona'] ?? '');
} catch (Throwable $e) {
    $z = null;
}
if (!$z) exit;
$cislo = static fn(string $k, int $vychozi): int => isset($_GET[$k]) && ctype_digit((string)$_GET[$k]) ? max(50, min(4096, (int)$_GET[$k])) : $vychozi;
$w = $cislo('sirka', (int)($z['width'] ?? 0) ?: 800);
$h = $cislo('vyska', (int)($z['height'] ?? 0) ?: 600);
$id = (int)$z['id'];
$nastaveni = json_encode([
    'url' => $app->url('d/ram.php') . '?zona=' . $id,
    'nazev' => 'twbr_okno_' . $id,
    'vlastnosti' => "width=$w,height=$h,resizable=yes,scrollbars=no,toolbar=no,location=no,menubar=no,status=no",
    'klik' => ($_GET['kdy'] ?? 'klik') !== 'ihned',
    'pod' => !empty($_GET['pod']),
    'jednou' => !empty($_GET['jednou']),
], JSON_UNESCAPED_SLASHES);
echo <<<JS
(function (w, d, o) {
  'use strict';
  var klic = 'twbr_okno_' + o.nazev;
  function otevri() {
    try { if (o.jednou && w.sessionStorage && sessionStorage.getItem(klic)) return; } catch (e) {}
    var u = o.url + '&loc=' + encodeURIComponent(location.href) + '&cb=' + Math.floor(Math.random() * 1e9);
    var okno = w.open(u, o.nazev, o.vlastnosti);
    if (!okno) return;
    try { if (o.jednou) sessionStorage.setItem(klic, '1'); } catch (e) {}
    if (o.pod) { try { okno.blur(); w.focus(); } catch (e) {} }
  }
  if (o.klik) {
    var jednou = function () { d.removeEventListener('click', jednou, true); otevri(); };
    d.addEventListener('click', jednou, true);
  } else {
    otevri();
  }
})(window, document, $nastaveni);

JS;
