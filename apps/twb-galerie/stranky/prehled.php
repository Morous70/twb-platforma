<?php
declare(strict_types=1);
// Přehled: počty médií podle druhu a obsazené místo. Proměnné: $app, $ui.
use MediaGalerie\Ui;
use TWB\Zalohy\Zalohy;

$pocty = ['image' => 0, 'video' => 0, 'pdf' => 0];
$velikost = 0;
foreach ($app->db->q('SELECT kind, COUNT(*) AS n, COALESCE(SUM(size), 0) AS s FROM media WHERE archived_at IS NULL GROUP BY kind')->fetchAll() as $r) {
    $pocty[$r['kind']] = (int)$r['n'];
    $velikost += (int)$r['s'];
}
$archiv = (int)$app->db->q('SELECT COUNT(*) FROM media WHERE archived_at IS NOT NULL')->fetchColumn();
?>
<div class="mrizka">
  <?= Ui::dlazdice('Obrázky', Ui::cislo($pocty['image']), 'obrazky') ?>
  <?= Ui::dlazdice('Videa', Ui::cislo($pocty['video']), 'videa') ?>
  <?= Ui::dlazdice('Obsazené místo', $velikost > 0 ? Ui::h(Zalohy::velikost($velikost)) : '0 B', 'velikost') ?>
  <?= Ui::dlazdice('V archivu', Ui::cislo($archiv), 'galerie') ?>
</div>
<?php if ($app->emailObnovy() === ''): ?>
<div class="hlaska hlaska-varovani">Není nastavený e-mail pro obnovu hesla. Doplňte ho v <a href="<?= Ui::h(Ui::odkaz('nastaveni')) ?>#zabezpeceni">Nastavení → Zabezpečení</a>.</div>
<?php endif; ?>
<div class="karta">
  <h2 style="margin-top:0">Začínáme</h2>
  <p>Média nahrajete na stránce <a href="<?= Ui::h(Ui::odkaz('galerie')) ?>">Galerie</a>. Přihlášení přes Google a Passkey nastavíte v <a href="<?= Ui::h(Ui::odkaz('nastaveni')) ?>">Nastavení</a>.</p>
</div>
