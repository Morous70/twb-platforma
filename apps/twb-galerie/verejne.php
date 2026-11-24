<?php
declare(strict_types=1);
// Veřejný web galerie (0.8.0): ?s=domu (hlavní stránka), ?s=galerie (veřejné kategorie), ?s=galerie&k=<id-nazev> (fotky kategorie), ?s=o-galerii.
// Kořen domény ho otevře přes DirectoryIndex v .htaccess; index.php s parametrem s= (bez p=) sem předá také. Administrace zůstává na index.php.
// Ukazuje jen veřejné kategorie a v nich obrázky s veřejným odkazem mimo archiv (viz MediaGalerie\Web).
// 0.8.4: hlavní stránka ukazuje náhodný výběr doporučených fotografií (Web::doporucene) v mřížce sloupce × řádky.
// 0.8.3: reklamní pozice s kódem z reklamního systému, měřicí kód, cookie lišta se souhlasem (bez souhlasu je kód jen neživý <template>),
// stránka ?s=zasady-ochrany-osobnich-udaju a ads.txt (?s=ads.txt; /ads.txt a /zasady-ochrany-osobnich-udaju přes .htaccess).
// 0.8.12: volitelný štítek „Reklama“ nad bannerem (každá pozice zvlášť) a vlastní CSS jako samostatný soubor ?s=vlastni.css.
// 0.8.15: výřezy fotek (úvod, mřížky, karty galerií) podle ohniska fotky nebo celá fotka s rozmazaným okolím (Web::fotkaHtml).
// 0.8.14: vyhledávání ?s=hledat&q=…[&q_rezim=kterykoli][&strana=N] – fulltext jako API (Web::hledej), pole v záhlaví místo tlačítka administrace.
use MediaGalerie\{App, Web, Media, VerejneRozvrzeni};
use TWB\Napoveda\Markdown;

/** @var App $app */
$app ??= require __DIR__ . '/lib/bootstrap.php';
if (!$app->nainstalovano()) { header('Location: install.php'); exit; }
$app->migrujPodlePotreby();
$web = $app->web();
$n = $web->nastaveni();
$stranka = (string)($_GET['s'] ?? 'domu');
// zásady a ads.txt fungují i s vypnutým webem (odkaz na zásady může vést odjinud, reklamní systém kontroluje ads.txt)
if (!$n['zapnuto'] && !in_array($stranka, [Web::ZASADY, 'ads.txt', Web::CSS], true)) { header('Location: index.php', true, 302); exit; }

$h = static fn(mixed $s): string => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$ikona = static fn(string $nazev, int $v = 20): string => VerejneRozvrzeni::ikona($nazev, $v);
$fotografii = static function (int $n): string {
    return $n . "\u{00A0}" . ($n === 1 ? 'fotografie' : ($n >= 2 && $n <= 4 ? 'fotografie' : 'fotografií'));
};
$odkazKategorie = static fn(array $k): string => '?s=galerie&k=' . rawurlencode(Web::slug($k));

