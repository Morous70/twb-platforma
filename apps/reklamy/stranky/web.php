<?php
declare(strict_types=1);
// Úprava webu a přidání zón. Proměnné: $app, $ui, $post.
use Reklamy\{Ui, Zony, Bannery};

$id = (int)($_GET['id'] ?? 0);
$w = $app->weby()->radek($id);
$titulek = $w['name'];
if ($post) {
    $akce = (string)($_POST['akce'] ?? '');
    $novaZona = 0;
    if ($ui->zpracuj(function () use ($app, $akce, $id, &$novaZona, $ui) {
        if ($akce === 'ulozit') $app->weby()->uloz($_POST, $id);
        elseif ($akce === 'nova-zona') $novaZona = $app->zony()->uloz(['site_id' => $id] + $_POST);
        elseif ($akce === 'smazat') { $app->weby()->smaz($id); $ui->zprava('ok', 'Web smazán.'); $ui->presmeruj(Ui::odkaz('weby')); }
        else throw new Reklamy\Chyba('Neznámá akce.');
    })) {
        $ui->zprava('ok', $novaZona ? 'Zóna přidána. Přiřaďte jí kampaně nebo bannery.' : 'Uloženo.');
        $ui->presmeruj($novaZona ? Ui::odkaz('zona', ['id' => $novaZona]) : Ui::odkaz('web', ['id' => $id]));
    }
}
$zony = $app->zony()->seznam($id);
$rozmery = ['' => 'Běžné rozměry…'];
foreach (Bannery::ROZMERY as $r => $n) $rozmery[$r] = str_replace('x', '×', $r) . ' – ' . $n;
$jeNova = ($_POST['akce'] ?? '') === 'nova-zona';
?>
<div class="dva-sloupce">
<section>
  <h2 style="margin-top:0">Zóny</h2>
  <?php if (!$zony): ?><div class="prazdne">Web zatím nemá zóny.</div><?php else: ?>
  <div class="tabulka-obal"><table>
    <tr><th>Zóna</th><th>Druh</th><th>Rozměr</th><th class="cisla">Kampaně</th><th class="cisla">Bannery</th></tr>
    <?php foreach ($zony as $z): ?>
    <tr>
      <td><a href="<?= Ui::h(Ui::odkaz('zona', ['id' => $z['id']])) ?>"><?= Ui::h($z['name']) ?></a> <span class="slabe mala">#<?= (int)$z['id'] ?></span></td>
      <td><?= Ui::h(Zony::DRUHY[$z['kind']]) ?></td>
      <td><?= Ui::h(Zony::rozmer($z)) ?></td>
      <td class="cisla"><?= (int)$z['campaigns'] ?></td>
      <td class="cisla"><?= (int)$z['banners'] ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>

  <?php Ui::$zPostu = $jeNova; ?>
  <form method="post" class="formular karta" id="nova-zona">
    <h2 style="margin:0">Nová zóna</h2>
    <?= $ui->csrf() ?>
    <input type="hidden" name="akce" value="nova-zona">
    <?= Ui::pole('Název', 'name', '', ['required' => true, 'maxlength' => 200, 'placeholder' => 'např. Pravý sloupec nahoře']) ?>
    <?= Ui::vyber('Druh', 'kind', Zony::DRUHY, 'banner') ?>
    <div class="radek">
      <?= Ui::pole('Šířka (px)', 'width', '', ['type' => 'number', 'min' => 1, 'max' => Bannery::MAX_ROZMER]) ?>
      <?= Ui::pole('Výška (px)', 'height', '', ['type' => 'number', 'min' => 1, 'max' => Bannery::MAX_ROZMER]) ?>
      <label class="pole"><span>&nbsp;</span><select data-rozmery aria-label="Běžné rozměry"><?php foreach ($rozmery as $k => $t): ?><option value="<?= Ui::h($k) ?>"><?= Ui::h($t) ?></option><?php endforeach; ?></select></label>
    </div>
    <small class="slabe">Bez rozměru zóna přijme banner libovolné velikosti.</small>
    <div><button type="submit" class="hlavni">Přidat zónu</button></div>
  </form>
</section>
<section>
  <?php Ui::$zPostu = !$jeNova; ?>
  <form method="post" class="formular karta">
    <h2 style="margin:0">Web</h2>
    <?= $ui->csrf() ?>
    <input type="hidden" name="akce" value="ulozit">
    <?= Ui::pole('Název', 'name', $w['name'], ['required' => true, 'maxlength' => 200]) ?>
    <?= Ui::pole('Adresa webu', 'url', $w['url'], ['type' => 'url']) ?>
    <?= Ui::oblast('Poznámka', 'note', $w['note'], ['rows' => 3]) ?>
    <div class="tlacitka"><button type="submit" class="hlavni">Uložit</button> <a href="<?= Ui::h(Ui::odkaz('weby')) ?>">Zpět</a></div>
  </form>
  <?= $ui->tlacitko('Smazat web', 'smazat', [], 'maly nebezpecne', 'Smazat web „' . $w['name'] . '“ i se všemi zónami?') ?>
</section>
</div>
