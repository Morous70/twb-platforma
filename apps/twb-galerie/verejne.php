<?php
declare(strict_types=1);
// Veřejný web galerie (0.8.0): ?s=domu (hlavní stránka), ?s=galerie (veřejné kategorie), ?s=galerie&k=<id-nazev> (fotky kategorie), ?s=o-galerii.
// Kořen domény ho otevře přes DirectoryIndex v .htaccess; index.php s parametrem s= (bez p=) sem předá také. Administrace zůstává na index.php.
// Ukazuje jen veřejné kategorie a v nich obrázky s veřejným odkazem mimo archiv (viz MediaGalerie\Web).
// 0.8.2: reklamní plochy mohou být kód z reklamního systému (vloží se bez úprav), kód do hlavičky a ads.txt (?s=ads.txt, /ads.txt přes .htaccess).
use MediaGalerie\{App, Web, Media};
use TWB\Napoveda\Markdown;

/** @var App $app */
$app ??= require __DIR__ . '/lib/bootstrap.php';
if (!$app->nainstalovano()) { header('Location: install.php'); exit; }
$app->migrujPodlePotreby();
$web = $app->web();
$n = $web->nastaveni();
if (!$n['zapnuto']) { header('Location: index.php', true, 302); exit; }

$h = static fn(mixed $s): string => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
/** Ikony ve stylu Feather Icons (MIT, viz assets/LICENCE.txt). */
$ikona = static function (string $nazev, int $v = 20): string {
    $obsah = [
        'logo' => '<circle cx="12" cy="12" r="10"/><line x1="14.31" y1="8" x2="20.05" y2="17.94"/><line x1="9.69" y1="8" x2="21.17" y2="8"/><line x1="7.38" y1="12" x2="13.12" y2="2.06"/><line x1="9.69" y1="16" x2="3.95" y2="6.06"/><line x1="14.31" y1="16" x2="2.83" y2="16"/><line x1="16.62" y1="12" x2="10.88" y2="21.94"/>',
        'prihlasit' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>',
        'obrazek' => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
        'foto' => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
        'dalsi' => '<polyline points="9 18 15 12 9 6"/>',
        'zpet' => '<polyline points="15 18 9 12 15 6"/>',
        'zavrit' => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'menu' => '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
    ][$nazev] ?? '';
    return '<svg class="ik" width="' . $v . '" height="' . $v . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $obsah . '</svg>';
};
$fotografii = static function (int $n): string {
    return $n . "\u{00A0}" . ($n === 1 ? 'fotografie' : ($n >= 2 && $n <= 4 ? 'fotografie' : 'fotografií'));
};
$odkazKategorie = static fn(array $k): string => '?s=galerie&k=' . rawurlencode(Web::slug($k));

