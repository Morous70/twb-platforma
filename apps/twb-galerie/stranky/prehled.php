<?php
declare(strict_types=1);
// Přehled: počty médií podle druhu a obsazené místo. Proměnné: $app, $ui.
use MediaGalerie\Ui;
use TWB\Zalohy\Zalohy;

$s = $app->media()->souhrn();
$velikost = $s['velikost'];
$posledni = $app->media()->seznam(['razeni' => 'nove'], 8)['polozky'];
?>
<div class="mrizka">
  <?= Ui::dlazdice('Obrázky', Ui::cislo($s['image']), 'obrazky') ?>
  <?= Ui::dlazdice('Videa', Ui::cislo($s['video']), 'videa') ?>
  <?= Ui::dlazdice('Obsazené místo', $velikost > 0 ? Ui::h(Zalohy::velikost($velikost)) : '0 B', 'velikost') ?>
  <?= Ui::dlazdice('V archivu', Ui::cislo($s['archiv']), 'galerie') ?>
</div>
<?php if ($app->emailObnovy() === ''): ?>
<div class="hlaska hlaska-varovani">Není nastavený e-mail pro obnovu hesla. Doplňte ho v <a href="<?= Ui::h(Ui::odkaz('nastaveni')) ?>#zabezpeceni">Nastavení → Zabezpečení</a>.</div>
<?php endif; ?>
<?php if ($posledni): ?>
<h2>Naposledy nahrané</h2>
<div class="galerie galerie-mala">
  <?php foreach ($posledni as $m): ?>
  <figure><a class="nahled-medium" href="<?= Ui::h(Ui::odkaz('medium', ['id' => $m['id']])) ?>" title="<?= Ui::h($m['original_name']) ?>">
    <?php if ($m['kind'] === 'image'): ?><img src="media.php?id=<?= (int)$m['id'] ?>&amp;nahled=1&amp;v=<?= Ui::h(substr((string)$m['md5'], 0, 8)) ?>" alt="" loading="lazy">
    <?php elseif ($m['kind'] === 'video'): ?><video src="media.php?id=<?= (int)$m['id'] ?>#t=0.5" preload="metadata" muted playsinline></video><span class="znacka">Video</span>
    <?php else: ?><span class="pdf-ikona">PDF</span><?php endif; ?></a>
    <figcaption><strong><?= Ui::h($m['original_name']) ?></strong></figcaption></figure>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<div class="karta">
  <h2 style="margin-top:0">Začínáme</h2>
  <p>Média nahrajete na stránce <a href="<?= Ui::h(Ui::odkaz('galerie')) ?>">Galerie</a> a roztřídíte do <a href="<?= Ui::h(Ui::odkaz('kampane')) ?>">kampaní</a>. Každý soubor má veřejný odkaz pro vložení na web. Přihlášení přes Google a Passkey nastavíte v <a href="<?= Ui::h(Ui::odkaz('nastaveni')) ?>">Nastavení</a>.</p>
</div>
