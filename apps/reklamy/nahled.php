<?php
declare(strict_types=1);
// Náhled HTML a HTML5 banneru v administraci (modální okno v seznamu bannerů): ?id=ID banneru, jen přihlášený.
// HTML banner se vydá jako izolovaný dokument (CSP sandbox – vlastní prázdný původ), HTML5 přesměruje na hlavní soubor balíčku
// bez podpisu, takže clickTag vede rovnou na cíl a nic se nepočítá. Obrázkové bannery zobrazuje okno přímo (media.php).
use Reklamy\{App, Vydej, Vykresleni};

/** @var App $app */
$app = require __DIR__ . '/lib/bootstrap.php';
if (!$app->nainstalovano() || !$app->relace()->jePrihlasen($app->limitNecinnosti(), true)) { http_response_code(403); exit; }
session_write_close();
Vydej::bezCache();
try {
    $b = $app->bannery()->radek((int)($_GET['id'] ?? 0));
    if ($b['type'] === 'html') {
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Security-Policy: ' . Vydej::SANDBOX);
        echo $app->vykresleni()->dokumentHtml($b, 0);
        exit;
    }
    if ($b['type'] === 'html5') {
        $c = Vykresleni::balicek($b);
        header('Location: ' . $app->vykresleni()->adresa('h5.php/' . $c['zip'] . '/' . implode('/', array_map('rawurlencode', explode('/', $c['index']))), ['b' => (int)$b['id']]), true, 302);
        exit;
    }
} catch (Throwable) {
}
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Náhled není k dispozici.\n";