if ($stranka === 'ads.txt') {
    // ads.txt pro reklamní systémy (AdSense…): prostý text z nastavení webu, bez něj 404
    http_response_code($n['ads_txt'] !== '' ? 200 : 404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo $n['ads_txt'] !== '' ? $n['ads_txt'] . "\n" : '';
    exit;
}
if ($stranka === Web::CSS) {
    // 0.8.12: vlastní CSS jako samostatný soubor – stačí CSP style-src 'self' a obsah nejde „ukončit“ a vložit do něj HTML ani skript.
    // Adresa nese otisk obsahu (Web::vlastniCssAdresa), proto se smí cachovat dlouho; bez CSS prázdný soubor s 404.
    $css = Web::vlastniCss($n);
    http_response_code($css !== '' ? 200 : 404);
    header('Content-Type: text/css; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: ' . ($css !== '' && isset($_GET['v']) ? 'public, max-age=31536000, immutable' : 'no-cache'));
    echo $css !== '' ? $css . "\n" : '';
    exit;
}
$kategorie = $web->kategorie();
$titulek = '';
$aktivni = $stranka;
$obsah = '';
$kod = 200;
$q = '';          // 0.8.14: hledaný text (předvyplní pole v záhlaví)
$noindex = false; // 0.8.14: stránku výsledků vyhledávače neindexují
$kodNaStrance = false;   // stránka obsahuje reklamní nebo měřicí kód (kvůli CSP)
$souhlas = Web::kategorieSouhlasu($n);   // na co se ptá cookie lišta; prázdné = kód se vkládá rovnou
/** Kód reklamy nebo měření: se souhlasem jako neživý <template> (oživí ho verejne.js po povolení), jinak rovnou. */
$vlozKod = static function (string $kod, string $kategorie) use ($souhlas, &$kodNaStrance): string {
    $kodNaStrance = true;
    return isset($souhlas[$kategorie]) ? '<template data-souhlas="' . $kategorie . '">' . $kod . '</template>' : $kod;
};

// ---------- části stránek ----------
// 0.8.12: štítek „Reklama“ je uvnitř vkládaného kódu – se souhlasem s cookies se tak ukáže až s reklamou, ne nad prázdným místem
$reklama = static function (string $pozice) use ($n, $vlozKod): string {
    $kod = Web::reklamaKod($n, $pozice);
    if ($kod === '') return '';
    $stitek = Web::reklamaStitek($n, $pozice) ? '<p class="reklama-stitek">Reklama</p>' : '';
    return '<aside class="reklama reklama-' . str_replace('_', '-', $pozice) . '" aria-label="Reklama">' . $vlozKod($stitek . $kod, 'reklama') . '</aside>';
};
$karty = static function (array $seznam) use ($web, $h, $ikona, $fotografii, $odkazKategorie): string {
    $out = '<div class="karty">';
    foreach ($seznam as $k) {
        $src = $k['obalka'] ? $web->obrazek($k['obalka'], Web::PX_NAHLED) : '';
        $out .= '<a class="karta" href="' . $h($odkazKategorie($k)) . '">'
            . '<span class="karta-obrazek">' . ($src !== '' ? Web::fotkaHtml($k['obalka'], $src, '', ' loading="lazy"') : '') . '</span>'
            . '<span class="karta-text"><strong>' . $h($k['name']) . '</strong><span class="karta-pocet">' . $ikona('obrazek', 16) . $h($fotografii((int)$k['pocet'])) . '</span>'
            . ($k['web_description'] ? '<span class="karta-popis">' . $h($k['web_description']) . '</span>' : '')
            . '</span><span class="karta-sipka">' . $ikona('dalsi') . '</span></a>';
    }
    return $out . '</div>';
};
/** Mřížka fotek s oknem náhledu (assets/verejne.js); bez JS odkaz vede na velký obrázek. */
// 0.8.5: obal je kontejner (container query) – počet sloupců volí CSS tak, aby se stránka (36, 12 fotek) dělila beze zbytku.
// $viditelne (doporučené): kolik fotek ukázat v každém pásmu šířky, aby zůstal nastavený počet řádků (Web::viditelneDoporucene).
$mrizka = static function (array $fotky, bool $sKategorii = false, string $trida = '', array $viditelne = []) use ($web, $h): string {
    $out = '<div class="fotky-obal"><div class="fotky' . ($trida !== '' ? ' ' . $trida : '') . '" data-fotky>';
    foreach (array_values($fotky) as $i => $m) {
        $skryt = implode('', array_map(fn($p) => ' skryt-' . $p, array_keys(array_filter($viditelne, fn($n) => $i >= $n))));
        $uv = Media::uvedeni($m)['text'];
        $popis = implode(' · ', array_filter([$sKategorii ? (string)($m['kategorie_nazev'] ?? '') : '', $uv]));
        $out .= '<a class="foto' . $skryt . '" href="' . $h($web->obrazek($m, Web::PX_VELKY)) . '" data-foto data-titulek="' . $h(Web::titulek($m)) . '" data-popis="' . $h($popis) . '">'
            . Web::fotkaHtml($m, $web->obrazek($m, Web::PX_NAHLED), '', ' alt="' . $h(Web::titulek($m)) . '" loading="lazy"'
                . ($m['width'] ? ' width="' . (int)$m['width'] . '" height="' . (int)$m['height'] . '"' : '')) . '</a>';
    }
    return $out . '</div></div>';
};
$nadpisSekce = static fn(string $nadpis, string $pod = '', string $uroven = 'h2'): string => '<header class="sekce-nadpis"><' . $uroven . '>' . $h($nadpis) . '</' . $uroven . '>'
    . ($pod !== '' ? '<p>' . $h($pod) . '</p>' : '') . '</header>';

// ---------- stránky ----------
switch ($stranka) {
    case 'domu':
        $uvod = $web->uvodni($kategorie);   // 0.8.11: náhodně jedna z až pěti úvodních fotek
        $foto = $uvod ? $web->obrazek($uvod, Web::PX_VELKY) : '';
        $obsah .= '<section class="hero' . ($foto === '' ? ' hero-bez-fotky' : '') . '">'
            . ($foto !== '' ? Web::fotkaHtml($uvod, $foto, 'hero-fotka', ' fetchpriority="high"') : '')   // 0.8.15: ohnisko / celá fotka
            . '<div class="hero-text">'
            . ($n['stitek'] !== '' ? '<p class="nadtitulek">' . $h($n['stitek']) . '</p>' : '')
            . '<h1>' . $h($n['nadpis']) . '</h1>'
            . ($n['podnadpis'] !== '' ? '<p class="podtitulek">' . $h($n['podnadpis']) . '</p>' : '')
            . '<a class="tlacitko velke" href="?s=galerie">' . $ikona('obrazek', 22) . '<span>' . $h($n['tlacitko']) . '</span>' . $ikona('dalsi', 18) . '</a>'
            . ($n['poznamka'] !== '' ? '<p class="poznamka">' . $ikona('foto', 18) . '<em>' . $h($n['poznamka']) . '</em></p>' : '')
            . '</div></section>';
        $obsah .= '<div class="obsah">' . $reklama('pod_uvodem');
        $vybrane = $n['vybrane_pocet'] > 0 ? array_slice($kategorie, 0, (int)$n['vybrane_pocet']) : [];
        if ($vybrane) {
            $obsah .= '<section class="sekce">' . $nadpisSekce($n['vybrane_nadpis'], $n['vybrane_podnadpis']) . $karty($vybrane)
                . (count($kategorie) > count($vybrane) ? '<p class="vice"><a href="?s=galerie">Všechny galerie' . $ikona('dalsi', 16) . '</a></p>' : '') . '</section>';
        } elseif (!$kategorie) {
            $obsah .= '<p class="prazdne">Galerie se připravuje. Nové fotografie brzy přibudou.</p>';
        }
        // 0.8.9: reklama Před fotografiemi – nad doporučenými fotografiemi (bez nich na stejném místě před patičkou)
        $obsah .= $reklama('pod_galeriemi');
        if ($doporucene = $web->doporucene()) {
            // 0.8.4: náhodný výběr z doporučených; mřížka sloupce × řádky (třídy v assets/verejne.css, CSP nepovoluje style="")
            $obsah .= '<section class="sekce sekce-doporucene">' . $nadpisSekce($n['doporucene_nadpis'], $n['doporucene_podnadpis'])
                . $mrizka($doporucene, true, 'doporucene sloupce-' . (int)$n['doporucene_sloupce'],
                    Web::viditelneDoporucene(count($doporucene), (int)$n['doporucene_sloupce'], (int)$n['doporucene_radky'])) . '</section>';
        }
        $obsah .= $reklama('pred_patickou') . '</div>';
        break;

    case 'galerie':
        $kId = (int)($_GET['k'] ?? 0);
        if ($kId > 0) {
            $k = $web->jednaKategorie($kId);
            if (!$k) { $kod = 404; break; }
            $titulek = (string)$k['name'];
            $f = $web->fotky($kId, (int)($_GET['strana'] ?? 1));
            $obsah .= '<div class="obsah"><nav class="drobecky" aria-label="Drobečková navigace"><a href="?s=galerie">Galerie</a> <span aria-hidden="true">›</span> <span>' . $h($k['name']) . '</span></nav>'
                . $nadpisSekce((string)$k['name'], ($k['web_description'] ? $k['web_description'] . ' · ' : '') . $fotografii($f['celkem']), 'h1') . $reklama('pod_uvodem');
            $obsah .= $f['polozky'] ? $mrizka($f['polozky']) : '<p class="prazdne">V této galerii zatím nejsou žádné fotografie.</p>';
            if ($f['stran'] > 1) {
                $obsah .= '<nav class="strankovani" aria-label="Stránky">';
                for ($i = 1; $i <= $f['stran']; $i++) {
                    $obsah .= $i === $f['strana'] ? '<span aria-current="page">' . $i . '</span>' : '<a href="' . $h($odkazKategorie($k) . '&strana=' . $i) . '">' . $i . '</a>';
                }
                $obsah .= '</nav>';
            }
            $obsah .= $reklama('pred_patickou') . '</div>';
            break;
        }
        $titulek = 'Galerie';
        $obsah .= '<div class="obsah">' . $nadpisSekce('Galerie', $kategorie ? 'Všechny veřejné galerie.' : '', 'h1') . $reklama('pod_uvodem');
        $obsah .= $kategorie ? $karty($kategorie) : '<p class="prazdne">Galerie se připravuje. Nové fotografie brzy přibudou.</p>';
        $obsah .= $reklama('pod_galeriemi');   // 0.8.9: před nejnovějšími fotografiemi
        if ($nove = $web->nejnovejsi(12)) $obsah .= '<section class="sekce">' . $nadpisSekce('Nejnovější fotografie') . $mrizka($nove, true) . '</section>';
        $obsah .= $reklama('pred_patickou') . '</div>';
        break;

    case 'hledat':
        // 0.8.14: fulltext jako v API (q, q_rezim) – jen to, co web smí ukázat (veřejné kategorie, veřejný odkaz, mimo archiv)
        $q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, Web::MAX_DOTAZ, 'UTF-8'));
        $vse = !in_array($_GET['q_rezim'] ?? 'vse', ['kterykoli', 'any'], true);
        $noindex = true;
        $aktivni = '';
        $titulek = $q !== '' ? 'Hledání: ' . $q : 'Hledání';
        $f = $web->hledej($q, $vse, (int)($_GET['strana'] ?? 1));
        $adresa = static fn(array $navic = []): string => '?' . http_build_query(['s' => 'hledat', 'q' => $q] + ($vse ? [] : ['q_rezim' => 'kterykoli']) + $navic);
        $pod = !$f['vyrazy'] ? 'Zadejte, co hledáte – název, štítek, autora nebo popis fotografie.'
            : ($f['celkem'] > 0 ? 'Nalezeno ' . $fotografii($f['celkem']) . '.' : '');
        $obsah .= '<div class="obsah">' . $nadpisSekce($q !== '' ? 'Výsledky hledání „' . $q . '“' : 'Hledání', $pod, 'h1')
            . '<div class="hledani-stranka">' . VerejneRozvrzeni::hledaciPole($q, 'hledani-velke', 'hledat-stranka');
        if (count($f['vyrazy']) > 1) {
            // stejné jako q_rezim v API: všechna slova (výchozí) nebo kterékoli
            $obsah .= '<p class="hledani-rezim" role="group" aria-label="Hledaná slova">';
            foreach (['vse' => 'Všechna slova', 'kterykoli' => 'Kterékoli slovo'] as $r => $text) {
                $href = '?' . http_build_query(['s' => 'hledat', 'q' => $q] + ($r === 'vse' ? [] : ['q_rezim' => 'kterykoli']));
                $obsah .= ($r === 'vse') === $vse ? '<span aria-current="true">' . $h($text) . '</span>' : '<a href="' . $h($href) . '">' . $h($text) . '</a>';
            }
            $obsah .= '</p>';
        }
        $obsah .= '</div>' . $reklama('pod_uvodem');
        if ($f['polozky']) {
            $obsah .= $mrizka($f['polozky'], true);
            if ($f['stran'] > 1) {
                $obsah .= '<nav class="strankovani" aria-label="Stránky">';
                for ($i = 1; $i <= $f['stran']; $i++) {
                    $obsah .= $i === $f['strana'] ? '<span aria-current="page">' . $i . '</span>' : '<a href="' . $h($adresa(['strana' => $i])) . '">' . $i . '</a>';
                }
                $obsah .= '</nav>';
            }
        } elseif ($f['vyrazy']) {
            $obsah .= '<div class="prazdne hledani-nic"><p><strong>Nic jsme nenašli.</strong> Zkuste jiné nebo kratší slovo, na diakritice ani velikosti písmen nezáleží.</p>'
                . ($vse && count($f['vyrazy']) > 1 ? '<p><a href="' . $h('?' . http_build_query(['s' => 'hledat', 'q' => $q, 'q_rezim' => 'kterykoli'])) . '">Hledat kterékoli ze slov</a></p>' : '')
                . '<p><a class="tlacitko" href="?s=galerie">' . $ikona('obrazek', 18) . '<span>Prohlédnout galerie</span></a></p></div>';
        }
        $obsah .= $reklama('pred_patickou') . '</div>';
        break;

    case 'o-galerii':
        $titulek = 'O galerii';
        $text = trim((string)$n['o_galerii']);
        $obsah .= '<div class="obsah">' . $nadpisSekce('O galerii', '', 'h1')   // 0.8.9: stejná šířka jako ostatní stránky
            . '<div class="text">' . ($text !== '' ? (new Markdown(fn(string $id) => '?s=' . rawurlencode($id)))->html($text) : '') . '</div>'
            . $reklama('pred_patickou') . '</div>';
        break;

    case Web::ZASADY:
        if (!$n['zasady_zapnuto']) { $kod = 404; break; }
        $titulek = 'Zásady ochrany osobních údajů';
        $obsah .= '<div class="obsah uzky">' . $nadpisSekce($titulek, '', 'h1')
            . '<div class="text">' . (new Markdown(fn(string $id) => '?s=' . rawurlencode($id)))->html($web->textZasad($n)) . '</div>'
            . ($souhlas ? '<p><button type="button" class="tlacitko" data-cookies-nastaveni>Nastavení cookies</button></p>' : '') . '</div>';
        break;

    default:
        $kod = 404;
}
if ($kod === 404) {
    $titulek = 'Stránka nenalezena';
    $aktivni = '';
    $kodNaStrance = false;
    $obsah = '<div class="obsah uzky">' . $nadpisSekce('Stránka nenalezena', 'Tahle stránka neexistuje nebo už není veřejná.', 'h1')
        . '<p><a class="tlacitko" href="./">Zpět na hlavní stránku</a></p></div>';
}

