<?php
declare(strict_types=1);
// Detail média: náhled, údaje, název, poznámka, kampaň, tagy, veřejný odkaz, nahrazení souboru, komprese, archiv, smazání.
// Proměnné: $app, $ui, $post.
use MediaGalerie\{App, Ui, Chyba, Media, Vstup};
use TWB\Zalohy\Zalohy;

$galerie = $app->galerie();
$media = $app->media();
$id = (int)($_GET['id'] ?? 0);
$m = $media->radek($id);
$odkaz = Ui::odkaz('medium', ['id' => $id]);

if ($post) {
    $akce = (string)($_POST['akce'] ?? '');
    $zprava = 'Uloženo.';
    $kam = $odkaz;
    if ($ui->zpracuj(function () use ($akce, $app, $galerie, $media, $id, $m, &$zprava, &$kam) {
        switch ($akce) {
            case 'ulozit':
                // nejdřív kontrola všech polí, pak zápis najednou (chyba v tagu nesmí uložit půlku formuláře)
                $nazev = Vstup::text($_POST, 'nazev', 'Název', 200);
                $pozn = Vstup::text($_POST, 'note', 'Poznámka', 5000, false);
                $k = (string)($_POST['kampan'] ?? '');
                $kampan = $k === '' ? null : (int)$k;
                if ($kampan !== null) $app->kampane()->radek($kampan);
                $tagy = $galerie->tagy()->vycistiSeznam((string)($_POST['tagy'] ?? ''));
                $app->db->transakce(function () use ($galerie, $media, $id, $m, $nazev, $pozn, $kampan, $tagy) {
                    if ($nazev !== $m['original_name']) $galerie->prejmenuj($id, $nazev);
                    $media->nastavPoznamku($id, $pozn);
                    $media->nastavKampan([$id], $kampan);
                    $galerie->tagy()->nastav($id, $tagy);
                });
                break;
            case 'odkaz-novy':
                $media->novyOdkaz($id);
                $zprava = 'Vytvořen nový veřejný odkaz. Předchozí odkaz už nefunguje.';
                break;
            case 'odkaz-zrusit':
                $media->zrusOdkaz($id);
                $zprava = 'Veřejný odkaz je vypnutý.';
                break;
            case 'komprimovat':
                $z = $galerie->komprimuj($id, true);
                $zprava = $z ? 'Zkomprimováno: ' . $z : 'Obrázek už je dost malý, komprese ho nezmenší.';
                if ($z) $app->log('info', 'Obrázek zkomprimován: ' . $z);
                break;
            case 'original':
                if (!$galerie->obnovPuvodni($id)) throw new Chyba('Původní soubor není k dispozici.');
                $app->log('info', "Obnoven původní soubor: {$m['original_name']}");
                $zprava = 'Vrácen původní soubor.';
                break;
            case 'archivovat':
            case 'vratit':
                $galerie->archivuj([$id], $akce === 'archivovat');
                $zprava = $akce === 'archivovat' ? 'Přesunuto do archivu. Veřejný odkaz dál funguje.' : 'Vráceno z archivu.';
                break;
            case 'smazat':
                $galerie->smaz([$id]);
                $media->uklidNahledy();
                $zprava = "Smazáno: {$m['original_name']}.";
                $kam = Ui::odkaz('galerie');
                break;
            default:
                throw new Chyba('Neznámá akce.');
        }
    })) {
        $ui->zprava('ok', $zprava);
        $ui->presmeruj($kam);
    }
}

$titulek = (string)$m['original_name'];
$url = $media->verejnaAdresa($m);
$kampane = $app->kampane()->volby();
$tagy = $galerie->tagy()->vsechny();
$src = 'media.php?id=' . $id . '&v=' . substr((string)$m['md5'], 0, 8);
?>
<p><a href="<?= Ui::h(Ui::odkaz('galerie', $m['archived_at'] ? ['archiv' => 1] : [])) ?>">← <?= $m['archived_at'] ? 'Archiv' : 'Galerie' ?></a>
  <?php if ($m['archived_at']): ?> <?= Ui::stav('paused', 'v archivu') ?><?php endif; ?></p>
