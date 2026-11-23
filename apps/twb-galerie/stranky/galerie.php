<?php
declare(strict_types=1);
// Galerie: nahrávání (po částech, přetažením), filtry, zobrazení mřížka / seznam / jen média, větší náhled v okně, hromadné akce.
// Proměnné: $app, $ui, $post.
use MediaGalerie\{App, Ui, Chyba, Media, Komprese};

$galerie = $app->galerie();
$media = $app->media();
$kategorie = $app->kategorie()->volby();

// filtr z adresy (zůstává i po hromadné akci)
$f = [
    'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
    'druh' => isset(Media::DRUHY[$_GET['druh'] ?? '']) ? (string)$_GET['druh'] : '',
    'kategorie' => isset($_GET['kategorie']) && $_GET['kategorie'] !== '' && (isset($kategorie[(int)$_GET['kategorie']]) || (string)$_GET['kategorie'] === '0') ? (string)(int)$_GET['kategorie'] : '',
    'tag' => mb_substr(trim((string)($_GET['tag'] ?? '')), 0, 30),
    'archiv' => !empty($_GET['archiv']) ? '1' : '',
    'doporucene' => !empty($_GET['doporucene']) ? '1' : '',
    'razeni' => isset(Media::RAZENI[$_GET['razeni'] ?? '']) ? (string)$_GET['razeni'] : 'nove',
    'strana' => max(1, (int)($_GET['strana'] ?? 1)),
];
// zobrazení: ?zobrazeni= ho přepne a zapamatuje (místní nastavení), jinak platí naposledy zvolené
$zobrazeni = (string)($_GET['zobrazeni'] ?? '');
if (isset(Media::ZOBRAZENI[$zobrazeni])) $app->nastaveni()->uloz('galerie_zobrazeni', $zobrazeni);
else $zobrazeni = (string)$app->nastaveni()->dej('galerie_zobrazeni', 'mrizka');
if (!isset(Media::ZOBRAZENI[$zobrazeni])) $zobrazeni = 'mrizka';
$zpet = Ui::odkaz('galerie', array_filter($f, fn($v) => $v !== '' && $v !== 'nove' && $v !== 1));

if ($post) {
    $akce = (string)($_POST['akce'] ?? '');
    $zprava = 'Uloženo.';
    if ($ui->zpracuj(function () use ($akce, $app, $galerie, $media, $ui, $zpet, &$zprava) {
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
        if (!$ids) throw new Chyba('Vyberte aspoň jedno médium.');
        switch ($akce) {
            case 'kategorie':
                $k = (string)($_POST['kategorie'] ?? '');
                $n = $media->nastavKategorii($ids, $k === '' ? null : (int)$k);
                $zprava = "Kategorie změněna u {$n} médií.";
                break;
            case 'tag-pridat':
            case 'tag-odebrat':
                $tag = trim((string)($_POST['tag'] ?? ''));
                if ($tag === '') throw new Chyba('Zadejte tag.');
                $n = $akce === 'tag-pridat' ? $galerie->tagy()->hromadne($ids, [$tag], []) : $galerie->tagy()->hromadne($ids, [], [$tag]);
                $zprava = ($akce === 'tag-pridat' ? 'Tag přidán' : 'Tag odebrán') . " u {$n} médií.";
                break;
            case 'doporucit':
            case 'nedoporucit':
                $n = $media->nastavDoporucene($ids, $akce === 'doporucit');
                $zprava = $akce === 'doporucit' ? "Označeno jako doporučené: {$n}." : "Doporučení zrušeno: {$n}.";
                break;
            case 'archivovat':
            case 'vratit':
                $n = count($galerie->archivuj($ids, $akce === 'archivovat'));
                $zprava = $akce === 'archivovat' ? "Do archivu přesunuto: {$n}." : "Z archivu vráceno: {$n}.";
                break;
            case 'komprimovat':
                // po krocích na stránce Komprimovat obrázky (časový limit sdíleného hostingu)
                $app->komprese()->zaloz($ids, Komprese::volby($_POST), $zpet);
                $ui->presmeruj(Ui::odkaz('komprese'));
            case 'smazat':
                $n = count($galerie->smaz($ids));
                $media->uklidNahledy();
                $zprava = "Smazáno: {$n}.";
                break;
            default:
                throw new Chyba('Neznámá akce.');
        }
    })) {
        $ui->zprava('ok', $zprava);
        $ui->presmeruj($zpet);
    }
}

