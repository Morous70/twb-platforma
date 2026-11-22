<?php
declare(strict_types=1);
// Nahrávání do galerie po částech (assets/app.js): POST multipart s polí uid, index, celkem, nazev, cast (soubor),
// volitelně kategorie, tagy (čárkami), nahradit (id média). Soubor .zip se rozbalí: každý obrázek jako samostatné médium. Jen přihlášený, CSRF v hlavičce X-CSRF. Odpověď JSON.
use MediaGalerie\{App, Chyba};
use TWB\Galerie\Nahravani;

/** @var App $app */
$app = require __DIR__ . '/lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$odpoved = static function (array $data, int $kod = 200): never {
    http_response_code($kod);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};
if (!$app->nainstalovano()) $odpoved(['ok' => false, 'error' => 'Aplikace není nainstalovaná.'], 400);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $odpoved(['ok' => false, 'error' => 'Jen POST.'], 405);
$relace = $app->relace();
if (!$relace->jePrihlasen($app->limitNecinnosti())) $odpoved(['ok' => false, 'error' => 'Nejste přihlášeni, načtěte stránku znovu.'], 401);
if (!$relace->csrfOver($_SERVER['HTTP_X_CSRF'] ?? null)) $odpoved(['ok' => false, 'error' => 'Stránka vypršela, načtěte ji znovu.'], 403);
session_write_close();
$app->migruj();

try {
    $f = $_FILES['cast'] ?? null;
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
        throw new Chyba('Část souboru se nepodařilo přijmout (příliš velká část nebo přerušený přenos).');
    }
    $tmp = $app->data . '/tmp';
    $nahravani = new Nahravani($tmp, App::MAX_NAHRANI);
    $index = (int)($_POST['index'] ?? -1);
    if ($index === 0) $nahravani->uklid();
    $hotovo = $nahravani->cast((string)($_POST['uid'] ?? ''), $index, (int)($_POST['celkem'] ?? 0), (string)$f['tmp_name']);
    if ($hotovo === null) $odpoved(['ok' => true, 'hotovo' => false]);

    $nazev = mb_substr(trim((string)($_POST['nazev'] ?? '')), 0, 200);
    $nahradit = (int)($_POST['nahradit'] ?? 0) ?: null;
    $kategorie = (int)($_POST['kategorie'] ?? 0) ?: null;
    if ($kategorie !== null) $app->kategorie()->radek($kategorie);
    $tagy = array_filter(array_map('trim', explode(',', (string)($_POST['tagy'] ?? ''))));
    if ($nahradit === null && strtolower(pathinfo($nazev, PATHINFO_EXTENSION)) === 'zip') {
        // ZIP s obrázky: každý obrázek zvlášť, název, štítky, autor a licence z metadat
        $r = $app->media()->nahrajZip($hotovo, array_values($tagy), $kategorie);
        if (!$r['media'] && !$r['chyby'] && !$r['preskoceno']) throw new Chyba('ZIP je prázdný.');
        if (!$r['media'] && $r['chyby']) throw new Chyba('Z ZIPu se nenahrál žádný obrázek: ' . implode(' ', array_slice($r['chyby'], 0, 3)));
        $zprava = 'obrázků ' . count($r['media']) . ($r['preskoceno'] ? ', přeskočeno ' . count($r['preskoceno']) . ' (jiné typy)' : '')
            . ($r['chyby'] ? ', chyby: ' . implode(' ', array_slice($r['chyby'], 0, 3)) . (count($r['chyby']) > 3 ? ' …' : '') : '');
        $odpoved(['ok' => true, 'hotovo' => true, 'zip' => true, 'media' => $r['media'], 'preskoceno' => $r['preskoceno'], 'chyby' => $r['chyby'], 'poznamka' => $zprava, 'nahrazeno' => false]);
    }
    $v = $app->media()->nahraj($hotovo, $nazev, array_values($tagy), $kategorie, $nahradit);
    $odpoved(['ok' => true, 'hotovo' => true, 'id' => $v['id'], 'poznamka' => $v['poznamka'], 'nahrazeno' => $v['nahrazeno']]);
} catch (RuntimeException | InvalidArgumentException $e) {
    $odpoved(['ok' => false, 'error' => $e->getMessage()], 400);
}
