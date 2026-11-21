<?php
declare(strict_types=1);
// Seznam kampaní. Proměnné: $app, $ui, $post.
use Reklamy\{Ui, Kampane};

$kampane = $app->kampane();
if ($post) {
    $id = (int)($_POST['id'] ?? 0);
    $akce = (string)($_POST['akce'] ?? '');
    if ($ui->zpracuj(function () use ($kampane, $id, $akce, $ui) {
        match ($akce) {
            'stav' => $kampane->nastavStav($id, (string)($_POST['stav'] ?? '')),
            'duplikovat' => $ui->presmeruj(Ui::odkaz('kampan', ['id' => $kampane->duplikuj($id)])),
            'smazat' => $kampane->smaz($id),
            default => throw new Reklamy\Chyba('Neznámá akce.'),
        };
    })) {
        $ui->zprava('ok', $akce === 'smazat' ? 'Kampaň smazána.' : 'Uloženo.');
        $ui->presmeruj($_SERVER['REQUEST_URI']);
    }
}
$stav = (string)($_GET['stav'] ?? '');
if ($stav !== '' && !isset(Kampane::STAVY[$stav])) $stav = '';
$tag = (string)($_GET['tag'] ?? '');
$seznam = $kampane->seznam($stav, $tag);
$tagy = $kampane->tagy()->vsechny();
?>
<div class="nastroje">
  <a class="tlacitko hlavni" href="<?= Ui::h(Ui::odkaz('kampan')) ?>">+ Nová kampaň</a>
  <form method="get" class="tlacitka">
    <input type="hidden" name="p" value="kampane">
    <select name="stav" onchange="this.form.submit()" aria-label="Stav">
      <option value="">Aktivní a pozastavené</option>
      <?php foreach (Kampane::STAVY as $k => $t): ?><option value="<?= $k ?>"<?= $k === $stav ? ' selected' : '' ?>><?= Ui::h($t) ?></option><?php endforeach; ?>
    </select>
    <?php if ($tagy): ?>
    <select name="tag" onchange="this.form.submit()" aria-label="Tag">
      <option value="">Všechny tagy</option>
      <?php foreach ($tagy as $t): ?><option<?= $t['name'] === $tag ? ' selected' : '' ?>><?= Ui::h($t['name']) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <noscript><button type="submit">Filtrovat</button></noscript>
  </form>
</div>
<?php if (!$seznam): ?>
  <div class="prazdne">Žádné kampaně.</div>
<?php else: ?>
<div class="tabulka-obal"><table>
  <tr><th>Kampaň</th><th>Stav</th><th>Druh / priorita</th><th>Termín</th><th class="cisla">Bannery</th><th class="cisla">Zóny</th><th></th></tr>
  <?php foreach ($seznam as $k):
      $bezi = Kampane::bezi($k);
      $stavText = $k['status'] !== 'active' ? Kampane::STAVY[$k['status']] : ($bezi ? 'Běží' : (($k['ends_at'] && $k['ends_at'] <= time()) ? 'Skončila' : 'Čeká na začátek'));
      $stavTrida = $k['status'] !== 'active' ? $k['status'] : ($bezi ? 'bezi' : (($k['ends_at'] && $k['ends_at'] <= time()) ? 'skoncila' : 'ceka')); ?>
  <tr>
    <td><a href="<?= Ui::h(Ui::odkaz('kampan', ['id' => $k['id']])) ?>"><strong><?= Ui::h($k['name']) ?></strong></a>
      <?php foreach ($kampane->tagy()->tagyPolozky($k) as $t): ?><span class="tag"><?= Ui::h($t) ?></span><?php endforeach; ?></td>
    <td><?= Ui::stav($stavTrida, $stavText) ?></td>
    <td><?= Ui::h(explode(' – ', Kampane::DRUHY[$k['kind']])[0]) ?> / <?= (int)$k['priority'] ?></td>
    <td class="mala"><?= Ui::h(Kampane::termin($k)) ?></td>
    <td class="cisla"><?= (int)$k['banners'] ?></td>
    <td class="cisla"><?= (int)$k['zones'] ?></td>
    <td><div class="akce">
      <?= $k['status'] === 'active' ? $ui->tlacitko('Pozastavit', 'stav', ['id' => $k['id'], 'stav' => 'paused']) : $ui->tlacitko('Spustit', 'stav', ['id' => $k['id'], 'stav' => 'active']) ?>
      <?= $ui->tlacitko('Kopie', 'duplikovat', ['id' => $k['id']]) ?>
      <?= $k['status'] !== 'archived' ? $ui->tlacitko('Archivovat', 'stav', ['id' => $k['id'], 'stav' => 'archived']) : '' ?>
    </div></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php endif;
