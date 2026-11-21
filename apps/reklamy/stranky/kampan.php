<?php
declare(strict_types=1);
// Nová kampaň nebo úprava kampaně, její bannery a zóny. Proměnné: $app, $ui, $post.
use Reklamy\{Ui, Kampane, Bannery, Vstup, Zony, Chyba, Statistiky, Cileni};

$kampane = $app->kampane();
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$k = $id !== null ? $kampane->radek($id) : null;
$titulek = $k ? $k['name'] : 'Nová kampaň';

if ($post) {
    $akce = (string)($_POST['akce'] ?? 'ulozit');
    $bid = (int)($_POST['banner'] ?? 0);
    $ok = $ui->zpracuj(function () use ($akce, $kampane, $app, &$id, $bid, $ui) {
        switch ($akce) {
            case 'ulozit':
                $id = $kampane->uloz($_POST, $id);
                break;
            case 'smazat':
                $kampane->smaz((int)$id);
                $ui->zprava('ok', 'Kampaň smazána.');
                $ui->presmeruj(Ui::odkaz('kampane'));
            case 'banner-stav':
                if ((int)$app->bannery()->radek($bid)['campaign_id'] !== $id) throw new Chyba('Banner patří jiné kampani.');
                $app->bannery()->nastavStav($bid, (string)($_POST['stav'] ?? ''));
                break;
            case 'banner-kopie':
                if ((int)$app->bannery()->radek($bid)['campaign_id'] !== $id) throw new Chyba('Banner patří jiné kampani.');
                $app->bannery()->duplikuj($bid);
                break;
            default:
                throw new Chyba('Neznámá akce.');
        }
    });
    if ($ok) {
        $ui->zprava('ok', 'Uloženo.');
        $ui->presmeruj(Ui::odkaz('kampan', ['id' => $id]));
    }
}
$z = $k ?? ['name' => '', 'status' => 'active', 'kind' => 'standard', 'priority' => 5, 'weight' => 1, 'starts_at' => null, 'ends_at' => null,
    'limit_impressions' => null, 'limit_clicks' => null, 'limit_conversions' => null, 'daily_impressions' => null, 'daily_clicks' => null, 'note' => '', 'tags' => null,
    'targeting' => null, 'cap_impressions' => null, 'cap_hours' => null];