// ---------- měření návštěvnosti a cookie lišta ----------
$mereni = Web::meri($n) ? $vlozKod((string)$n['mereni_kod'], 'mereni') . "\n" : '';
$mereniHlavicka = $n['mereni_umisteni'] === 'hlavicka' ? $mereni : '';
$mereniKonec = $n['mereni_umisteni'] === 'hlavicka' ? '' : $mereni;
$zasadyOdkaz = $n['zasady_zapnuto'] ? '?s=' . Web::ZASADY : '';
$lista = '';
if ($souhlas) {
    $co = implode(' a ', array_intersect_key(['mereni' => 'měření návštěvnosti', 'reklama' => 'reklamu'], $souhlas));
    $volby = '';
    foreach ($souhlas as $k => $nazev) {
        $popis = $k === 'mereni' ? 'Souhrnné statistiky návštěvnosti, podle kterých web zlepšujeme.' : 'Reklama z reklamních systémů, které mohou ukládat cookies a zpracovávat údaje o návštěvě.';
        $volby .= '<label class="cookies-volba"><input type="checkbox" name="' . $k . '" value="1"> <span><strong>' . $h($nazev) . '</strong> ' . $h($popis) . '</span></label>';
    }
    $lista = '<div class="cookies" data-cookies data-cookie="' . Web::COOKIE_SOUHLAS . '" data-kategorie="' . $h(implode(',', array_keys($souhlas))) . '" role="dialog" aria-live="polite" aria-label="Souhlas s cookies" hidden>'
        . '<div class="cookies-obal"><p class="cookies-text"><strong>Cookies</strong> Rádi bychom použili cookies pro ' . $h($co) . '. Bez vašeho souhlasu se nic z toho nenačte, '
        . 'web funguje i tak. Volbu můžete kdykoli změnit odkazem Nastavení cookies v patičce.'
        . ($zasadyOdkaz !== '' ? ' <a href="' . $h($zasadyOdkaz) . '">Zásady ochrany osobních údajů</a>' : '') . '</p>'
        . '<div class="cookies-volby" data-cookies-volby hidden><label class="cookies-volba"><input type="checkbox" checked disabled> <span><strong>Nezbytné</strong> Uložení vaší volby. Bez nich web nefunguje.</span></label>' . $volby . '</div>'
        . '<div class="cookies-tlacitka"><button type="button" class="tlacitko" data-cookies-akce="nastaveni">Nastavení</button>'
        . '<button type="button" class="tlacitko" data-cookies-akce="vyber" hidden>Uložit výběr</button>'
        . '<button type="button" class="tlacitko" data-cookies-akce="odmitnout">Odmítnout</button>'
        . '<button type="button" class="tlacitko hlavni" data-cookies-akce="vse">Přijmout vše</button></div></div></div>';
}

