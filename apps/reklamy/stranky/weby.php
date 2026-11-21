<?php
declare(strict_types=1);
// Weby a jejich zóny. Proměnné: $app, $ui, $post.
use Reklamy\{Ui, Zony};

if ($post && ($_POST['akce'] ?? '') === 'novy-web') {
    $id = 0;
    if ($ui->zpracuj(function () use ($app, &$id) { $id = $app->weby()->uloz($_POST); })) {
        $ui->zprava('ok', 'Web přidán. Teď mu přidejte zóny.');
        $ui->presmeruj(Ui::odkaz('web', ['id' => $id]));
    }
}
$weby = $app->weby()->seznam();
$zony = [];
foreach ($app->zony()->seznam() as $z) $zony[$z['site_id']][] = $z;
?>
<div class="dva-sloupce">
<section>
<?php if (!$weby): ?>
  <div class="prazdne">Zatím žádný web. Přidejte web, na kterém se budou reklamy zobrazovat, a v něm zóny (místa pro reklamu).</div>
<?php endif; ?>
<?php foreach ($weby as $w): ?>
  <div class="karta">
    <div class="tlacitka" style="justify-content:space-between">
      <h2 style="margin:0"><a href="<?= Ui::h(Ui::odkaz('web', ['id' => $w['id']])) ?>"><?= Ui::h($w['name']) ?></a></h2>
      <?php if ($w['url']): ?><a class="mala" href="<?= Ui::h($w['url']) ?>" target="_blank" rel="noopener noreferrer"><?= Ui::h($w['url']) ?></a><?php endif; ?>
    </div>
    <?php if (empty($zony[$w['id']])): ?>
      <p class="slabe">Web nemá zóny. <a href="<?= Ui::h(Ui::odkaz('web', ['id' => $w['id']])) ?>#nova-zona">Přidat zónu</a></p>
    <?php else: ?>
    <div class="tabulka-obal" style="margin:.8rem 0 0"><table>
      <tr><th>Zóna</th><th>Rozměr</th><th class="cisla">Kampaně</th><th class="cisla">Bannery</th></tr>
      <?php foreach ($zony[$w['id']] as $z): ?>
      <tr>
        <td><a href="<?= Ui::h(Ui::odkaz('zona', ['id' => $z['id']])) ?>"><?= Ui::h($z['name']) ?></a> <span class="slabe mala">#<?= (int)$z['id'] ?><?= $z['kind'] === 'popup' ? ', popup' : '' ?></span></td>
        <td><?= Ui::h(Zony::rozmer($z)) ?></td>
        <td class="cisla"><?= (int)$z['campaigns'] ?></td>
        <td class="cisla"><?= (int)$z['banners'] ?></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</section>
<section>
  <form method="post" class="formular karta">
    <h2 style="margin:0">Nový web</h2>
    <?= $ui->csrf() ?>
    <input type="hidden" name="akce" value="novy-web">
    <?= Ui::pole('Název', 'name', '', ['required' => true, 'maxlength' => 200]) ?>
    <?= Ui::pole('Adresa webu', 'url', '', ['type' => 'url', 'placeholder' => 'https://…']) ?>
    <div><button type="submit" class="hlavni">Přidat web</button></div>
  </form>
</section>
</div>
