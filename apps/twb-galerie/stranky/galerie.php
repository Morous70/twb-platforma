<?php
declare(strict_types=1);
// Galerie: nahrávání (po částech, přetažením), filtry, mřížka s náhledy, hromadné akce. Proměnné: $app, $ui, $post.
use MediaGalerie\{App, Ui, Chyba, Media};

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
    'razeni' => isset(Media::RAZENI[$_GET['razeni'] ?? '']) ? (string)$_GET['razeni'] : 'nove',
    'strana' => max(1, (int)($_GET['strana'] ?? 1)),
];
$zpet = Ui::odkaz('galerie', array_filter($f, fn($v) => $v !== '' && $v !== 'nove' && $v !== 1));

if ($post) {
    $akce = (string)($_POST['akce'] ?? '');
    $zprava = 'Uloženo.';
    if ($ui->zpracuj(function () use ($akce, $app, $galerie, $media, &$zprava) {
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
            case 'archivovat':
            case 'vratit':
                $n = count($galerie->archivuj($ids, $akce === 'archivovat'));
                $zprava = $akce === 'archivovat' ? "Do archivu přesunuto: {$n}." : "Z archivu vráceno: {$n}.";
                break;
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
  <div class="nahravani-plocha" data-plocha>
    <p><strong>Přetáhněte sem soubory</strong> nebo <label class="odkaz-tlacitko">vyberte z počítače<input type="file" multiple hidden data-vyber
      accept="<?= Ui::h(implode(',', array_map(fn($p) => '.' . $p, array_keys(TWB\Galerie\Galerie::POVOLENE)))) ?>"></label></p>
    <p class="slabe mala">Obrázky (JPG, PNG, GIF, WebP, BMP), videa (MP4, MOV, WebM, AVI, MKV) a PDF, jeden soubor nejvýš <?= (int)(App::MAX_NAHRANI / 1048576) ?> MB.</p>
  </div>
  <div class="radek">
    <?= Ui::vyber('Nahrát do kategorie', 'nahrat_kategorie', ['' => '— bez kategorie —'] + $kategorie, $f['kategorie'] !== '0' ? $f['kategorie'] : '') ?>
    <?= Ui::pole('Tagy nových souborů', 'nahrat_tagy', '', ['placeholder' => 'oddělte čárkou', 'list' => 'seznam-tagu']) ?>
  </div>
  <ul class="nahravani-seznam" data-seznam></ul>
</div>
<?php endif; ?>
<datalist id="seznam-tagu"><?php foreach ($tagy as $t): ?><option value="<?= Ui::h($t['name']) ?>"><?php endforeach; ?></datalist>

<form method="get" class="filtr karta">
  <input type="hidden" name="p" value="galerie">
  <?php if ($f['archiv']): ?><input type="hidden" name="archiv" value="1"><?php endif; ?>
  <input type="search" name="q" value="<?= Ui::h($f['q']) ?>" placeholder="Hledat v názvu a poznámce" aria-label="Hledat">
  <select name="druh" aria-label="Druh"><option value="">Všechny druhy</option>
    <?php foreach (Media::DRUHY as $k => $t): ?><option value="<?= $k ?>"<?= $f['druh'] === $k ? ' selected' : '' ?>><?= Ui::h($t) ?></option><?php endforeach; ?></select>
  <select name="kategorie" aria-label="Kategorie"><option value="">Všechny kategorie</option><option value="0"<?= $f['kategorie'] === '0' ? ' selected' : '' ?>>Bez kategorie</option>
    <?php foreach ($kategorie as $id => $n): ?><option value="<?= $id ?>"<?= $f['kategorie'] === (string)$id ? ' selected' : '' ?>><?= Ui::h($n) ?></option><?php endforeach; ?></select>
  <select name="tag" aria-label="Tag"><option value="">Všechny tagy</option>
    <?php foreach ($tagy as $t): ?><option value="<?= Ui::h($t['name']) ?>"<?= mb_strtolower($f['tag']) === mb_strtolower($t['name']) ? ' selected' : '' ?>><?= Ui::h($t['name']) ?> (<?= (int)$t['count'] ?>)</option><?php endforeach; ?></select>
  <select name="razeni" aria-label="Řazení">
    <?php foreach (Media::RAZENI as $k => $t): ?><option value="<?= $k ?>"<?= $f['razeni'] === $k ? ' selected' : '' ?>><?= Ui::h($t) ?></option><?php endforeach; ?></select>
  <button type="submit">Filtrovat</button>
  <a class="tlacitko maly" href="<?= Ui::h($f['archiv'] ? Ui::odkaz('galerie') : Ui::odkaz('galerie', ['archiv' => 1])) ?>"><?= $f['archiv'] ? 'Zpět do galerie' : 'Archiv' ?></a>
</form>

<?php if (!$vysledek['polozky']): ?>
  <div class="prazdne"><?= $f['q'] !== '' || $f['druh'] !== '' || $f['kategorie'] !== '' || $f['tag'] !== '' ? 'Filtru nic neodpovídá.' : ($f['archiv'] ? 'Archiv je prázdný.' : 'Galerie je prázdná. Nahrajte první soubory.') ?></div>
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
      <?php if ($f['archiv']): ?><option value="vratit">Vrátit z archivu</option><?php else: ?><option value="archivovat">Přesunout do archivu</option><?php endif; ?>
      <option value="smazat">Smazat</option>
    </select>
    <select name="kategorie" aria-label="Kategorie" data-pro="kategorie" hidden><option value="">— bez kategorie —</option>
      <?php foreach ($kategorie as $id => $n): ?><option value="<?= $id ?>"><?= Ui::h($n) ?></option><?php endforeach; ?></select>
    <input name="tag" list="seznam-tagu" placeholder="Tag" aria-label="Tag" data-pro="tag-pridat tag-odebrat" hidden>
    <button type="submit" class="hlavni" data-hromadne-odeslat disabled>Provést</button>
  </div>
  <p class="slabe mala"><?= Ui::cislo($vysledek['celkem']) ?> <?= $vysledek['celkem'] === 1 ? 'položka' : ($vysledek['celkem'] >= 2 && $vysledek['celkem'] <= 4 ? 'položky' : 'položek') ?></p>
  <div class="galerie">
    <?php foreach ($vysledek['polozky'] as $m): $id = (int)$m['id']; $url = $media->verejnaAdresa($m); ?>
    <figure>
      <label class="vyber-medium" title="Vybrat"><input type="checkbox" name="ids[]" value="<?= $id ?>" aria-label="Vybrat <?= Ui::h($m['original_name']) ?>"></label>
      <a class="nahled-medium" href="<?= Ui::h(Ui::odkaz('medium', ['id' => $id])) ?>">
        <?php if ($m['kind'] === 'image'): ?><img src="media.php?id=<?= $id ?>&amp;nahled=1&amp;v=<?= Ui::h(substr((string)$m['md5'], 0, 8)) ?>" alt="" loading="lazy">
        <?php elseif ($m['kind'] === 'video'): ?><video src="media.php?id=<?= $id ?>#t=0.5" preload="metadata" muted playsinline></video><span class="znacka">Video</span>
        <?php else: ?><span class="pdf-ikona">PDF</span><?php endif; ?>
      </a>
      <figcaption>
        <a href="<?= Ui::h(Ui::odkaz('medium', ['id' => $id])) ?>"><strong><?= Ui::h($m['original_name']) ?></strong></a>
        <span class="slabe"><?= Ui::h(Media::popis($m)) ?></span>
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