// ---------- rozvržení ----------
http_response_code($kod);
header('Content-Type: text/html; charset=utf-8');
// 0.8.11: hlavní stránka (náhodná úvodní fotka z až pěti, náhodné doporučené) se při každém načtení liší – prohlížeč ji vždy ověří u serveru
header('Cache-Control: ' . ($stranka === 'domu' ? 'no-cache' : 'public, max-age=60'));
header('X-Frame-Options: SAMEORIGIN');
header('Content-Security-Policy: ' . Web::csp($kodNaStrance));
if ($n['skryt_vyhledavacum']) header('X-Robots-Tag: noindex, nofollow');
elseif ($noindex) header('X-Robots-Tag: noindex, follow');
$v = $h($app->verze);
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
<?php elseif ($noindex): ?><meta name="robots" content="noindex, follow">
<?php endif; ?>
<link rel="stylesheet" href="assets/verejne.css?v=<?= $v ?>">
<?php if (($cssAdresa = Web::vlastniCssAdresa($n)) !== ''): ?><link rel="stylesheet" href="<?= $h($cssAdresa) ?>">
<?php endif; ?>
<link rel="icon" href="assets/ikona.svg" type="image/svg+xml">
<script src="assets/verejne.js?v=<?= $v ?>" defer></script>
<?= $mereniHlavicka ?>
</head>
<body>
<?= VerejneRozvrzeni::zahlavi($n, $aktivni, true, true, $web->logoAdresa(), $q) ?>
<main>
<?= $obsah ?>
</main>
<?= VerejneRozvrzeni::paticka($n, $zasadyOdkaz, (bool)$souhlas, (bool)$n['odkaz_administrace']) ?>
<?= $lista ?>
<?= $mereniKonec ?>
</body>
</html>
