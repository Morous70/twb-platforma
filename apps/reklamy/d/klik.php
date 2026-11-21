<?php
declare(strict_types=1);
// Proklik: započítá klik a přesměruje na cíl banneru.
//   ?b=BANNER&z=ZONA&k=PODPIS[&cil=adresa]  – odkaz z vloženého banneru (cil jen u HTML bannerů, na povolenou adresu)
//   ?zona=ID&n=X                           – vložení odkazem, banner se dohledá podle n z obr.php
use Reklamy\{App, Vydej, Vykresleni, Bannery};

/** @var App $app */
$app = require dirname(__DIR__) . '/lib/bootstrap.php';
Vydej::bezCache();
header('Referrer-Policy: no-referrer-when-downgrade');
try {
    $v = new Vydej($app);
    $d = $app->dorucovani();
    $banner = null;
    $zona = 0;
    if (isset($_GET['b'], $_GET['z']) && ctype_digit((string)$_GET['b']) && ctype_digit((string)$_GET['z'])) {
        if ($v->pripraveno && $d->overPodpis("klik|{$_GET['b']}|{$_GET['z']}", $_GET['k'] ?? null)) {
            $banner = $app->db->q('SELECT * FROM banners WHERE id = ?', [(int)$_GET['b']])->fetch() ?: null;
            $zona = (int)$_GET['z'];
        }
    } elseif ($z = $v->zona($_GET['zona'] ?? '')) {
        $zona = (int)$z['id'];
        $n = is_string($_GET['n'] ?? null) ? mb_substr($_GET['n'], 0, 64) : '';
        $id = $n !== '' ? $d->zapamatovany($n, $zona) : null;
        if ($id !== null) $banner = $app->db->q('SELECT * FROM banners WHERE id = ?', [$id])->fetch() ?: null;
        // bez záznamu (obrázek se nenačetl, mezitím uklizeno): banner, který by se zobrazil teď
        $banner ??= $d->vyber($z, $v->kontext($_GET, $zona), Bannery::OBRAZKOVE);
    }
    if ($banner) {
        $cil = (string)($banner['target_url'] ?? '');
        $jiny = is_string($_GET['cil'] ?? null) ? trim($_GET['cil']) : '';
        if ($jiny !== '' && !in_array($banner['type'], Bannery::OBRAZKOVE, true) && Vykresleni::povolenyCil($banner, $jiny)) $cil = $jiny;
        if ($cil !== '' && Vydej::http($cil)) {
            $k = $v->kontext($_GET, $zona);
            $klikId = $d->proklik((int)$banner['id'], $zona, $k);
            Vydej::ulozCookies($k);
            // parametr twbr pro přiřazení konverze i bez cookies (kód trackeru si ho na webu zapamatuje)
            if ($klikId !== null && $app->nastaveni()->dej('click_param', '1') === '1') $cil = Reklamy\Konverze::pridejParametr($cil, $app->konverze()->token($klikId));
            header('Location: ' . $cil, true, 302);
            exit;
        }
    }
} catch (Throwable $e) {
    if (isset($v)) $v->chyba($e, 'klik.php');
}
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Odkaz reklamy už neplatí.\n";
