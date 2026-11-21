<?php
declare(strict_types=1);
// Zóna: nastavení, přiřazení kampaní a bannerů. Proměnné: $app, $ui, $post.
use Reklamy\{Ui, Zony, Bannery, Kampane, Kody, Vydej};

$id = (int)($_GET['id'] ?? 0);
$z = $app->zony()->radek($id);
$titulek = $z['site_name'] . ' / ' . $z['name'];
$akce = $post ? (string)($_POST['akce'] ?? '') : '';
if ($post) {
    if ($ui->zpracuj(function () use ($app, $akce, $id, $z, $ui) {
        switch ($akce) {
            case 'ulozit': $app->zony()->uloz($_POST, $id); break;
            case 'priradit': $app->zony()->prirad($id, (array)($_POST['kampane'] ?? []), (array)($_POST['bannery'] ?? [])); break;
            case 'smazat':
                $app->zony()->smaz($id);
                $ui->zprava('ok', 'Zóna smazána.');
                $ui->presmeruj(Ui::odkaz('web', ['id' => $z['site_id']]));
            default: throw new Reklamy\Chyba('Neznámá akce.');
        }
    })) {
        $ui->zprava('ok', 'Uloženo.');
        $ui->presmeruj(Ui::odkaz('zona', ['id' => $id]));
    }
}
$prirazeni = $app->zony()->prirazeni($id);
if ($akce === 'priradit') $prirazeni = ['kampane' => array_map('intval', (array)($_POST['kampane'] ?? [])), 'bannery' => array_map('intval', (array)($_POST['bannery'] ?? []))];
$kampane = $app->kampane()->seznam();
$bannery = $app->bannery()->seznam();
$efektivni = $app->zony()->bannery($id);
$rozmery = ['' => 'Běžné rozměry…'];
foreach (Bannery::ROZMERY as $r => $n) $rozmery[$r] = str_replace('x', '×', $r) . ' – ' . $n;
?>
<p class="slabe">Web: <a href="<?= Ui::h(Ui::odkaz('web', ['id' => $z['site_id']])) ?>"><?= Ui::h($z['site_name']) ?></a> · zóna #<?= (int)$z['id'] ?> · rozměr <?= Ui::h(Zony::rozmer($z)) ?></p>
<div class="dva-sloupce">
<section>
  <form method="post" class="formular karta">
    <h2 style="margin:0">Co se v zóně zobrazuje</h2>
    <?= $ui->csrf() ?>
    <input type="hidden" name="akce" value="priradit">
    <fieldset><legend>Celé kampaně</legend>
      <p class="slabe mala" style="margin-top:0">Platí i pro bannery, které do kampaně přidáte později<?= $z['width'] !== null ? ' (jen rozměr ' . Ui::h(Zony::rozmer($z)) . ')' : '' ?>.</p>
      <?php if (!$kampane): ?><p class="slabe">Žádné kampaně.</p><?php else: ?>
      <div class="seznam-voleb">
        <?php foreach ($kampane as $k): ?>
        <label><input type="checkbox" name="kampane[]" value="<?= (int)$k['id'] ?>"<?= in_array((int)$k['id'], $prirazeni['kampane'], true) ? ' checked' : '' ?>>
          <?= Ui::h($k['name']) ?> <?= Ui::stav($k['status'], Kampane::STAVY[$k['status']]) ?></label>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </fieldset>
    <fieldset><legend>Jednotlivé bannery</legend>
      <?php if (!$bannery): ?><p class="slabe">Žádné bannery.</p><?php else: ?>
      <div class="seznam-voleb">
        <?php foreach ($bannery as $b): $pasuje = Zony::pasuje($z, $b); ?>
        <label class="<?= $pasuje ? '' : 'nepasuje' ?>"><input type="checkbox" name="bannery[]" value="<?= (int)$b['id'] ?>"<?= in_array((int)$b['id'], $prirazeni['bannery'], true) ? ' checked' : '' ?><?= $pasuje ? '' : ' disabled' ?>>
          <?= Ui::h($b['campaign_name'] . ' / ' . $b['name']) ?> <span class="slabe mala"><?= (int)$b['width'] ?>×<?= (int)$b['height'] ?></span></label>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </fieldset>
    <div><button type="submit" class="hlavni">Uložit přiřazení</button></div>
  </form>

  <h2>Bannery v zóně (<?= count($efektivni) ?>)</h2>
  <?php if (!$efektivni): ?><div class="prazdne">Zóna zatím nemá co zobrazit<?= $z['fallback'] ? ' – zobrazí se náhradní HTML' : '' ?>.</div><?php else: ?>
  <div class="tabulka-obal"><table>
    <tr><th>Banner</th><th>Kampaň</th><th>Rozměr</th><th>Stav</th></tr>
    <?php foreach ($efektivni as $b): ?>
    <tr>
      <td><a href="<?= Ui::h(Ui::odkaz('banner', ['id' => $b['id']])) ?>"><?= Ui::h($b['name']) ?></a></td>
      <td><a href="<?= Ui::h(Ui::odkaz('kampan', ['id' => $b['campaign_id']])) ?>"><?= Ui::h($b['campaign_name']) ?></a></td>
      <td><?= (int)$b['width'] ?>×<?= (int)$b['height'] ?></td>
      <td><?= Ui::stav($b['status'], Bannery::STAVY[$b['status']]) ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>

  <h2 id="kod">Kód pro vložení na web</h2>
  <?php $kody = new Kody($app); $prvni = true;
  foreach (Kody::ZPUSOBY as $zp => [$nazev, $popis]): ?>
  <details class="kod-vlozeni"<?= $prvni ? ' open' : '' ?>>
    <summary><?= Ui::h($nazev) ?></summary>
    <p class="slabe mala"><?= Ui::h($popis) ?></p>
    <textarea id="kod-<?= $zp ?>" class="kod" readonly rows="<?= in_array($zp, ['php', 'xmlrpc', 'json'], true) ? 12 : 4 ?>"><?= Ui::h($kody->kod($z, $zp)) ?></textarea>
    <div class="tlacitka" style="margin-top:.4rem"><button type="button" class="maly" data-kopirovat="kod-<?= $zp ?>">Kopírovat</button></div>
  </details>
  <?php $prvni = false; endforeach; ?>
