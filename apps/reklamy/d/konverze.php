<?php
declare(strict_types=1);
// Konverze z webu inzerenta: ?t=TRACKER[&hodnota=1234.50][&id=ČÍSLO OBJEDNÁVKY][&twbr=TOKEN PROKLIKU][&format=json]
// Vrací průhledný obrázek GIF (kód na stránce), nebo JSON (volání ze serveru). Přiřazení k banneru viz lib/Konverze.php.
use Reklamy\{App, Vydej, Konverze, Chyba};

/** @var App $app */
$app = require dirname(__DIR__) . '/lib/bootstrap.php';
Vydej::bezCache();
Vydej::cors();
$json = ($_GET['format'] ?? '') === 'json';
$vysledek = ['stav' => 'chyba', 'via' => '', 'banner' => null];
try {
    $v = new Vydej($app);
    $t = (string)($_GET['t'] ?? '');
    if ($v->pripraveno && ctype_digit($t)) {
        $k = Reklamy\Kontext::zPozadavku([], $_SERVER);
        if ($v->cookiesPovolene()) $k->nactiCookies($_COOKIE);
        try { $hodnota = Konverze::castka($_GET['hodnota'] ?? ''); } catch (Chyba) { $hodnota = null; }
        $ref = is_scalar($_GET['id'] ?? null) ? (string)$_GET['id'] : null;
        $token = is_string($_GET['twbr'] ?? null) ? $_GET['twbr'] : null;
        $r = $app->konverze()->zaznamenej((int)$t, $token, $k, $hodnota, $ref);
        $vysledek = ['stav' => $r['stav'], 'via' => $r['via'], 'banner' => $r['banner']];
    }
} catch (Throwable $e) {
    if (isset($v)) $v->chyba($e, 'konverze.php');
}
if ($json) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($vysledek, JSON_UNESCAPED_UNICODE);
} else {
    header('Content-Type: image/gif');
    echo Vydej::GIF;
}
