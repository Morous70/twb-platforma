<?php
declare(strict_types=1);
// Protokol událostí. Proměnné: $app, $ui.
use MediaGalerie\{Ui, ProtokolDb};

$uroven = (string)($_GET['uroven'] ?? '');
if (!in_array($uroven, ['', 'info', 'warn', 'error'], true)) $uroven = '';
$p = $app->host->protokol;
$zaznamy = $p instanceof ProtokolDb ? $p->posledni(300, $uroven) : [];
$nazvy = ['info' => 'Informace', 'warn' => 'Varování', 'error' => 'Chyba'];
?>
<div class="nastroje">
  <form method="get" class="tlacitka">
    <input type="hidden" name="p" value="protokol">
    <select name="uroven" onchange="this.form.submit()" aria-label="Úroveň">
      <option value="">Vše</option>
      <?php foreach ($nazvy as $k => $t): ?><option value="<?= $k ?>"<?= $k === $uroven ? ' selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
    </select>
    <noscript><button type="submit">Filtrovat</button></noscript>
  </form>
</div>
<?php if (!$zaznamy): ?><div class="prazdne">Protokol je prázdný.</div><?php else: ?>
<div class="tabulka-obal"><table>
  <tr><th>Čas</th><th>Úroveň</th><th>Událost</th></tr>
  <?php foreach ($zaznamy as $z): ?>
  <tr><td class="mala" style="white-space:nowrap"><?= Ui::h(date('j. n. Y H:i:s', (int)$z['at'])) ?></td>
    <td><?= Ui::stav($z['level'] === 'info' ? 'active' : ($z['level'] === 'warn' ? 'paused' : 'archived'), $nazvy[$z['level']] ?? $z['level']) ?></td>
    <td><?= Ui::h($z['message']) ?></td></tr>
  <?php endforeach; ?>
</table></div>
<?php endif;
