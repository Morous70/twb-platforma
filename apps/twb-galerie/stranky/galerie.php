<?php
declare(strict_types=1);
// Galerie médií. Proměnné: $app, $ui, $post. Správa médií (nahrávání, tagy, kampaně, odkazy) přibude ve verzi 0.2.
use MediaGalerie\Ui;

$media = $app->db->q('SELECT id, original_name, kind, size, width, height, created_at FROM media WHERE archived_at IS NULL ORDER BY id DESC LIMIT 200')->fetchAll();
?>
<?php if (!$media): ?>
<div class="prazdne">Galerie je zatím prázdná. Nahrávání přibude v další verzi aplikace.</div>
<?php else: ?>
<div class="tabulka-obal"><table>
  <tr><th>Název</th><th>Druh</th><th class="cisla">Rozměr</th><th>Nahráno</th></tr>
  <?php foreach ($media as $m): ?>
  <tr><td><?= Ui::h($m['original_name']) ?></td><td><?= Ui::h($m['kind']) ?></td>
    <td class="cisla"><?= $m['width'] ? (int)$m['width'] . '×' . (int)$m['height'] : '–' ?></td><td class="mala"><?= Ui::h($m['created_at']) ?></td></tr>
  <?php endforeach; ?>
</table></div>
<?php endif;
