<?php
declare(strict_types=1);
// Přehled: stav kampaní a upozornění. Proměnné: $app, $ui, $post.
use Reklamy\{Ui, Kampane, Statistiky};

$db = $app->db;
$kampane = $app->kampane()->seznam();
$bezi = array_filter($kampane, fn($k) => Kampane::bezi($k));
$pocty = [
    'Běžící kampaně' => [count($bezi), 'kampane'],
    'Aktivní bannery' => [(int)$db->q("SELECT COUNT(*) FROM banners b WHERE b.status = 'active' AND EXISTS (SELECT 1 FROM campaign_banners cb JOIN campaigns c ON c.id = cb.campaign_id WHERE cb.banner_id = b.id AND c.status = 'active')")->fetchColumn(), 'bannery'],
    'Weby' => [(int)$db->q('SELECT COUNT(*) FROM sites')->fetchColumn(), 'weby'],
    'Zóny' => [(int)$db->q('SELECT COUNT(*) FROM zones')->fetchColumn(), 'zony'],
];
$n = $app->nastaveni();
$upozorneni = [];
if ((string)$n->dej('backup_password', '') === '') $upozorneni[] = ['Zálohy nejsou nastavené – zadejte heslo záloh.', Ui::odkaz('nastaveni') . '#zalohy'];
$cron = (int)$n->dej('cron_last_run', 0);
if ($cron < time() - 2 * 86400) $upozorneni[] = [$cron ? 'Cron neběží od ' . Ui::datum($cron) . '.' : 'Cron zatím nikdy neběžel (denní záloha, kontrola aktualizací).', Ui::odkaz('nastaveni') . '#system'];
$bezZony = (int)$db->q("SELECT COUNT(*) FROM campaigns c WHERE c.status = 'active' AND NOT EXISTS (SELECT 1 FROM zone_campaigns z WHERE z.campaign_id = c.id)
    AND NOT EXISTS (SELECT 1 FROM zone_banners zb JOIN campaign_banners cb ON cb.banner_id = zb.banner_id WHERE cb.campaign_id = c.id)")->fetchColumn();
if ($bezZony) $upozorneni[] = ["Aktivní kampaně bez zóny: $bezZony (nikde se nezobrazí).", Ui::odkaz('kampane')];
?>
<?php
$st = $app->statistiky();
$dnes = $st->souhrn(date('Y-m-d'), date('Y-m-d'));
$tyden = $st->souhrn(date('Y-m-d', strtotime('-6 days')), date('Y-m-d'));
?>
<div class="mrizka">
  <?= Ui::dlazdice('Zobrazení dnes', Ui::cislo($dnes['impressions']) . ' <small class="slabe mala">7 dní: ' . Ui::cislo($tyden['impressions']) . '</small>', 'zobrazeni') ?>
  <?= Ui::dlazdice('Prokliky dnes', Ui::cislo($dnes['clicks']) . ' <small class="slabe mala">7 dní: ' . Ui::cislo($tyden['clicks']) . '</small>', 'prokliky') ?>
  <?= Ui::dlazdice('CTR za 7 dní', Ui::h(Statistiky::ctr($tyden['impressions'], $tyden['clicks'])), 'ctr') ?>
</div>
<div class="mrizka">
<?php foreach ($pocty as $popis => [$cislo, $ikona]): ?>
  <?= Ui::dlazdice(Ui::h($popis), Ui::cislo($cislo), $ikona) ?>
<?php endforeach; ?>
</div>
<?php foreach ($upozorneni as [$text, $kam]): ?>
  <div class="hlaska hlaska-varovani"><?= Ui::h($text) ?> <a href="<?= Ui::h($kam) ?>">Opravit</a></div>
<?php endforeach; ?>

<h2>Běžící kampaně</h2>
<?php if (!$bezi): ?>
  <div class="prazdne">Žádná kampaň teď neběží. <a href="<?= Ui::h(Ui::odkaz('kampan')) ?>">Vytvořit kampaň</a></div>
<?php else: ?>
<div class="tabulka-obal"><table>
  <tr><th>Kampaň</th><th>Druh</th><th>Termín</th><th class="cisla">Bannery</th><th class="cisla">Zóny</th><th class="cisla">Zobrazení 7 dní</th><th class="cisla">Prokliky</th></tr>
  <?php foreach ($bezi as $k): $s7 = $st->souhrn(date('Y-m-d', strtotime('-6 days')), null, (int)$k['id']); ?>
  <tr>
    <td><a href="<?= Ui::h(Ui::odkaz('kampan', ['id' => $k['id']])) ?>"><?= Ui::h($k['name']) ?></a></td>
    <td><?= Ui::h(explode(' – ', Kampane::DRUHY[$k['kind']])[0]) ?></td>
    <td><?= Ui::h(Kampane::termin($k)) ?></td>
    <td class="cisla"><?= (int)$k['banners'] ?></td>
    <td class="cisla"><?= (int)$k['zones'] ?></td>
    <td class="cisla"><?= Ui::cislo($s7['impressions']) ?></td>
    <td class="cisla"><?= Ui::cislo($s7['clicks']) ?></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>
<p class="slabe mala">Podrobné statistiky po dnech, zónách a webech, grafy a export najdete ve <a href="<?= Ui::h(Ui::odkaz('statistiky')) ?>">Statistikách</a>.</p>
