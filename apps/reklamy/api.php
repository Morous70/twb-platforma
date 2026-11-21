<?php
declare(strict_types=1);
// JSON rozhraní pro editor slidů (assets/slide-shim.js): api.php?a=<akce>. Jen pro přihlášeného; POST s hlavičkou X-CSRF.
use Reklamy\{App, Chyba, SlideBannery, Bannery};
use TWB\Slidy\Fx;
use TWB\Data\Format;

/** @var App $app */
$app = require __DIR__ . '/lib/bootstrap.php';
header('X-Content-Type-Options: nosniff');
$json = function (mixed $data, int $kod = 200): never {
    http_response_code($kod);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
};
if (!$app->nainstalovano()) $json(['error' => 'Aplikace není nainstalovaná.'], 400);
$app->migruj();
$relace = $app->relace();
if (!$relace->jePrihlasen($app->limitNecinnosti())) $json(['error' => 'Přihlášení vypršelo.'], 401);
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post && !$relace->csrfOver($_SERVER['HTTP_X_CSRF'] ?? null)) $json(['error' => 'Stránka vypršela, načtěte ji znovu.'], 403);
$a = (string)($_GET['a'] ?? '');
$vstup = $post && str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')
    ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : $_POST;
$cas = fn() => !empty($vstup['ts']) && is_numeric($vstup['ts']) ? (new DateTimeImmutable())->setTimestamp((int)$vstup['ts']) : new DateTimeImmutable();
$jenPost = function () use ($post, $json): void { if (!$post) $json(['error' => 'Jen POST.'], 405); };