$vysledek = $media->seznam($f);
$tagy = $galerie->tagy()->vsechny();
$nazvyKategorii = $kategorie;
$odkazFiltru = fn(array $zmena) => Ui::h(Ui::odkaz('galerie', array_filter($zmena + $f, fn($v) => $v !== '' && $v !== 'nove' && $v !== 1)));
$titulek = $f['archiv'] ? 'Archiv' : 'Galerie';
?>
<?php if (!$f['archiv']): ?>
<div class="karta nahravani" id="nahravani" data-csrf="<?= Ui::h($app->relace()->csrfToken()) ?>" data-cast="<?= App::CAST_NAHRANI ?>" data-max="<?= App::MAX_NAHRANI ?>">
  <div class="nahravani-sloupce">
    <div class="nahravani-plocha" data-plocha>
      <p><strong>Přetáhněte sem soubory</strong> nebo <label class="odkaz-tlacitko">vyberte z počítače<input type="file" multiple hidden data-vyber
        accept="<?= Ui::h(implode(',', array_map(fn($p) => '.' . $p, [...array_keys(TWB\Galerie\Galerie::POVOLENE), 'zip']))) ?>"></label></p>
      <p class="slabe mala" title="Obrázky JPG, PNG, GIF, WebP, BMP; videa MP4, MOV, WebM, AVI, MKV; PDF. ZIP s obrázky se rozbalí, každý obrázek bude samostatné médium. Název, štítky, autor a licence se načtou z metadat obrázku.">Obrázky, videa, PDF a ZIP s obrázky, nejvýš <?= (int)(App::MAX_NAHRANI / 1048576) ?> MB na soubor.</p>
    </div>
    <div class="nahravani-volby">
      <?= Ui::vyber('Nahrát do kategorie', 'nahrat_kategorie', ['' => '— bez kategorie —'] + $kategorie, $f['kategorie'] !== '0' ? $f['kategorie'] : '') ?>
      <?= Ui::pole('Tagy nových souborů', 'nahrat_tagy', '', ['placeholder' => 'Napište tag a stiskněte Enter', 'list' => 'seznam-tagu', 'data-stitky' => '']) ?>
    </div>
  </div>
  <ul class="nahravani-seznam" data-seznam></ul>
</div>
<?php endif; ?>
<datalist id="seznam-tagu"><?php foreach ($tagy as $t): ?><option value="<?= Ui::h($t['name']) ?>"><?php endforeach; ?></datalist>

<form method="get" class="filtr karta">
  <input type="hidden" name="p" value="galerie">
  <?php if ($f['archiv']): ?><input type="hidden" name="archiv" value="1"><?php endif; ?>
  <input type="search" name="q" value="<?= Ui::h($f['q']) ?>" placeholder="Hledat v názvu, tazích, autorovi a poznámce" aria-label="Hledat">
  <select name="druh" aria-label="Druh"><option value="">Všechny druhy</option>
    <?php foreach (Media::DRUHY as $k => $t): ?><option value="<?= $k ?>"<?= $f['druh'] === $k ? ' selected' : '' ?>><?= Ui::h($t) ?></option><?php endforeach; ?></select>
  <select name="kategorie" aria-label="Kategorie"><option value="">Všechny kategorie</option><option value="0"<?= $f['kategorie'] === '0' ? ' selected' : '' ?>>Bez kategorie</option>
    <?php foreach ($kategorie as $id => $n): ?><option value="<?= $id ?>"<?= $f['kategorie'] === (string)$id ? ' selected' : '' ?>><?= Ui::h($n) ?></option><?php endforeach; ?></select>
  <select name="tag" aria-label="Tag"><option value="">Všechny tagy</option>
    <?php foreach ($tagy as $t): ?><option value="<?= Ui::h($t['name']) ?>"<?= mb_strtolower($f['tag']) === mb_strtolower($t['name']) ? ' selected' : '' ?>><?= Ui::h($t['name']) ?> (<?= (int)$t['count'] ?>)</option><?php endforeach; ?></select>
  <label class="zaskrtavatko mala" title="Jen fotografie označené jako doporučená (veřejný web z nich ukazuje náhodný výběr)"><input type="checkbox" name="doporucene" value="1"<?= $f['doporucene'] ? ' checked' : '' ?>> <span>Jen doporučené</span></label>
  <select name="razeni" aria-label="Řazení">
    <?php foreach (Media::RAZENI as $k => $t): ?><option value="<?= $k ?>"<?= $f['razeni'] === $k ? ' selected' : '' ?>><?= Ui::h($t) ?></option><?php endforeach; ?></select>
  <button type="submit">Filtrovat</button>
  <a class="tlacitko maly" href="<?= Ui::h($f['archiv'] ? Ui::odkaz('galerie') : Ui::odkaz('galerie', ['archiv' => 1])) ?>"><?= $f['archiv'] ? 'Zpět do galerie' : 'Archiv' ?></a>
