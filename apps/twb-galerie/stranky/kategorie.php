<?php
declare(strict_types=1);
// Kategorie: složky médií. Nová, přejmenování, smazání (média zůstanou bez kategorie). Proměnné: $app, $ui, $post.
use MediaGalerie\{Ui, Chyba};
use TWB\Zalohy\Zalohy;

$kategorie = $app->kategorie();
$upravit = isset($_GET['upravit']) ? $kategorie->radek((int)$_GET['upravit']) : null;

if ($post) {
    $akce = (string)($_POST['akce'] ?? '');
    $zprava = 'Uloženo.';
    if ($ui->zpracuj(function () use ($akce, $kategorie, $upravit, &$zprava) {
        switch ($akce) {
            case 'ulozit':
                $kategorie->uloz($_POST, $upravit ? (int)$upravit['id'] : null);
                break;
            case 'smazat':
                $n = $kategorie->smaz((int)($_POST['id'] ?? 0));
                $zprava = 'Kategorie smazána' . ($n ? ", {$n} médií zůstalo v galerii bez kategorie." : '.');
                break;
            default:
                throw new Chyba('Neznámá akce.');
        }
    })) {
        $ui->zprava('ok', $zprava);
        $ui->presmeruj(Ui::odkaz('kategorie'));
    }
}
$seznam = $kategorie->seznam();
$bez = (int)$app->db->q('SELECT COUNT(*) FROM media WHERE category_id IS NULL AND archived_at IS NULL')->fetchColumn();
?>
<div class="dva-sloupce">
<section>
<?php if (!$seznam): ?>
  <div class="prazdne">Zatím žádná kategorie. Kategorie jsou složky, do kterých média roztřídíte.</div>
<?php else: ?>
  <div class="tabulka-obal"><table>
    <tr><th>Kategorie</th><th class="cisla">Médií</th><th class="cisla">Velikost</th><th></th></tr>
    <?php foreach ($seznam as $k): ?>
    <tr><td><a href="<?= Ui::h(Ui::odkaz('galerie', ['kategorie' => $k['id']])) ?>"><strong><?= Ui::h($k['name']) ?></strong></a>
        <?php if ($k['note']): ?><div class="slabe mala"><?= Ui::h($k['note']) ?></div><?php endif; ?></td>
      <td class="cisla"><?= Ui::cislo($k['pocet']) ?></td><td class="cisla"><?= Ui::h(Zalohy::velikost((int)$k['velikost'])) ?></td>
      <td class="tlacitka" style="justify-content:flex-end"><a class="tlacitko maly" href="<?= Ui::h(Ui::odkaz('kategorie', ['upravit' => $k['id']])) ?>">Upravit</a>
        <?= $ui->tlacitko('Smazat', 'smazat', ['id' => $k['id']], 'maly nebezpecne', 'Smazat kategorii „' . $k['name'] . '“? Média zůstanou v galerii bez kategorie.') ?></td></tr>
    <?php endforeach; ?>
  </table></div>
<?php endif; ?>
  <p class="slabe mala"><a href="<?= Ui::h(Ui::odkaz('galerie', ['kategorie' => 0])) ?>">Média bez kategorie</a>: <?= Ui::cislo($bez) ?></p>
</section>
<section>
  <form method="post" class="formular karta" action="<?= Ui::h(Ui::odkaz('kategorie', $upravit ? ['upravit' => $upravit['id']] : [])) ?>">
    <h2 style="margin:0"><?= $upravit ? 'Upravit kategorii' : 'Nová kategorie' ?></h2>
    <?= $ui->csrf() ?>
    <input type="hidden" name="akce" value="ulozit">
    <?= Ui::pole('Název', 'name', $upravit['name'] ?? '', ['required' => true, 'maxlength' => 200]) ?>
    <?= Ui::oblast('Poznámka', 'note', $upravit['note'] ?? '', ['rows' => 3]) ?>
    <div class="tlacitka"><button type="submit" class="hlavni"><?= $upravit ? 'Uložit' : 'Vytvořit' ?></button>
      <?php if ($upravit): ?><a class="tlacitko" href="<?= Ui::h(Ui::odkaz('kategorie')) ?>">Zrušit</a><?php endif; ?></div>
  </form>
</section>
</div>
