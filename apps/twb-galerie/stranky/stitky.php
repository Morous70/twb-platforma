<?php
declare(strict_types=1);
// Štítky (0.8.8): seznam štítků (tagů) s počty médií, hledání, přejmenování, sloučení vybraných do jednoho a smazání.
// Úpravy dělá MediaGalerie\Stitky (modul tagy). Nic se neotevírá v nové kartě. Proměnné: $app, $ui, $post.
use MediaGalerie\{Ui, Chyba};

$stitky = $app->stitky();
$hledat = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
$prejmenovat = trim((string)($_GET['prejmenovat'] ?? ''));

if ($post) {
    $akce = (string)($_POST['akce'] ?? '');
    $zprava = '';
    if ($ui->zpracuj(function () use ($akce, $stitky, &$zprava) {
        $pocet = static fn(int $n): string => $n . ' ' . ($n === 1 ? 'médium' : ($n >= 2 && $n <= 4 ? 'média' : 'médií'));
        switch ($akce) {
            case 'prejmenovat':
                $z = (string)($_POST['z'] ?? '');
                $na = trim((string)($_POST['na'] ?? ''));
                $n = $stitky->prejmenuj($z, $na);
                $zprava = "Štítek „{$z}“ přejmenován na „{$na}“ (" . $pocet($n) . ').';
                break;
            case 'sloucit':
                $cil = trim((string)($_POST['cil'] ?? ''));
                $n = $stitky->slouc(array_map('strval', (array)($_POST['vybrane'] ?? [])), $cil);
                $zprava = "Štítky sloučeny do „{$cil}“ (změněno " . $pocet($n) . ').';
                break;
            case 'smazat':
                $nazev = (string)($_POST['nazev'] ?? '');
                $n = $stitky->smaz($nazev);
                $zprava = "Štítek „{$nazev}“ smazán u " . $pocet($n) . '. Média zůstala v galerii.';
                break;
            default:
                throw new Chyba('Neznámá akce.');
        }
    })) {
        $ui->zprava('ok', $zprava);
        $ui->presmeruj(Ui::odkaz('stitky', ['q' => $hledat]));
    }
}
$seznam = $stitky->seznam($hledat);
$vsech = $hledat === '' ? count($seznam) : count($stitky->seznam());
$upravit = null;
if ($prejmenovat !== '') foreach ($stitky->seznam() as $t) if (mb_strtolower($t['nazev']) === mb_strtolower($prejmenovat)) $upravit = $t;
$vybrane = array_map('mb_strtolower', array_map('strval', (array)($_POST['vybrane'] ?? [])));
?>
<div class="dva-sloupce">
<section>
  <form method="get" class="filtr karta">
    <input type="hidden" name="p" value="stitky">
    <input type="search" name="q" value="<?= Ui::h($hledat) ?>" placeholder="Hledat štítek" aria-label="Hledat štítek">
    <button type="submit">Hledat</button>
    <?php if ($hledat !== ''): ?><a class="tlacitko maly" href="<?= Ui::h(Ui::odkaz('stitky')) ?>">Zrušit</a><?php endif; ?>
    <span class="slabe mala" style="margin-left:auto">Štítků: <strong><?= Ui::cislo($vsech) ?></strong><?= $hledat !== '' ? ' · nalezeno ' . Ui::cislo(count($seznam)) : '' ?></span>
  </form>
<?php if (!$seznam): ?>
  <div class="prazdne"><?= $hledat !== '' ? 'Hledání nic nenašlo.' : 'Zatím žádné štítky. Přidáte je u média v detailu, při nahrání nebo hromadnou akcí v Galerii.' ?></div>
