<?php
declare(strict_types=1);
// Tracker konverzí: nastavení, sledované kampaně, kódy pro web inzerenta, poslední konverze. Proměnné: $app, $ui, $post.
use Reklamy\{Ui, Konverze, Chyba};

$konv = $app->konverze();
$id = (int)($_GET['id'] ?? 0);
$t = $konv->radek($id);
$titulek = $t['name'];
if ($post) {
    $akce = (string)($_POST['akce'] ?? '');
    if ($akce === 'smazat') {
        if ($ui->zpracuj(fn() => $konv->smaz($id))) {
            $ui->zprava('ok', 'Tracker smazán.');
            $ui->presmeruj(Ui::odkaz('konverze'));
        }
    } elseif ($ui->zpracuj(function () use ($konv, $id, $akce) {
        if ($akce !== 'ulozit') throw new Chyba('Neznámá akce.');
        $konv->uloz($_POST, $id);
    })) {
        $ui->zprava('ok', 'Uloženo.');
        $ui->presmeruj(Ui::odkaz('tracker', ['id' => $id]));
    }
}
$vybrane = $post ? array_map('intval', (array)($_POST['kampane'] ?? [])) : $konv->kampane($id);
$kampane = $app->kampane()->seznam();
$posledni = $konv->posledni($id, 50);
$hodnota = $t['value_default'] !== null ? str_replace(' Kč', '', Konverze::kc((int)$t['value_default'])) : '';
?>
<p class="slabe"><a href="<?= Ui::h(Ui::odkaz('konverze')) ?>">Konverze</a> · tracker #<?= (int)$t['id'] ?></p>
<div class="dva-sloupce">
<section>
  <form method="post" class="formular karta">
    <?= $ui->csrf() ?><input type="hidden" name="akce" value="ulozit">
    <?= Ui::pole('Název', 'name', $t['name'], ['required' => true, 'maxlength' => 200]) ?>
    <div class="radek">
      <?= Ui::vyber('Druh', 'kind', Konverze::DRUHY, $t['kind']) ?>
      <?= Ui::vyber('Stav', 'status', Konverze::STAVY, $t['status']) ?>
      <?= Ui::pole('Výchozí hodnota (Kč)', 'value_default', $hodnota, ['inputmode' => 'decimal', 'placeholder' => '0'], 'Když kód hodnotu nepošle.') ?>
    </div>
    <div class="radek">
      <?= Ui::pole('Okno po prokliku (dní)', 'click_days', $t['click_days'], ['type' => 'number', 'min' => 1, 'max' => 365, 'required' => true]) ?>
      <?= Ui::pole('Okno po zobrazení (dní)', 'view_days', $t['view_days'], ['type' => 'number', 'min' => 0, 'max' => 90], '0 = jen po prokliku. Po zobrazení potřebuje cookies.') ?>
    </div>
    <label class="zaskrtavatko"><input type="checkbox" name="once_per_click" value="1"<?= Ui::hodnota('once_per_click', $t['once_per_click'] ? '1' : '') === '1' ? ' checked' : '' ?>>
      <span>Jen jedna konverze na proklik (další objednávky téhož návštěvníka se nepřiřadí)</span></label>
    <div class="pole"><span>Sledované kampaně</span>
      <div class="seznam-voleb">
        <?php foreach ($kampane as $k): ?>
        <label class="zaskrtavatko"><input type="checkbox" name="kampane[]" value="<?= (int)$k['id'] ?>"<?= in_array((int)$k['id'], $vybrane, true) ? ' checked' : '' ?>> <?= Ui::h($k['name']) ?></label>
        <?php endforeach; ?>
      </div>
      <small>Nic nezaškrtnuté = všechny kampaně.</small></div>
    <?= Ui::oblast('Poznámka', 'note', $t['note'], ['rows' => 2]) ?>
    <div class="tlacitka"><button type="submit" class="hlavni">Uložit</button> <a href="<?= Ui::h(Ui::odkaz('konverze')) ?>">Zpět</a></div>
  </form>
  <?= $ui->tlacitko('Smazat tracker', 'smazat', [], 'maly nebezpecne', "Smazat tracker „{$t['name']}“ i se záznamy jeho konverzí? Statistiky banerů zůstanou.") ?>
</section>
<section>
  <h2 id="kody" style="margin-top:0">Kód pro web inzerenta</h2>
  <?php foreach ($konv->kody($t) as $i => [$nadpis, $popis, $kod]): ?>
  <details class="kod-vlozeni"<?= $i < 2 ? ' open' : '' ?>>
    <summary><?= Ui::h($nadpis) ?></summary>
    <p class="slabe mala"><?= Ui::h($popis) ?></p>
    <textarea id="tk-<?= $i ?>" class="kod" readonly rows="<?= substr_count($kod, "\n") + 2 ?>"><?= Ui::h($kod) ?></textarea>
    <div class="tlacitka" style="margin-top:.4rem"><button type="button" class="maly" data-kopirovat="tk-<?= $i ?>">Kopírovat</button></div>
  </details>
  <?php endforeach; ?>

  <h2>Poslední konverze</h2>
  <?php if (!$posledni): ?><div class="prazdne">Zatím žádná konverze.</div>
  <?php else: ?>
  <div class="tabulka-obal"><table>
    <tr><th>Kdy</th><th>Objednávka</th><th>Banner</th><th class="cisla">Hodnota</th></tr>
    <?php foreach ($posledni as $c): ?>
    <tr><td class="mala"><?= Ui::h(Ui::datum((int)$c['at'])) ?></td><td><?= Ui::h($c['ref'] ?? '–') ?></td>
      <td><?php if ($c['banner_id']): ?><a href="<?= Ui::h(Ui::odkaz('banner', ['id' => $c['banner_id']])) ?>"><?= Ui::h($c['banner_name'] ?? ('#' . $c['banner_id'])) ?></a>
        <span class="slabe mala"><?= Ui::h(Konverze::CESTY[$c['via']] ?? '') ?></span><?php else: ?><span class="slabe">nepřiřazená</span><?php endif; ?></td>
      <td class="cisla"><?= Ui::h(Konverze::kc((int)$c['value'])) ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</section>
</div>
