<?php
declare(strict_types=1);
// Kampaně: složky médií. Nová, přejmenování, smazání (média zůstanou bez kampaně). Proměnné: $app, $ui, $post.
use MediaGalerie\{Ui, Chyba};
use TWB\Zalohy\Zalohy;

$kampane = $app->kampane();
$upravit = isset($_GET['upravit']) ? $kampane->radek((int)$_GET['upravit']) : null;

if ($post) {
    $akce = (string)($_POST['akce'] ?? '');
    $zprava = 'Uloženo.';
    if ($ui->zpracuj(function () use ($akce, $kampane, $upravit, &$zprava) {
        switch ($akce) {
            case 'ulozit':
                $kampane->uloz($_POST, $upravit ? (int)$upravit['id'] : null);
                break;
            case 'smazat':
                $n = $kampane->smaz((int)($_POST['id'] ?? 0));
                $zprava = 'Kampaň smazána' . ($n ? ", {$n} médií zůstalo v galerii bez kampaně." : '.');
                break;
            default:
                throw new Chyba('Neznámá akce.');
        }
    })) {
        $ui->zprava('ok', $zprava);
        $ui->presmeruj(Ui::odkaz('kampane'));
    }
}
$seznam = $kampane->seznam();
$bez = (int)$app->db->q('SELECT COUNT(*) FROM media WHERE campaign_id IS NULL AND archived_at IS NULL')->fetchColumn();
?>
<div class="dva-sloupce">
<section>
<?php if (!$seznam): ?>
  <div class="prazdne">Zatím žádná kampaň. Kampaně jsou složky, do kterých média roztřídíte.</div>
<?php else: ?>
  <div class="tabulka-obal"><table>
    <tr><th>Kampaň</th><th class="cisla">Médií</th><th class="cisla">Velikost</th><th></th></tr>
    <?php foreach ($seznam as $k): ?>
    <tr><td><a href="<?= Ui::h(Ui::odkaz('galerie', ['kampan' => $k['id']])) ?>"><strong><?= Ui::h($k['name']) ?></strong></a>
        <?php if ($k['note']): ?><div class="slabe mala"><?= Ui::h($k['note']) ?></div><?php endif; ?></td>
      <td class="cisla"><?= Ui::cislo($k['pocet']) ?></td><td class="cisla"><?= Ui::h(Zalohy::velikost((int)$k['velikost'])) ?></td>
      <td class="tlacitka" style="justify-content:flex-end"><a class="tlacitko maly" href="<?= Ui::h(Ui::odkaz('kampane', ['upravit' => $k['id']])) ?>">Upravit</a>
        <?= $ui->tlacitko('Smazat', 'smazat', ['id' => $k['id']], 'maly nebezpecne', 'Smazat kampaň „' . $k['name'] . '“? Média zůstanou v galerii bez kampaně.') ?></td></tr>
    <?php endforeach; ?>
  </table></div>
<?php endif; ?>
  <p class="slabe mala"><a href="<?= Ui::h(Ui::odkaz('galerie', ['kampan' => 0])) ?>">Média bez kampaně</a>: <?= Ui::cislo($bez) ?></p>
</section>
<section>
  <form method="post" class="formular karta" action="<?= Ui::h(Ui::odkaz('kampane', $upravit ? ['upravit' => $upravit['id']] : [])) ?>">
    <h2 style="margin:0"><?= $upravit ? 'Upravit kampaň' : 'Nová kampaň' ?></h2>
    <?= $ui->csrf() ?>
    <input type="hidden" name="akce" value="ulozit">
    <?= Ui::pole('Název', 'name', $upravit['name'] ?? '', ['required' => true, 'maxlength' => 200]) ?>
    <?= Ui::oblast('Poznámka', 'note', $upravit['note'] ?? '', ['rows' => 3]) ?>
    <div class="tlacitka"><button type="submit" class="hlavni"><?= $upravit ? 'Uložit' : 'Vytvořit' ?></button>
      <?php if ($upravit): ?><a class="tlacitko" href="<?= Ui::h(Ui::odkaz('kampane')) ?>">Zrušit</a><?php endif; ?></div>
  </form>
</section>
</div>