<?php else: ?>
  <div class="tabulka-obal"><table class="tabulka-stitky">
    <thead><tr><th class="sloupec-vyber"><span class="skryte">Vybrat ke sloučení</span></th><th>Štítek</th><th class="cisla">Médií</th><th class="cisla">V archivu</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($seznam as $t): $id = 'st-' . substr(md5(mb_strtolower($t['nazev'])), 0, 10); ?>
    <tr<?= $upravit && $upravit['nazev'] === $t['nazev'] ? ' class="aktivni"' : '' ?>>
      <td class="sloupec-vyber"><input type="checkbox" form="f-slouceni" name="vybrane[]" value="<?= Ui::h($t['nazev']) ?>" id="<?= $id ?>" data-stitek-vyber<?= in_array(mb_strtolower($t['nazev']), $vybrane, true) ? ' checked' : '' ?>
        aria-label="Vybrat štítek <?= Ui::h($t['nazev']) ?> ke sloučení"></td>
      <td><label for="<?= $id ?>" class="stitek stitek-velky"><?= Ui::h($t['nazev']) ?></label></td>
      <td class="cisla"><?= $t['pocet'] ? '<a href="' . Ui::h(Ui::odkaz('galerie', ['tag' => $t['nazev']])) . '" title="Zobrazit média se štítkem v Galerii">' . Ui::cislo($t['pocet']) . '</a>' : '0' ?></td>
      <td class="cisla"><?= $t['archiv'] ? '<a href="' . Ui::h(Ui::odkaz('galerie', ['tag' => $t['nazev'], 'archiv' => 1])) . '">' . Ui::cislo($t['archiv']) . '</a>' : '<span class="slabe">0</span>' ?></td>
      <td class="bunka-akce"><div class="tlacitka"><a class="tlacitko maly" href="<?= Ui::h(Ui::odkaz('stitky', ['prejmenovat' => $t['nazev'], 'q' => $hledat]) . '#prejmenovat') ?>">Přejmenovat</a>
        <?= $ui->tlacitko('Smazat', 'smazat', ['nazev' => $t['nazev']], 'maly nebezpecne', 'Smazat štítek „' . $t['nazev'] . '“ u ' . ($t['pocet'] + $t['archiv']) . ' médií? Média zůstanou v galerii, jen bez tohoto štítku.') ?></div></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</section>
<section>
  <?php if ($upravit): ?>
  <form method="post" class="formular karta" id="prejmenovat" action="<?= Ui::h(Ui::odkaz('stitky', ['prejmenovat' => $upravit['nazev'], 'q' => $hledat])) ?>">
    <header class="skupina-hlavicka"><h2>Přejmenovat štítek</h2><p class="slabe">Změní se u všech <?= Ui::cislo($upravit['pocet'] + $upravit['archiv']) ?> médií, i v archivu.</p></header>
    <?= $ui->csrf() ?>
    <input type="hidden" name="akce" value="prejmenovat"><input type="hidden" name="z" value="<?= Ui::h($upravit['nazev']) ?>">
    <p style="margin:0">Původní název: <span class="stitek stitek-velky"><?= Ui::h($upravit['nazev']) ?></span></p>
    <?= Ui::pole('Nový název', 'na', $upravit['nazev'], ['required' => true, 'maxlength' => \TWB\Tagy\Tagy::MAX_DELKA, 'list' => 'seznam-stitku', 'autofocus' => true],
        'Když štítek s novým názvem už existuje, oba se <strong>sloučí</strong> do jednoho.') ?>
    <div class="tlacitka"><button type="submit" class="hlavni">Přejmenovat</button><a class="tlacitko" href="<?= Ui::h(Ui::odkaz('stitky', ['q' => $hledat])) ?>">Zrušit</a></div>
  </form>
  <?php endif; ?>
  <form method="post" class="formular karta" id="f-slouceni" action="<?= Ui::h(Ui::odkaz('stitky', ['q' => $hledat])) ?>">
    <header class="skupina-hlavicka"><h2>Sloučit štítky</h2><p class="slabe">Třeba „auto“, „Auta“ a „automobil“ do jednoho „auta“.</p></header>
    <?= $ui->csrf() ?>
    <input type="hidden" name="akce" value="sloucit">
    <p style="margin:0" data-stitky-vybrane>Zaškrtněte v seznamu štítky, které chcete sloučit.</p>
    <?= Ui::pole('Sloučit do štítku', 'cil', '', ['required' => true, 'maxlength' => \TWB\Tagy\Tagy::MAX_DELKA, 'list' => 'seznam-stitku', 'placeholder' => 'Název výsledného štítku'],
        'Může to být jeden z vybraných, jiný existující nebo úplně nový název. Média s více sloučenými štítky budou mít štítek jen jednou.') ?>
    <div class="tlacitka"><button type="submit" class="hlavni">Sloučit vybrané</button></div>
  </form>
  <div class="karta">
    <h2 style="margin-top:0;font-size:1.05rem">Jak to funguje</h2>
    <ul class="slabe mala" style="margin:0;padding-left:1.2rem">
      <li>Velikost písmen se nerozlišuje – „Léto“ a „léto“ je jeden štítek.</li>
      <li>Číslo v seznamu otevře média se štítkem v Galerii.</li>
      <li>Smazání štítek jen odebere, média zůstanou. Přidávat štítky jde u média v detailu, při nahrání nebo hromadnou akcí v Galerii.</li>
    </ul>
  </div>
</section>
</div>
<datalist id="seznam-stitku"><?php foreach ($stitky->seznam() as $t): ?><option value="<?= Ui::h($t['nazev']) ?>"><?php endforeach; ?></datalist>
