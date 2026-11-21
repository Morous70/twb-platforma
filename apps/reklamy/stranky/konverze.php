<?php
declare(strict_types=1);
// Konverze: trackery a poslední zaznamenané konverze. Proměnné: $app, $ui, $post.
use Reklamy\{Ui, Konverze};

$konv = $app->konverze();
if ($post && ($_POST['akce'] ?? '') === 'novy') {
    $id = 0;
    if ($ui->zpracuj(function () use ($konv, &$id) { $id = $konv->uloz($_POST); })) {
        $ui->zprava('ok', 'Tracker vytvořen. Vložte kód na web inzerenta.');
        $ui->presmeruj(Ui::odkaz('tracker', ['id' => $id]) . '#kody');
    }
}
$trackery = $konv->seznam();
$posledni = $konv->posledni(null, 30);
?>
<p class="slabe">Tracker je kód na webu inzerenta (objednávka, registrace, poptávka). Konverze se přiřadí banneru, na který návštěvník předtím klikl
(volitelně i jen viděl) – podle tokenu <code>twbr</code> v adrese prokliku nebo cookie návštěvníka. Počítá se do statistik kampaně a banneru.</p>
<div class="dva-sloupce">
<section>
  <h2 style="margin-top:0">Trackery</h2>
  <?php if (!$trackery): ?><div class="prazdne">Zatím žádný tracker.</div>
  <?php else: ?>
  <div class="tabulka-obal"><table>
    <tr><th>Tracker</th><th>Okno</th><th class="cisla">Konverze</th><th class="cisla">Hodnota</th></tr>
    <?php foreach ($trackery as $t): ?>
    <tr>
      <td><a href="<?= Ui::h(Ui::odkaz('tracker', ['id' => $t['id']])) ?>"><?= Ui::h($t['name']) ?></a> <span class="slabe mala">#<?= (int)$t['id'] ?></span><br>
        <span class="slabe mala"><?= Ui::h(Konverze::DRUHY[$t['kind']] ?? $t['kind']) ?><?= $t['status'] !== 'active' ? ' · ' . Ui::h(mb_strtolower(Konverze::STAVY[$t['status']])) : '' ?>
        <?= $t['posledni'] ? ' · naposledy ' . Ui::h(Ui::datum((int)$t['posledni'])) : '' ?></span></td>
      <td class="mala"><?= (int)$t['click_days'] ?> dní po prokliku<?= $t['view_days'] ? '<br>' . (int)$t['view_days'] . ' dní po zobrazení' : '' ?></td>
      <td class="cisla"><?= Ui::cislo((int)$t['pocet']) ?></td>
      <td class="cisla"><?= Ui::h(Konverze::kc((int)$t['hodnota'])) ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>

  <form method="post" class="formular karta">
    <?= $ui->csrf() ?><input type="hidden" name="akce" value="novy">
    <h3 style="margin-top:0">Nový tracker</h3>
    <div class="radek">
      <?= Ui::pole('Název', 'name', '', ['required' => true, 'maxlength' => 200, 'placeholder' => 'Objednávka v e-shopu']) ?>
      <?= Ui::vyber('Druh', 'kind', Konverze::DRUHY, 'sale') ?>
    </div>
    <div class="tlacitka"><button type="submit" class="hlavni">Vytvořit tracker</button></div>
  </form>
</section>
<section>
  <h2 style="margin-top:0">Poslední konverze</h2>
  <?php if (!$posledni): ?><div class="prazdne">Zatím žádná konverze.</div>
  <?php else: ?>
  <div class="tabulka-obal"><table>
    <tr><th>Kdy</th><th>Tracker</th><th>Banner</th><th class="cisla">Hodnota</th></tr>
    <?php foreach ($posledni as $c): ?>
    <tr><td class="mala"><?= Ui::h(Ui::datum((int)$c['at'])) ?></td>
      <td><?= Ui::h($c['tracker_name']) ?><?= $c['ref'] !== null ? '<br><span class="slabe mala">' . Ui::h($c['ref']) . '</span>' : '' ?></td>
      <td><?php if ($c['banner_id']): ?><a href="<?= Ui::h(Ui::odkaz('banner', ['id' => $c['banner_id']])) ?>"><?= Ui::h($c['banner_name'] ?? ('#' . $c['banner_id'])) ?></a>
        <br><span class="slabe mala"><?= Ui::h(Konverze::CESTY[$c['via']] ?? '') ?></span><?php else: ?><span class="slabe">nepřiřazená</span><?php endif; ?></td>
      <td class="cisla"><?= Ui::h(Konverze::kc((int)$c['value'])) ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</section>
</div>