</section>
<section>
  <h2 style="margin-top:0">Náhled</h2>
  <?php $vydej = new Vydej($app); $nw = (int)($z['width'] ?? 0) ?: 300; $nh = (int)($z['height'] ?? 0) ?: 250; ?>
  <div class="nahled" style="margin-bottom:1rem">
    <iframe src="<?= Ui::h($app->url('d/ram.php') . '?' . http_build_query(['zona' => $id, 'nahled' => $vydej->podpisNahledu($id), 'cb' => random_int(1, 1_000_000)])) ?>"
      width="<?= $nw ?>" height="<?= $nh ?>" title="Náhled zóny" style="background:transparent"></iframe>
  </div>
  <p class="slabe mala" style="margin-top:-.5rem">Náhled vybere banner stejně jako na webu (obnovením stránky se střídají), ale nepočítá se do statistik.</p>
  <?php Ui::$zPostu = $akce === 'ulozit'; ?>
  <form method="post" class="formular karta">
    <h2 style="margin:0">Nastavení zóny</h2>
    <?= $ui->csrf() ?>
    <input type="hidden" name="akce" value="ulozit">
    <?= Ui::pole('Název', 'name', $z['name'], ['required' => true, 'maxlength' => 200]) ?>
    <?= Ui::vyber('Druh', 'kind', Zony::DRUHY, $z['kind']) ?>
    <div class="radek">
      <?= Ui::pole('Šířka (px)', 'width', $z['width'], ['type' => 'number', 'min' => 1, 'max' => Bannery::MAX_ROZMER]) ?>
      <?= Ui::pole('Výška (px)', 'height', $z['height'], ['type' => 'number', 'min' => 1, 'max' => Bannery::MAX_ROZMER]) ?>
    </div>
    <label class="pole"><span>Běžné rozměry</span><select data-rozmery><?php foreach ($rozmery as $k => $t): ?><option value="<?= Ui::h($k) ?>"><?= Ui::h($t) ?></option><?php endforeach; ?></select>
      <small>Bez rozměru zóna přijme banner libovolné velikosti. Přímo přiřazené bannery jiného rozměru se po změně odeberou.</small></label>
    <?= Ui::oblast('Náhradní HTML', 'fallback', $z['fallback'], ['class' => 'kod', 'rows' => 4, 'spellcheck' => 'false'], 'Zobrazí se, když zóna nemá žádný banner k dispozici. Prázdné = nic.') ?>
    <?= Ui::oblast('Poznámka', 'note', $z['note'], ['rows' => 2]) ?>
    <div><button type="submit" class="hlavni">Uložit</button></div>
  </form>
  <?php Ui::$zPostu = true; ?>
  <?= $ui->tlacitko('Smazat zónu', 'smazat', [], 'maly nebezpecne', 'Smazat zónu „' . $z['name'] . '“? Kód vložený na webu přestane zobrazovat reklamy.') ?>
</section>
</div>
