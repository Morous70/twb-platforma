<?php
declare(strict_types=1);
// Soubory HTML5 banneru: d/h5.php/<md5 balíčku>/<cesta v balíčku>. Relativní odkazy uvnitř balíčku tak fungují beze změny.
// Hlavní soubor dostane clickTag: s podpisem ?b=ID&z=ZONA&c=KAMPAN&k=PODPIS vede přes počítání prokliků, bez něj (náhled) přímo na cíl.
use Reklamy\{App, Vydej, Vykresleni};

/** @var App $app */
$app = require dirname(__DIR__) . '/lib/bootstrap.php';
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');   // dokument běží s vlastním (prázdným) původem – písma a data potřebují CORS
try {
    $v = new Vydej($app);
    $cesta = (string)($_SERVER['PATH_INFO'] ?? $_GET['f'] ?? '');
    if ($v->pripraveno && preg_match('~^/?([a-f0-9]{32})/(.+)$~', $cesta, $m)) {
        [, $md5, $rel] = $m;
        $rel = rawurldecode($rel);
        $soubor = $app->html5()->soubor($md5, $rel);
        $b = null;
        if ($soubor !== null) {
            $id = (string)($_GET['b'] ?? '');
            $b = ctype_digit($id) ? ($app->db->q("SELECT * FROM banners WHERE id = ? AND type = 'html5'", [(int)$id])->fetch() ?: null) : null;
            if ($b === null) {
                $b = $app->db->q("SELECT * FROM banners WHERE type = 'html5' AND content LIKE ? LIMIT 1", ['%"zip":"' . $md5 . '"%'])->fetch() ?: null;
            }
        }
        if ($soubor !== null && $b !== null && Vykresleni::balicek($b)['zip'] === $md5) {
            $typ = \Reklamy\Html5::typ($rel);
            header('Content-Type: ' . $typ);
            if (str_starts_with($typ, 'text/html') || str_starts_with($typ, 'image/svg')) header('Content-Security-Policy: ' . Vydej::SANDBOX);
            if ($rel === Vykresleni::balicek($b)['index']) {
                $o = (string)($_GET['b'] ?? '') === (string)$b['id'] ? $app->vykresleni()->overOdkaz('html', $_GET) : null;
                $zona = $o[0] ?? null;
                if ($o) { $b['campaign_id'] = $o[1]; $b['zarizeni'] = Vykresleni::zarizeniZOdkazu($_GET); }
                Vydej::bezCache();
                echo $app->vykresleni()->html5Index($b, (string)file_get_contents($soubor), $zona);
                exit;
            }
            header('Cache-Control: public, max-age=31536000, immutable');   // obsah balíčku se pod stejným md5 nemění
            header('Content-Length: ' . filesize($soubor));
            readfile($soubor);
            exit;
        }
    }
} catch (Throwable $e) {
    if (isset($v)) $v->chyba($e, 'h5.php');
}
http_response_code(404);
Vydej::bezCache();
header('Content-Type: text/plain; charset=utf-8');
echo "Soubor nenalezen.\n";