try {
    switch ($a) {
        case 'slide_formats':   // běžné rozměry + vlastní (?w=&h= nového slidu nebo rozměr upravovaného banneru)
            $f = array_map(fn($k, $x) => ['key' => $k, 'w' => $x[0], 'h' => $x[1], 'name' => $x[2]], array_keys(SlideBannery::formaty()), SlideBannery::formaty());
            $w = (int)($_GET['w'] ?? 0); $h = (int)($_GET['h'] ?? 0);
            if (!empty($_GET['id'])) {
                $b = $app->bannery()->radek((int)$_GET['id']);
                $w = (int)$b['width']; $h = (int)$b['height'];
            }
            if ($w >= 16 && $h >= 16 && $w <= Bannery::MAX_ROZMER && $h <= Bannery::MAX_ROZMER && !isset(SlideBannery::formaty()["{$w}x{$h}"])) {
                $f[] = ['key' => 'vlastni', 'w' => $w, 'h' => $h, 'name' => 'Vlastní rozměr'];
            }
            $json($f);

        case 'slide_get':
            $b = $app->bannery()->radek((int)($_GET['id'] ?? 0));
            if ($b['type'] !== 'slide') throw new Chyba('Banner není slide.');
            $json(['id' => (int)$b['id'], 'name' => $b['name'], 'tags' => [], 'design' => $app->slideBannery()->design($b), 'campaign_id' => (int)$b['campaign_id'],
                'url' => 'media.php?slide=' . (int)$b['id'] . '&v=' . $app->slideBannery()->klic($b)]);

        case 'slide_save':   // id (0 = nový), kampan (u nového), name, design
            $jenPost();
            $id = (int)($vstup['id'] ?? 0) ?: null;
            $nazev = trim((string)($vstup['name'] ?? ''));
            if ($nazev === '') $json(['error' => 'Zadejte název slidu.'], 422);
            $id = $app->slideBannery()->uloz($id, isset($vstup['kampan']) ? (int)$vstup['kampan'] : null, $nazev, $vstup['design'] ?? []);
            $b = $app->bannery()->radek($id);
            $json(['ok' => true, 'media' => ['id' => $id, 'name' => $b['name'], 'width' => (int)$b['width'], 'height' => (int)$b['height']]]);

        case 'slide_preview':
            $jenPost();
            $jpg = $app->slidy()->vykresli($app->navrh()->vycisti($vstup['design'] ?? []), $cas(), 85);
            $json(['image' => 'data:image/jpeg;base64,' . base64_encode($jpg)]);

        case 'resolve':   // dosazené proměnné a přesné rozvržení textů tak, jak je vykreslí server
            $jenPost();
            $d = $app->navrh()->vycisti($vstup['design'] ?? []);
            $ev = $app->slidy()->vyhodnot($d, $cas());
            $res = [];
            foreach ($d['elements'] as $e) {
                $r = $ev[$e['id']];
                if ($e['type'] === 'text') { $L = $app->slidy()->text()->rozvrh($e, $r['text']); unset($L['font']); $r['layout'] = $L; }
                $res[$e['id']] = $r;
            }
            $json((object)$res);

        case 'fx_styles':
            $json(['styles' => Fx::STYLY, 'stops' => Fx::STOPS, 'ranges' => Fx::ROZSAHY]);

        case 'fonts':
            $json(array_map(fn($k) => $app->pisma()->vystup($k), array_keys($app->pisma()->vsechna())));

        case 'pismo':   // soubor písma pro editor: ?soubor=Roboto&tloustka=700 (vestavěné) nebo ?klic=u_… (vlastní)
            $p = $app->pisma();
            if (isset($_GET['klic'])) {
                $f = $p->vsechna()[(string)$_GET['klic']] ?? null;
                if (!$f || !$f['custom']) $json(['error' => 'Písmo nenalezeno.'], 404);
                $soubor = $p->soubor((string)$_GET['klic'], 700);
            } else {
                $soubor = '';
                foreach ($p->vsechna() as $k => $f) {
                    if (!$f['custom'] && $f['file'] === ($_GET['soubor'] ?? null)) $soubor = $p->soubor($k, (int)($_GET['tloustka'] ?? 400));
                }
                if ($soubor === '' || !is_file($soubor)) $json(['error' => 'Písmo nenalezeno.'], 404);
            }
            header('Content-Type: ' . (str_ends_with($soubor, '.otf') ? 'font/otf' : 'font/ttf'));
            header('Cache-Control: private, max-age=604800');
            header('Content-Length: ' . filesize($soubor));
            readfile($soubor);
            exit;

        case 'font_upload':
            $jenPost();
            $f = $_FILES['font'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) $json(['error' => 'Soubor s písmem se nepodařilo nahrát.'], 422);
            [$font, $chybi] = $app->pisma()->nahraj((string)$f['tmp_name'], (string)$f['name'], (string)($_POST['name'] ?? ''));
            $json(['font' => $font, 'missing' => $chybi]);

        case 'font_rename':
            $jenPost();
            $json(['font' => $app->pisma()->prejmenuj((string)($vstup['key'] ?? ''), (string)($vstup['name'] ?? ''))]);

        case 'font_delete':
            $jenPost();
            $app->pisma()->smaz((string)($vstup['key'] ?? ''));
            $json(['ok' => true]);

        case 'knihovna':   // obrázky z galerie pro editor
            $json(['items' => array_map(fn($m) => ['id' => $m['id'], 'url' => 'media.php?id=' . $m['id'] . '&v=' . substr((string)$m['md5'], 0, 8), 'kind' => 'image',
                'original_name' => $m['nazev'], 'tags' => $m['tagy'], 'w' => $m['sirka'], 'h' => $m['vyska']],
                array_values(array_filter($app->galerie()->vypis(), fn($m) => $m['druh'] === 'image' && !$m['archivovano'])))]);

        case 'data_list':
            $dnes = new DateTimeImmutable('today');
            $json(['rows' => array_map(function ($r) use ($dnes) {
                $d = Format::datumZTextu((string)$r['param']);
                return ['id' => (int)$r['id'], 'grp' => $r['grp'], 'param' => $r['param'], 'value' => $r['value'], 'date' => $d?->format('Y-m-d'), 'past' => $d ? $d < $dnes : false];
            }, $app->tabulkaDat()->radky(true))]);

        case 'source_tree':   // vzdálené zdroje dat aplikace zatím nemá
            $json(['sources' => []]);

        case 'datefmt':   // dialogy Formát data / čísla: jak vypadá proměnná v jednotlivých formátech (dnes)
            $jenPost();
            $expr = trim((string)($vstup['expr'] ?? ''));
            if ($expr === '' || mb_strlen($expr) > 300 || preg_match('/[{}|]/', $expr)) $json(['error' => 'Neplatná proměnná.'], 422);
            $t = new DateTimeImmutable(); $res = [];
            foreach (array_slice(array_map('strval', (array)($vstup['formats'] ?? [])), 0, 40) as $f) {
                if (mb_strlen($f) > 150 || preg_match('/[{}]/', $f)) { $res[] = null; continue; }
                [$txt, $unk] = $app->promenne()->vyres('{{' . $expr . $f . '}}', $t);
                $res[] = $unk ? null : $txt;
            }
            $json(['values' => $res]);

        case 'weather_settings':
            $json(['error' => 'Počasí aplikace nepoužívá.'], 404);
    }
    $json(['error' => 'Neznámá akce.'], 404);
} catch (Chyba | InvalidArgumentException $e) {
    $json(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    $app->log('error', "api.php ($a): " . $e->getMessage());
    $json(['error' => 'Chyba serveru: ' . $e->getMessage()], 500);
}