<div class="dva-sloupce">
<section>
  <div class="nahled nahled-detail">
    <?php if ($m['kind'] === 'image'): ?><a href="<?= Ui::h($src) ?>" target="_blank"><img src="<?= Ui::h($src) ?>" alt=""></a>
    <?php elseif ($m['kind'] === 'video'): ?><video src="<?= Ui::h($src) ?>" controls preload="metadata" playsinline></video>
    <?php else: ?><iframe src="<?= Ui::h($src) ?>" title="PDF"></iframe><p class="mala"><a href="<?= Ui::h($src) ?>" target="_blank">Otevřít PDF</a></p><?php endif; ?>
  </div>
  <div class="karta" style="margin-top:1.5rem">
    <dl class="udaje">
      <dt>Druh</dt><dd><?= Ui::h(Media::DRUHY[$m['kind']] ?? $m['kind']) ?> (<?= Ui::h($m['mime']) ?>)</dd>
      <?php if ($m['width']): ?><dt>Rozměr</dt><dd><?= (int)$m['width'] ?>×<?= (int)$m['height'] ?> px</dd><?php endif; ?>
      <dt>Velikost</dt><dd><?= Ui::h(Zalohy::velikost((int)$m['size'])) ?></dd>
      <?php if ($m['orig_filename']): ?><dt>Originál</dt><dd><?= (int)$m['orig_w'] ?>×<?= (int)$m['orig_h'] ?> px, <?= Ui::h(Zalohy::velikost((int)$m['orig_size'])) ?>
        · <a href="<?= Ui::h($src . '&original=1') ?>" target="_blank">zobrazit</a></dd><?php endif; ?>
      <dt>Nahráno</dt><dd><?= Ui::h(Ui::datum(strtotime((string)$m['created_at']))) ?><?= $m['upload_name'] && $m['upload_name'] !== $m['original_name'] ? ' jako ' . Ui::h($m['upload_name']) : '' ?></dd>
      <dt>Kontrolní součet</dt><dd class="mala"><code>MD5 <?= Ui::h($m['md5']) ?></code></dd>
    </dl>
    <div class="tlacitka" style="margin-top:1rem">
      <a class="tlacitko maly" href="<?= Ui::h($src . '&stahnout=1') ?>">Stáhnout</a>
      <?php if ($m['kind'] === 'image' && !$m['orig_filename']): ?><?= $ui->tlacitko('Zkomprimovat', 'komprimovat', [], 'maly') ?><?php endif; ?>
      <?php if ($m['orig_filename']): ?><?= $ui->tlacitko('Vrátit původní soubor', 'original', [], 'maly', 'Vrátit původní (nekomprimovaný) soubor?') ?><?php endif; ?>
    </div>
  </div>
</section>

<section>
  <div class="karta">
    <h2 style="margin-top:0">Veřejný odkaz</h2>
    <?php if ($url): ?>
      <div class="tlacitka"><input id="verejny-odkaz" readonly value="<?= Ui::h($url) ?>" style="flex:1"><button type="button" class="maly" data-kopirovat="verejny-odkaz">Kopírovat</button>
        <a class="tlacitko maly" href="<?= Ui::h($url) ?>" target="_blank" rel="noopener">Otevřít</a></div>
      <p class="slabe mala">Kdo zná odkaz, soubor zobrazí bez přihlášení (vložení do webu, e-mailu, reklamy). Po nahrazení souboru zůstává stejný.</p>
      <div class="tlacitka">
        <?= $ui->tlacitko('Nový odkaz', 'odkaz-novy', [], 'maly', 'Vytvořit nový odkaz? Starý přestane fungovat.') ?>
        <?= $ui->tlacitko('Vypnout odkaz', 'odkaz-zrusit', [], 'maly nebezpecne', 'Vypnout veřejný odkaz? Soubor přestane být dostupný na webech, kde je vložený.') ?>
      </div>
    <?php else: ?>
      <p class="slabe">Veřejný odkaz je vypnutý, soubor je jen v administraci.</p>
      <?= $ui->tlacitko('Zapnout veřejný odkaz', 'odkaz-novy', [], 'maly') ?>
    <?php endif; ?>
  </div>

  <form method="post" class="formular karta">
    <h2 style="margin:0">Údaje</h2>
    <?= $ui->csrf() ?>
    <input type="hidden" name="akce" value="ulozit">
    <?= Ui::pole('Název', 'nazev', $m['original_name'], ['required' => true, 'maxlength' => 200]) ?>
    <?= Ui::vyber('Kampaň', 'kampan', ['' => '— bez kampaně —'] + $kampane, (string)($m['campaign_id'] ?? '')) ?>
    <?= Ui::pole('Tagy', 'tagy', implode(', ', Media::tagy($m)), ['list' => 'seznam-tagu', 'placeholder' => 'oddělte čárkou'], 'Nejvýš 10 tagů po 30 znacích.') ?>
    <datalist id="seznam-tagu"><?php foreach ($tagy as $t): ?><option value="<?= Ui::h($t['name']) ?>"><?php endforeach; ?></datalist>
    <?= Ui::oblast('Poznámka', 'note', $m['note'], ['rows' => 3]) ?>
    <div><button type="submit" class="hlavni">Uložit</button></div>
  </form>

  <div class="karta nahravani" id="nahravani" data-csrf="<?= Ui::h($app->relace()->csrfToken()) ?>" data-cast="<?= App::CAST_NAHRANI ?>" data-max="<?= App::MAX_NAHRANI ?>" data-nahradit="<?= $id ?>">
    <h2 style="margin-top:0">Nahradit soubor</h2>
    <p class="slabe mala">Nový soubor stejného druhu (<?= Ui::h(Media::DRUHY[$m['kind']] ?? $m['kind']) ?>). Odkaz, kampaň, tagy a poznámka zůstanou.</p>
    <div class="nahravani-plocha" data-plocha>
      <p><strong>Přetáhněte sem soubor</strong> nebo <label class="odkaz-tlacitko">vyberte z počítače<input type="file" hidden data-vyber></label></p>
    </div>
    <ul class="nahravani-seznam" data-seznam></ul>
  </div>

  <div class="karta tlacitka">
    <?php if ($m['archived_at']): ?><?= $ui->tlacitko('Vrátit z archivu', 'vratit', [], 'maly') ?>
    <?php else: ?><?= $ui->tlacitko('Přesunout do archivu', 'archivovat', [], 'maly') ?><?php endif; ?>
    <?= $ui->tlacitko('Smazat', 'smazat', [], 'maly nebezpecne', 'Smazat „' . $m['original_name'] . '“ i se souborem? Veřejný odkaz přestane fungovat.') ?>
  </div>
</section>
</div>