$stranka = (string)($_GET['s'] ?? 'domu');
if ($stranka === 'ads.txt') {
    // ads.txt pro reklamní systémy (AdSense…): prostý text z nastavení webu, bez něj 404
    http_response_code($n['ads_txt'] !== '' ? 200 : 404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo $n['ads_txt'] !== '' ? $n['ads_txt'] . "\n" : '';
    exit;
}
$kategorie = $web->kategorie();
$titulek = '';
$aktivni = $stranka;
$obsah = '';
$kod = 200;
$plochy = [];   // reklamní plochy, které stránka opravdu ukázala (kvůli CSP)

// ---------- části stránek ----------
$banner = static function (string $klic) use ($n, $h, $ikona, &$plochy): string {
    $b = $n[$klic];
    if (!$b['zobrazit']) return '';
    if ($b['typ'] === 'kod') {
        // kód z reklamního systému se vkládá tak, jak ho správce zadal (HTML/JS), jen do vlastního obalu
        if ($b['kod'] === '') return '';
        $plochy[] = $klic;
        return '<aside class="banner-kod" aria-label="' . $h($b['stitek'] !== '' ? $b['stitek'] : 'Reklama') . '">'
            . ($b['stitek'] !== '' ? '<span class="banner-kod-stitek">' . $h($b['stitek']) . '</span>' : '')
            . '<div class="banner-kod-obsah">' . $b['kod'] . '</div></aside>';
    }
    if ($b['text'] === '' && $b['stitek'] === '') return '';
    $tl = $b['tlacitko'] !== '' && $b['odkaz'] !== ''
        ? '<a class="banner-tlacitko" href="' . $h($b['odkaz']) . '"' . (preg_match('~^https?://~i', $b['odkaz']) ? ' rel="sponsored noopener"' : '') . '>' . $h($b['tlacitko']) . $ikona('dalsi', 16) . '</a>' : '';
    return '<aside class="banner" aria-label="' . $h($b['stitek'] !== '' ? $b['stitek'] : 'Reklama') . '">'
        . ($b['stitek'] !== '' ? '<span class="banner-stitek">' . $h($b['stitek']) . '</span>' : '')
        . '<p class="banner-text">' . $h($b['text']) . '</p>' . $tl . '</aside>';
};
$karty = static function (array $seznam) use ($web, $h, $ikona, $fotografii, $odkazKategorie): string {
    $out = '<div class="karty">';
    foreach ($seznam as $k) {
        $src = $k['obalka'] ? $web->obrazek($k['obalka'], Web::PX_NAHLED) : '';
        $out .= '<a class="karta" href="' . $h($odkazKategorie($k)) . '">'
            . '<span class="karta-obrazek">' . ($src !== '' ? '<img src="' . $h($src) . '" alt="" loading="lazy">' : '') . '</span>'
            . '<span class="karta-text"><strong>' . $h($k['name']) . '</strong><span class="karta-pocet">' . $ikona('obrazek', 16) . $h($fotografii((int)$k['pocet'])) . '</span>'
            . ($k['web_description'] ? '<span class="karta-popis">' . $h($k['web_description']) . '</span>' : '')
            . '</span><span class="karta-sipka">' . $ikona('dalsi') . '</span></a>';
    }
    return $out . '</div>';
};
/** Mřížka fotek s oknem náhledu (assets/verejne.js); bez JS odkaz vede na velký obrázek. */
$mrizka = static function (array $fotky, bool $sKategorii = false) use ($web, $h): string {
    $out = '<div class="fotky" data-fotky>';
    foreach ($fotky as $m) {
        $uv = Media::uvedeni($m)['text'];
        $popis = implode(' · ', array_filter([$sKategorii ? (string)($m['kategorie_nazev'] ?? '') : '', $uv]));
        $out .= '<a class="foto" href="' . $h($web->obrazek($m, Web::PX_VELKY)) . '" data-foto data-titulek="' . $h(Web::titulek($m)) . '" data-popis="' . $h($popis) . '">'
            . '<img src="' . $h($web->obrazek($m, Web::PX_NAHLED)) . '" alt="' . $h(Web::titulek($m)) . '" loading="lazy"'
            . ($m['width'] ? ' width="' . (int)$m['width'] . '" height="' . (int)$m['height'] . '"' : '') . '></a>';
    }
    return $out . '</div>';
};
$nadpisSekce = static fn(string $nadpis, string $pod = '', string $uroven = 'h2'): string => '<header class="sekce-nadpis"><' . $uroven . '>' . $h($nadpis) . '</' . $uroven . '>'
    . ($pod !== '' ? '<p>' . $h($pod) . '</p>' : '') . '</header>';

// ---------- stránky ----------
switch ($stranka) {
    case 'domu':
        $uvod = $web->uvodni($kategorie);
        $foto = $uvod ? $web->obrazek($uvod, Web::PX_VELKY) : '';
        $obsah .= '<section class="hero' . ($foto === '' ? ' hero-bez-fotky' : '') . '">'
            . ($foto !== '' ? '<img class="hero-fotka" src="' . $h($foto) . '" alt="" fetchpriority="high">' : '')
            . '<div class="hero-text">'
            . ($n['stitek'] !== '' ? '<p class="nadtitulek">' . $h($n['stitek']) . '</p>' : '')
            . '<h1>' . $h($n['nadpis']) . '</h1>'
            . ($n['podnadpis'] !== '' ? '<p class="podtitulek">' . $h($n['podnadpis']) . '</p>' : '')
            . '<a class="tlacitko velke" href="?s=galerie">' . $ikona('obrazek', 22) . '<span>' . $h($n['tlacitko']) . '</span>' . $ikona('dalsi', 18) . '</a>'
            . ($n['poznamka'] !== '' ? '<p class="poznamka">' . $ikona('foto', 18) . '<em>' . $h($n['poznamka']) . '</em></p>' : '')
            . '</div></section>';
        $obsah .= '<div class="obsah">' . $banner('banner1');
        $vybrane = $n['vybrane_pocet'] > 0 ? array_slice($kategorie, 0, (int)$n['vybrane_pocet']) : [];
        if ($vybrane) {
            $obsah .= '<section class="sekce">' . $nadpisSekce($n['vybrane_nadpis'], $n['vybrane_podnadpis']) . $karty($vybrane)
                . (count($kategorie) > count($vybrane) ? '<p class="vice"><a href="?s=galerie">Všechny galerie' . $ikona('dalsi', 16) . '</a></p>' : '') . '</section>';
        } elseif (!$kategorie) {
            $obsah .= '<p class="prazdne">Galerie se připravuje. Nové fotografie brzy přibudou.</p>';
        }
        $obsah .= $banner('banner2') . '</div>';
        break;

    case 'galerie':
        $kId = (int)($_GET['k'] ?? 0);
        if ($kId > 0) {
            $k = $web->jednaKategorie($kId);
            if (!$k) { $kod = 404; break; }
            $titulek = (string)$k['name'];
            $f = $web->fotky($kId, (int)($_GET['strana'] ?? 1));
            $obsah .= '<div class="obsah"><nav class="drobecky" aria-label="Drobečková navigace"><a href="?s=galerie">Galerie</a> <span aria-hidden="true">›</span> <span>' . $h($k['name']) . '</span></nav>'
                . $nadpisSekce((string)$k['name'], ($k['web_description'] ? $k['web_description'] . ' · ' : '') . $fotografii($f['celkem']), 'h1');
            $obsah .= $f['polozky'] ? $mrizka($f['polozky']) : '<p class="prazdne">V této galerii zatím nejsou žádné fotografie.</p>';
            if ($f['stran'] > 1) {
                $obsah .= '<nav class="strankovani" aria-label="Stránky">';
                for ($i = 1; $i <= $f['stran']; $i++) {
                    $obsah .= $i === $f['strana'] ? '<span aria-current="page">' . $i . '</span>' : '<a href="' . $h($odkazKategorie($k) . '&strana=' . $i) . '">' . $i . '</a>';
                }
                $obsah .= '</nav>';
            }
            $obsah .= $banner('banner2') . '</div>';
            break;
        }
        $titulek = 'Galerie';
        $obsah .= '<div class="obsah">' . $nadpisSekce('Galerie', $kategorie ? 'Všechny veřejné galerie.' : '', 'h1');
        $obsah .= $kategorie ? $karty($kategorie) : '<p class="prazdne">Galerie se připravuje. Nové fotografie brzy přibudou.</p>';
        if ($nove = $web->nejnovejsi(12)) $obsah .= '<section class="sekce">' . $nadpisSekce('Nejnovější fotografie') . $mrizka($nove, true) . '</section>';
        $obsah .= $banner('banner2') . '</div>';
        break;

    case 'o-galerii':
        $titulek = 'O galerii';
        $text = trim((string)$n['o_galerii']);
        $obsah .= '<div class="obsah uzky">' . $nadpisSekce('O galerii', '', 'h1')
            . '<div class="text">' . ($text !== '' ? (new Markdown(fn(string $id) => '?s=' . rawurlencode($id)))->html($text) : '') . '</div></div>';
        break;

    default:
        $kod = 404;
}
if ($kod === 404) {
    $titulek = 'Stránka nenalezena';
    $aktivni = '';
    $plochy = [];
    $obsah = '<div class="obsah uzky">' . $nadpisSekce('Stránka nenalezena', 'Tahle stránka neexistuje nebo už není veřejná.', 'h1')
        . '<p><a class="tlacitko" href="./">Zpět na hlavní stránku</a></p></div>';
}

// ---------- rozvržení ----------
http_response_code($kod);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=60');
header('X-Frame-Options: SAMEORIGIN');
header('Content-Security-Policy: ' . Web::csp(Web::maKod($n, $plochy)));
if ($n['skryt_vyhledavacum']) header('X-Robots-Tag: noindex, nofollow');
$v = $h($app->verze);
$menu = ['domu' => ['./', 'Domů'], 'galerie' => ['?s=galerie', 'Galerie'], 'o-galerii' => ['?s=o-galerii', 'O galerii']];
$nav = '';
foreach ($menu as $k => [$href, $text]) $nav .= '<a href="' . $h($href) . '"' . ($aktivni === $k ? ' class="aktivni" aria-current="page"' : '') . '>' . $h($text) . '</a>';
$admin = $n['odkaz_administrace'];
$popisStranky = $n['podnadpis'] !== '' ? $n['podnadpis'] : $n['nadpis'];
?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($titulek !== '' ? $titulek . ' – ' . $n['nazev'] : $n['nazev'] . ' – ' . $n['nadpis']) ?></title>
<meta name="description" content="<?= $h($popisStranky) ?>">
<?php if ($n['skryt_vyhledavacum']): ?><meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<link rel="stylesheet" href="assets/verejne.css?v=<?= $v ?>">
<link rel="icon" href="assets/ikona.svg" type="image/svg+xml">
<script src="assets/verejne.js?v=<?= $v ?>" defer></script>
<?php if ($n['kod_hlavicka'] !== ''): ?>
<?= $n['kod_hlavicka'] ?>

<?php endif; ?>
</head>
<body>
<header class="hlavicka">
  <div class="hlavicka-obal">
    <a class="logo" href="./"><span class="logo-znak"><?= $ikona('logo', 34) ?></span><span class="logo-nazev"><?= $h($n['nazev']) ?></span></a>
    <button type="button" class="menu-tlacitko" aria-label="Menu" aria-expanded="false" aria-controls="hlavni-menu" data-menu><?= $ikona('menu', 24) ?></button>
    <nav id="hlavni-menu" class="menu" aria-label="Hlavní menu"><?= $nav ?>
      <?php if ($admin): ?><a class="tlacitko admin" href="index.php?p=prehled"><?= $ikona('prihlasit', 18) ?><span>Přihlášení do administrace</span></a><?php endif; ?>
    </nav>
  </div>
</header>
<main>
<?= $obsah ?>
</main>
<footer class="paticka">
  <div class="paticka-obal">
    <span><?= $h($n['patka']) ?></span>
    <?php if ($admin): ?><a href="index.php?p=prehled"><?= $ikona('prihlasit', 16) ?><span>Přihlášení do administrace</span></a><?php endif; ?>
  </div>
</footer>
</body>
</html>