</form>

<?php if (!$vysledek['polozky']): ?>
  <div class="prazdne"><?= $f['q'] !== '' || $f['druh'] !== '' || $f['kategorie'] !== '' || $f['tag'] !== '' || $f['doporucene'] !== '' ? 'Filtru nic neodpovídá.' : ($f['archiv'] ? 'Archiv je prázdný.' : 'Galerie je prázdná. Nahrajte první soubory.') ?></div>
<?php else: ?>
<form method="post" id="hromadne" action="<?= Ui::h($zpet) ?>">
  <?= $ui->csrf() ?>
  <div class="hromadne karta" data-hromadne>
    <label class="zaskrtavatko"><input type="checkbox" data-vybrat-vse> <span>Vybrat vše na stránce</span></label>
    <span class="slabe mala" data-pocet-vybranych>Nic nevybráno</span>
    <select name="akce" aria-label="Hromadná akce" data-hromadna-akce>
      <option value="">Akce s vybranými…</option>
      <option value="kategorie">Přesunout do kategorie</option>
      <option value="tag-pridat">Přidat tag</option>
      <option value="tag-odebrat">Odebrat tag</option>
      <option value="doporucit">Označit jako doporučené</option>
      <option value="nedoporucit">Zrušit doporučení</option>
      <?php if ($f['archiv']): ?><option value="vratit">Vrátit z archivu</option><?php else: ?><option value="archivovat">Přesunout do archivu</option><?php endif; ?>
      <option value="komprimovat">Komprimovat obrázky</option>
      <option value="smazat">Smazat</option>
    </select>
    <select name="kategorie" aria-label="Kategorie" data-pro="kategorie" hidden><option value="">— bez kategorie —</option>
      <?php foreach ($kategorie as $id => $n): ?><option value="<?= $id ?>"><?= Ui::h($n) ?></option><?php endforeach; ?></select>
    <input name="tag" list="seznam-tagu" placeholder="Tag" aria-label="Tag" data-pro="tag-pridat tag-odebrat" hidden>
    <span class="komprese-volby" data-pro="komprimovat" hidden>
      <select name="komprese_format" aria-label="Formát" title="Formát výsledku. JPEG: obrázky s průhledností zůstanou PNG. WebP: menší soubory, průhlednost zůstane.">
        <?php foreach (Komprese::FORMATY as $k => $t): if ($k === 'webp' && !function_exists('imagewebp')) continue; ?><option value="<?= $k ?>"<?= $k === Komprese::VYCHOZI['format'] ? ' selected' : '' ?>><?= Ui::h($t) ?></option><?php endforeach; ?></select>
      <label class="mala" title="Kvalita 40–95 (doporučeno 75–85)">Kvalita <input type="number" name="komprese_kvalita" min="40" max="95" value="<?= Komprese::VYCHOZI['kvalita'] ?>" style="width:5.5em"></label>
      <select name="komprese_rozmer" aria-label="Největší rozměr" title="Delší strana po zmenšení">
        <?php foreach (Komprese::ROZMERY as $k => $t): ?><option value="<?= $k ?>"<?= $k === Komprese::VYCHOZI['rozmer'] ? ' selected' : '' ?>><?= Ui::h($k ? 'Nejvýš ' . $t : $t) ?></option><?php endforeach; ?></select>
      <label class="zaskrtavatko mala" title="Originál zůstane na disku a v detailu média ho vrátíte. Místo na disku se tím neuvolní."><input type="checkbox" name="komprese_ponechat" value="1" checked> <span>Ponechat originál</span></label>
      <label class="zaskrtavatko mala" title="U JPEG zůstane v souboru EXIF, XMP, IPTC a barevný profil. Autor, licence a tagy v galerii zůstávají vždy."><input type="checkbox" name="komprese_metadata" value="1" checked> <span>Zachovat metadata</span></label>
    </span>
    <button type="submit" class="hlavni" data-hromadne-odeslat disabled>Provést</button>
  </div>
  <div class="galerie-lista">
    <p class="slabe mala"><?= Ui::cislo($vysledek['celkem']) ?> <?= $vysledek['celkem'] === 1 ? 'položka' : ($vysledek['celkem'] >= 2 && $vysledek['celkem'] <= 4 ? 'položky' : 'položek') ?>
      · více souborů vyberete tažením myši (s Ctrl nebo Shift přidáte k výběru) · kliknutím na náhled ho zvětšíte</p>
    <nav class="prepinac-zobrazeni" aria-label="Zobrazení">
      <?php foreach (Media::ZOBRAZENI as $k => $t): ?><a class="tlacitko maly<?= $zobrazeni === $k ? ' aktivni' : '' ?>" href="<?= $odkazFiltru(['zobrazeni' => $k]) ?>"<?= $zobrazeni === $k ? ' aria-current="true"' : '' ?> title="<?= Ui::h($t) ?>"><?= Ui::h($t) ?></a><?php endforeach; ?>
    </nav>
  </div>
  <div class="galerie zobrazeni-<?= $zobrazeni ?>" data-zobrazeni="<?= $zobrazeni ?>" data-okno-skupina>
    <?php foreach ($vysledek['polozky'] as $m): $id = (int)$m['id']; $url = $media->verejnaAdresa($m); $v = Ui::h(substr((string)$m['md5'], 0, 8)); ?>
    <figure<?= Ui::okno($m, $url) ?>>
      <label class="vyber-medium" title="Vybrat"><input type="checkbox" name="ids[]" value="<?= $id ?>" aria-label="Vybrat <?= Ui::h($m['original_name']) ?>"></label>
      <a class="nahled-medium" href="<?= Ui::h(Ui::odkaz('medium', ['id' => $id])) ?>" data-otevrit-okno title="<?= Ui::h($m['original_name']) ?>">
        <?php if ($m['kind'] === 'image'): ?><img src="media.php?id=<?= $id ?>&amp;nahled=1&amp;v=<?= $v ?>" alt="" loading="lazy">
        <?php elseif ($m['kind'] === 'video'): ?><video src="media.php?id=<?= $id ?>#t=0.5" preload="metadata" muted playsinline></video><span class="znacka">Video</span>
        <?php else: ?><span class="pdf-ikona">PDF</span><?php endif; ?>
      </a>
      <figcaption>
        <a href="<?= Ui::h(Ui::odkaz('medium', ['id' => $id])) ?>"><strong><?= Ui::h($m['original_name']) ?></strong></a>
        <span class="slabe"><?= Ui::h(Media::popis($m)) ?><span class="jen-seznam"> · <?= Ui::h(Media::DRUH_JEDNOTNE[$m['kind']] ?? $m['kind']) ?> · <?= Ui::h(date('j. n. Y', (int)strtotime((string)$m['created_at']))) ?></span></span>
        <?php if ($m['featured']): ?><span><a class="stitek stitek-doporucena" href="<?= $odkazFiltru(['doporucene' => '1', 'strana' => 1]) ?>" title="Doporučená fotografie pro veřejný web">★ Doporučená</a></span><?php endif; ?>
        <?php if ($m['category_id'] && isset($nazvyKategorii[(int)$m['category_id']])): ?>
          <span><a class="stitek stitek-kategorie" href="<?= $odkazFiltru(['kategorie' => (string)$m['category_id'], 'strana' => 1]) ?>"><?= Ui::h($nazvyKategorii[(int)$m['category_id']]) ?></a></span>
        <?php endif; ?>
        <?php if ($t = Media::tagy($m)): ?><span class="stitky"><?php foreach ($t as $tag): ?><a class="stitek" href="<?= $odkazFiltru(['tag' => $tag, 'strana' => 1]) ?>"><?= Ui::h($tag) ?></a><?php endforeach; ?></span><?php endif; ?>
        <?php if ($url): ?><span><button type="button" class="maly" data-kopirovat-text="<?= Ui::h($url) ?>">Kopírovat odkaz</button></span><?php endif; ?>
      </figcaption>
    </figure>
    <?php endforeach; ?>
  </div>
</form>
<?php if ($vysledek['stran'] > 1): ?>
<nav class="strankovani tlacitka" aria-label="Stránky">
  <?php for ($i = 1; $i <= $vysledek['stran']; $i++): ?>
    <a class="tlacitko maly<?= $i === $vysledek['strana'] ? ' aktivni' : '' ?>" href="<?= $odkazFiltru(['strana' => $i]) ?>"<?= $i === $vysledek['strana'] ? ' aria-current="page"' : '' ?>><?= $i ?></a>
  <?php endfor; ?>
</nav>
<?php endif; ?>
<?php endif;
