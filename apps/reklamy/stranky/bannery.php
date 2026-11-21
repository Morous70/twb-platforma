<?php
declare(strict_types=1);
// Všechny bannery (mimo ty, které jsou jen v archivovaných kampaních). Banner může být ve více kampaních. Proměnné: $app, $ui, $post.
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
  <span class="slabe mala">Nový banner přidáte v detailu kampaně, do dalších kampaní ho přiřadíte v úpravě banneru.</span>
</div>
<?php if (!$seznam): ?>
  <div class="prazdne">Žádné bannery. <a href="<?= Ui::h(Ui::odkaz('kampane')) ?>">Otevřít kampaně</a></div>
<?php else: ?>
<div class="tabulka-obal"><table>
  <tr><th></th><th>Banner</th><th>Kampaně</th><th>Druh</th><th>Rozměr</th><th>Stav</th><th><span class="jen-ctecka">Náhled</span></th></tr>
  <?php foreach ($seznam as $b): ?>
  <tr>
    <td><?php if ($b['type'] === 'image'): ?><img class="miniatura" src="media.php?id=<?= (int)$b['media_id'] ?>" alt=""><?php endif; ?></td>
    <td><a href="<?= Ui::h(Ui::odkaz('banner', ['id' => $b['id']])) ?>"><?= Ui::h($b['name']) ?></a></td>
    <td><?= Ui::kampaneBanneru($b) ?></td>
    <td><?= Ui::h(Bannery::TYPY[$b['type']] ?? $b['type']) ?></td>
    <td><?= (int)$b['width'] ?>×<?= (int)$b['height'] ?></td>
    <td><?= Ui::stav($b['status'], Bannery::STAVY[$b['status']]) ?></td>
    <td><?= Ui::nahledTlacitko($b) ?></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php endif;
