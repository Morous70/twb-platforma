<?php
declare(strict_types=1);
// Přehled: stav kampaní a upozornění. Proměnné: $app, $ui, $post.
use Reklamy\{Ui, Kampane};

$db = $app->db;
$kampane = $app->kampane()->seznam();
$bezi = array_filter($kampane, fn($k) => Kampane::bezi($k));
$pocty = [
    'Běžící kampaně' => count($bezi),
    'Aktivní bannery' => (int)$db->q("SELECT COUNT(*) FROM banners b JOIN campaigns c ON c.id = b.campaign_id WHERE b.status = 'active' AND c.status = 'active'")->fetchColumn(),
    'Weby' => (int)$db->q('SELECT COUNT(*) FROM sites')->fetchColumn(),
    'Zóny' => (int)$db->q('SELECT COUNT(*) FROM zones')->fetchColumn(),
];
$n = $app->nastaveni();
$upozorneni = [];
if ((string)$n->dej('backup_password', '') === '') $upozorneni[] = ['Zálohy nejsou nastavené – zadejte heslo záloh.', Ui::odkaz('nastaveni') . '#zalohy'];
$cron = (int)$n->dej('cron_last_run', 0);
if ($cron < time() - 2 * 86400) $upozorneni[] = [$cron ? 'Cron neběží od ' . Ui::datum($cron) . '.' : 'Cron zatím nikdy neběžel (denní záloha, kontrola aktualizací).', Ui::odkaz('nastaveni') . '#system'];
$bezZony = (int)$db->q("SELECT COUNT(*) FROM campaigns c WHERE c.status = 'active' AND NOT EXISTS (SELECT 1 FROM zone_campaigns z WHERE z.campaign_id = c.id)
    AND NOT EXISTS (SELECT 1 FROM zone_banners zb JOIN banners b ON b.id = zb.banner_id WHERE b.campaign_id = c.id)")->fetchColumn();
if ($bezZony) $upozorneni[] = ["Aktivní kampaně bez zóny: $bezZony (nikde se nezobrazí).", Ui::odkaz('kampane')];
?>
<div class="mrizka">
<?php foreach ($pocty as $popis => $cislo): ?>
  <div class="dlazdice"><div class="cislo"><?= Ui::cislo($cislo) ?></div><div class="popis"><?= Ui::h($popis) ?></div></div>
<?php endforeach; ?>
</div>
<?php foreach ($upozorneni as [$text, $kam]): ?>
  <div class="hlaska hlaska-varovani" style="margin-top:1rem"><?= Ui::h($text) ?> <a href="<?= Ui::h($kam) ?>">Opravit</a></div>
<?php endforeach; ?>

<h2>Běžící kampaně</h2>
<?php if (!$bezi): ?>
  <div class="prazdne">Žádná kampaň teď neběží. <a href="<?= Ui::h(Ui::odkaz('kampan')) ?>">Vytvořit kampaň</a></div>
<?php else: ?>
<div class="tabulka-obal"><table>
  <tr><th>Kampaň</th><th>Druh</th><th>Termín</th><th class="cisla">Bannery</th><th class="cisla">Zóny</th></tr>
  <?php foreach ($bezi as $k): ?>
  <tr>
    <td><a href="<?= Ui::h(Ui::odkaz('kampan', ['id' => $k['id']])) ?>"><?= Ui::h($k['name']) ?></a></td>
    <td><?= Ui::h(explode(' – ', Kampane::DRUHY[$k['kind']])[0]) ?></td>
    <td><?= Ui::h(Kampane::termin($k)) ?></td>
    <td class="cisla"><?= (int)$k['banners'] ?></td>
    <td class="cisla"><?= (int)$k['zones'] ?></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>
<p class="slabe mala">Statistiky zobrazení, prokliků a konverzí přibudou s doručováním reklam.</p>