$tagy = implode(', ', $kampane->tagy()->tagyPolozky($z));
$cil = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['akce'] ?? '') === 'ulozit') ? $_POST + ['cil_dny' => [], 'cil_zarizeni' => []] : Cileni::doFormulare($z['targeting'] ?? null);
$zaskrtnuto = fn(string $pole, string|int $h) => in_array((string)$h, array_map('strval', (array)($cil[$pole] ?? [])), true) ? ' checked' : '';
$bannery = $k ? $app->bannery()->seznam((int)$k['id']) : [];
$stB = $k ? $app->statistiky()->poBannerech(null, null, (int)$k['id']) : [];
$stK = $k ? $app->statistiky()->souhrn(null, null, (int)$k['id']) : null;
$stDnes = $k ? $app->statistiky()->souhrn(date('Y-m-d'), date('Y-m-d'), (int)$k['id']) : null;
?>
<div class="dva-sloupce">
<section>
<form method="post" class="formular karta">
  <?= $ui->csrf() ?>
  <input type="hidden" name="akce" value="ulozit">
  <?= Ui::pole('Název', 'name', $z['name'], ['required' => true, 'maxlength' => 200, 'autofocus' => $k === null]) ?>
  <div class="radek">
    <?= Ui::vyber('Stav', 'status', Kampane::STAVY, $z['status']) ?>
    <?= Ui::vyber('Druh', 'kind', Kampane::DRUHY, $z['kind']) ?>
  </div>
  <div class="radek">
    <?= Ui::pole('Priorita (1–10)', 'priority', $z['priority'], ['type' => 'number', 'min' => 1, 'max' => 10], 'Vyšší má přednost v rámci stejného druhu.') ?>
    <?= Ui::pole('Váha', 'weight', $z['weight'], ['type' => 'number', 'min' => 1, 'max' => 1000], 'Poměr střídání se stejně důležitými kampaněmi.') ?>
  </div>
  <div class="radek">
    <?= Ui::pole('Začátek', 'starts_at', Vstup::casDoPole($z['starts_at'] ? (int)$z['starts_at'] : null), ['type' => 'datetime-local']) ?>
    <?= Ui::pole('Konec', 'ends_at', Vstup::casDoPole($z['ends_at'] ? (int)$z['ends_at'] : null), ['type' => 'datetime-local']) ?>
  </div>
  <fieldset><legend>Limity (prázdné = bez limitu)</legend>
    <div class="radek">
      <?= Ui::pole('Zobrazení celkem', 'limit_impressions', $z['limit_impressions'], ['type' => 'number', 'min' => 1]) ?>
      <?= Ui::pole('Prokliky celkem', 'limit_clicks', $z['limit_clicks'], ['type' => 'number', 'min' => 1]) ?>
      <?= Ui::pole('Konverze celkem', 'limit_conversions', $z['limit_conversions'], ['type' => 'number', 'min' => 1]) ?>
    </div>
    <div class="radek">
      <?= Ui::pole('Zobrazení za den', 'daily_impressions', $z['daily_impressions'], ['type' => 'number', 'min' => 1]) ?>
      <?= Ui::pole('Prokliky za den', 'daily_clicks', $z['daily_clicks'], ['type' => 'number', 'min' => 1]) ?>
    </div>
  </fieldset>
  <details class="cileni"<?= !empty($z['targeting']) || !empty($z['cap_impressions']) ? ' open' : '' ?>><summary>Cílení a frekvence<?= $k ? ' <span class="slabe mala">(' . Ui::h(Cileni::popis($z['targeting'] ?? null, $z['cap_impressions'] ? (int)$z['cap_impressions'] : null, $z['cap_hours'] ? (int)$z['cap_hours'] : null)) . ')</span>' : '' ?></summary>
    <p class="slabe mala">Nic nezaškrtnuté = bez omezení. Stránku a odkud pozná vložení skriptem, rámem i odkazem (podle hlavičky Referer); vložení ze serveru jen když je pošle.</p>
    <div class="pole"><span>Dny v týdnu</span><div class="volby"><?php foreach (Cileni::DNY as $d => $n): ?>
      <label class="zaskrtavatko"><input type="checkbox" name="cil_dny[]" value="<?= $d ?>"<?= $zaskrtnuto('cil_dny', $d) ?>> <?= $n ?></label><?php endforeach; ?></div></div>
    <div class="radek">
      <?= Ui::pole('Od hodiny', 'cil_od', $cil['cil_od'] ?? '', ['type' => 'number', 'min' => 0, 'max' => 23, 'placeholder' => '0']) ?>
      <?= Ui::pole('Do hodiny', 'cil_do', $cil['cil_do'] ?? '', ['type' => 'number', 'min' => 1, 'max' => 24, 'placeholder' => '24'], 'Např. 22–6 = přes noc.') ?>
    </div>
    <div class="pole"><span>Zařízení</span><div class="volby"><?php foreach (Cileni::ZARIZENI as $zk => $n): ?>
      <label class="zaskrtavatko"><input type="checkbox" name="cil_zarizeni[]" value="<?= $zk ?>"<?= $zaskrtnuto('cil_zarizeni', $zk) ?>> <?= $n ?></label><?php endforeach; ?></div></div>
    <?= Ui::pole('Jazyk prohlížeče', 'cil_jazyky', $cil['cil_jazyky'] ?? '', ['placeholder' => 'cs, sk', 'maxlength' => 100]) ?>
    <?= Ui::oblast('Jen na stránkách, jejichž adresa obsahuje', 'cil_stranky', $cil['cil_stranky'] ?? '', ['rows' => 2, 'placeholder' => "kazbo.eu/clanky/
/sport"], 'Každý řádek zvlášť, stačí shoda jednoho.') ?>
    <?= Ui::oblast('Ne na stránkách, jejichž adresa obsahuje', 'cil_bez_stranek', $cil['cil_bez_stranek'] ?? '', ['rows' => 2]) ?>
    <?= Ui::oblast('Jen když návštěvník přišel z adresy obsahující', 'cil_odkud', $cil['cil_odkud'] ?? '', ['rows' => 2, 'placeholder' => "google.
seznam.cz"]) ?>
    <div class="radek">
      <?= Ui::pole('Nejvýš zobrazení jednomu návštěvníkovi', 'cap_impressions', $z['cap_impressions'] ?? null, ['type' => 'number', 'min' => 1, 'max' => 1000]) ?>
      <?= Ui::pole('Za hodin', 'cap_hours', $z['cap_hours'] ?? null, ['type' => 'number', 'min' => 1, 'max' => 720, 'placeholder' => '24'], 'Frekvence se hlídá cookie návštěvníka (Nastavení → Cookies).') ?>
    </div>
  </details>
  <?= Ui::pole('Tagy', 'tags', $tagy, ['maxlength' => 400], 'Oddělte čárkou, nejvýš 10.') ?>
  <?= Ui::oblast('Poznámka', 'note', $z['note'], ['rows' => 3]) ?>
  <div class="tlacitka">
    <button type="submit" class="hlavni"><?= $k ? 'Uložit' : 'Vytvořit kampaň' ?></button>
    <a href="<?= Ui::h(Ui::odkaz('kampane')) ?>">Zpět na kampaně</a>
  </div>
</form>
<?php if ($k): ?>
  <?= $ui->tlacitko('Smazat kampaň', 'smazat', [], 'maly nebezpecne', 'Smazat kampaň „' . $k['name'] . '“ i se všemi bannery?') ?>
<?php endif; ?>
</section>

<?php if ($k): ?>
<section>
  <div class="mrizka" style="grid-template-columns:repeat(auto-fill,minmax(9rem,1fr));margin-bottom:1rem">
    <div class="dlazdice"><div class="cislo"><?= Ui::cislo($stK['impressions']) ?></div><div class="popis">Zobrazení (dnes <?= Ui::cislo($stDnes['impressions']) ?>)</div></div>
    <div class="dlazdice"><div class="cislo"><?= Ui::cislo($stK['clicks']) ?></div><div class="popis">Prokliky (dnes <?= Ui::cislo($stDnes['clicks']) ?>)</div></div>
    <div class="dlazdice"><div class="cislo"><?= Statistiky::ctr($stK['impressions'], $stK['clicks']) ?></div><div class="popis">CTR</div></div>
    <div class="dlazdice"><div class="cislo"><?= Ui::cislo($stK['conversions']) ?></div><div class="popis"><a href="<?= Ui::h(Ui::odkaz('konverze')) ?>">Konverze</a> (dnes <?= Ui::cislo($stDnes['conversions']) ?>)</div></div>
    <div class="dlazdice"><div class="cislo"><?= Ui::h(Reklamy\Konverze::kc($stK['revenue']) ?: '0 Kč') ?></div><div class="popis">Hodnota konverzí</div></div>
  </div>
  <h2 style="margin-top:0">Bannery</h2>
  <div class="tlacitka" style="margin-bottom:.8rem">
    <?php foreach (Bannery::TYPY as $typ => $popis): ?>
      <a class="tlacitko maly" href="<?= Ui::h(Ui::odkaz('banner', ['kampan' => $k['id'], 'typ' => $typ])) ?>">+ <?= Ui::h($popis) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if (!$bannery): ?>
    <div class="prazdne">Kampaň zatím nemá bannery.</div>
  <?php else: ?>
  <div class="tabulka-obal"><table>
    <?php foreach ($bannery as $b): ?>
    <tr>
      <td><?php if ($b['type'] === 'image'): ?><img class="miniatura" src="media.php?id=<?= (int)$b['media_id'] ?>" alt=""><?php endif; ?></td>
      <td><a href="<?= Ui::h(Ui::odkaz('banner', ['id' => $b['id']])) ?>"><?= Ui::h($b['name']) ?></a><br>
        <span class="slabe mala"><?= Ui::h(Bannery::TYPY[$b['type']] ?? $b['type']) ?>, <?= (int)$b['width'] ?>×<?= (int)$b['height'] ?></span></td>
      <td><?= Ui::stav($b['status'], Bannery::STAVY[$b['status']]) ?></td>
      <td class="cisla mala"><?= Ui::cislo($stB[$b['id']]['impressions'] ?? 0) ?> / <?= Ui::cislo($stB[$b['id']]['clicks'] ?? 0) ?><?= !empty($stB[$b['id']]['conversions']) ? ' / ' . Ui::cislo($stB[$b['id']]['conversions']) . ' konv.' : '' ?></td>
      <td><div class="akce">
        <?= $b['status'] === 'active' ? $ui->tlacitko('Pozastavit', 'banner-stav', ['banner' => $b['id'], 'stav' => 'paused']) : $ui->tlacitko('Spustit', 'banner-stav', ['banner' => $b['id'], 'stav' => 'active']) ?>
        <?= $ui->tlacitko('Kopie', 'banner-kopie', ['banner' => $b['id']]) ?>
      </div></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>

  <h2>Zóny</h2>
  <?php
  $zony = $app->db->q('SELECT z.*, s.name AS site_name FROM zone_campaigns zc JOIN zones z ON z.id = zc.zone_id JOIN sites s ON s.id = z.site_id WHERE zc.campaign_id = ? ORDER BY s.name, z.name', [$k['id']])->fetchAll();
  if (!$zony): ?>
    <p class="slabe">Kampaň není přiřazená do žádné zóny jako celek. Přiřazení se nastavuje u zóny (<a href="<?= Ui::h(Ui::odkaz('weby')) ?>">Weby a zóny</a>).</p>
  <?php else: ?>
    <ul><?php foreach ($zony as $zn): ?>
      <li><a href="<?= Ui::h(Ui::odkaz('zona', ['id' => $zn['id']])) ?>"><?= Ui::h($zn['site_name'] . ' / ' . $zn['name']) ?></a> <span class="slabe mala">(<?= Ui::h(Zony::rozmer($zn)) ?>)</span></li>
    <?php endforeach; ?></ul>
  <?php endif; ?>
</section>
<?php endif; ?>
</div>
