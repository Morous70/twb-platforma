<?php
declare(strict_types=1);
// Všechny bannery (mimo archivované kampaně). Proměnné: $app, $ui, $post.
use Reklamy\{Ui, Bannery};

$roz = (string)($_GET['rozmer'] ?? '');
$seznam = $app->bannery()->seznam();
$rozmery = [];
foreach ($seznam as $b) $rozmery[$b['width'] . 'x' . $b['height']] = $b['width'] . '×' . $b['height'];
ksort($rozmery, SORT_NATURAL);
if ($roz !== '') $seznam = array_values(array_filter($seznam, fn($b) => $b['width'] . 'x' . $b['height'] === $roz));
?>
<div class="nastroje">
  <form method="get" class="tlacitka">
    <input type="hidden" name="p" value="bannery">
    <select name="rozmer" onchange="this.form.submit()" aria-label="Rozměr">
      <option value="">Všechny rozměry</option>
      <?php foreach ($rozmery as $k => $t): ?><option value="<?= Ui::h($k) ?>"<?= $k === $roz ? ' selected' : '' ?>><?= Ui::h($t) ?></option><?php endforeach; ?>
    </select>
    <noscript><button type="submit">Filtrovat</button></noscript>
  </form>
  <span class="slabe mala">Nový banner přidáte v detailu kampaně.</span>
</div>
<?php if (!$seznam): ?>
  <div class="prazdne">Žádné bannery. <a href="<?= Ui::h(Ui::odkaz('kampane')) ?>">Otevřít kampaně</a></div>
<?php else: ?>
<div class="tabulka-obal"><table>
  <tr><th></th><th>Banner</th><th>Kampaň</th><th>Druh</th><th>Rozměr</th><th>Stav</th></tr>
  <?php foreach ($seznam as $b): ?>
  <tr>
    <td><?php if ($b['type'] === 'image'): ?><img class="miniatura" src="media.php?id=<?= (int)$b['media_id'] ?>" alt=""><?php endif; ?></td>
    <td><a href="<?= Ui::h(Ui::odkaz('banner', ['id' => $b['id']])) ?>"><?= Ui::h($b['name']) ?></a></td>
    <td><a href="<?= Ui::h(Ui::odkaz('kampan', ['id' => $b['campaign_id']])) ?>"><?= Ui::h($b['campaign_name']) ?></a></td>
    <td><?= Ui::h(Bannery::TYPY[$b['type']] ?? $b['type']) ?></td>
    <td><?= (int)$b['width'] ?>×<?= (int)$b['height'] ?></td>
    <td><?= Ui::stav($b['status'], Bannery::STAVY[$b['status']]) ?></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php endif;
