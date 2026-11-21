<?php
declare(strict_types=1);
// Proměnné do textů banerů (slidy a HTML): kalendář, vlastní proměnné a tabulka dat Skupina | Parametr | Hodnota.
// Proměnné: $app, $ui, $post.
use Reklamy\{Ui, Chyba, VlastniPromenne};

$vlastni = new VlastniPromenne($app);
$tab = $app->tabulkaDat();
$akce = (string)($_POST['akce'] ?? '');
$zkouska = null;
if ($post) {
    $zprava = 'Uloženo.';
    if ($akce === 'zkusit') {
        $text = mb_substr((string)($_POST['text'] ?? ''), 0, 2000);
        $zkouska = $app->promenne()->vyres($text, new DateTimeImmutable(), 0, true);
    } elseif ($ui->zpracuj(function () use ($akce, $vlastni, $tab, $app, &$zprava) {
        switch ($akce) {
            case 'promenna': $vlastni->uloz($_POST, ($_POST['puvodni'] ?? '') !== '' ? (string)$_POST['puvodni'] : null); break;
            case 'promenna-smazat': $vlastni->smaz((string)($_POST['name'] ?? '')); $zprava = 'Proměnná smazána.'; break;
            case 'radek':
                try { $tab->uloz(null, $_POST['grp'] ?? '', $_POST['param'] ?? '', $_POST['value'] ?? ''); }
                catch (InvalidArgumentException $e) { throw new Chyba('Řádek nelze uložit: ' . $e->getMessage() . '.'); }
                break;
            case 'radky-smazat':
                $ids = array_map('intval', (array)($_POST['ids'] ?? []));
                if (!$ids) throw new Chyba('Zaškrtněte řádky, které chcete smazat.');
                $zprava = 'Smazáno řádků: ' . $tab->smaz($ids) . '.';
                break;
            case 'minule': $zprava = 'Smazáno řádků s minulým datem: ' . $tab->smazMinule(new DateTimeImmutable('today')) . '.'; break;
            case 'import':
                try { $r = $tab->importuj((string)($_POST['text'] ?? ''), !empty($_POST['nahradit'])); }
                catch (InvalidArgumentException $e) { throw new Chyba($e->getMessage()); }
                $zprava = "Vloženo řádků: {$r['imported']}." . ($r['errors'] ? ' Přeskočeno: ' . implode('; ', array_slice($r['errors'], 0, 3)) . '.' : '');
                break;
            default: throw new Chyba('Neznámá akce.');
        }
        $app->log('info', "Proměnné: $akce");
    })) {
        $ui->zprava('ok', $zprava);
        $ui->presmeruj(Ui::odkaz('promenne'));
    }
}
$t = new DateTimeImmutable();
$vanoce = new DateTimeImmutable($t->format('Y') . '-12-24');
if ($vanoce < $t->setTime(0, 0)) $vanoce = $vanoce->modify('+1 year');
$kalendar = ['datum' => 'dnešní datum', 'den' => 'den v týdnu ({Den} s velkým písmenem)', 'mesic' => 'měsíc', 'rok' => 'rok', 'tyden' => 'číslo týdne',
    'cas' => 'čas', 'svatek' => 'kdo má svátek', 'datum:+1' => 'zítřejší datum (i -1, +7 …)', 'svatek:+1' => 'svátek zítra',
    'odpocet:' . $vanoce->format('j. n. Y') => 'odpočet do data', 'dnu:' . $vanoce->format('j. n. Y') => 'počet dní do data', 'datum|D. MĚSÍCE' => 'datum ve vlastním formátu'];
$radky = $tab->radky(true);
$upravit = isset($_GET['upravit']) ? ($vlastni->seznam()[mb_strtolower((string)$_GET['upravit'])] ?? null) : null;
?>
<p class="slabe">Proměnné se píšou do textů slidů a HTML banerů ve složených závorkách, např. <code>{datum}</code> nebo <code>{Akce:sleva}</code>. Dosazují se při každém zobrazení banneru; slide se pak vykreslí znovu.
Za názvem mohou být filtry, např. <code>{cena|mil}</code> nebo <code>{nic|vychozi:—}</code> (hodnota, když proměnná chybí).</p>
<div class="dva-sloupce">
<section>
  <h2 style="margin-top:0">Kalendář</h2>
  <div class="tabulka-obal"><table>
    <tr><th>Zápis</th><th>Teď</th><th>Význam</th></tr>
    <?php foreach ($kalendar as $zapis => $popis): ?>
    <tr><td><code>{<?= Ui::h($zapis) ?>}</code></td><td><?= Ui::h($app->promenne()->vyres('{' . $zapis . '}', $t, 0, true)[0]) ?></td><td class="slabe mala"><?= Ui::h($popis) ?></td></tr>
    <?php endforeach; ?>
  </table></div>

  <h2>Vlastní proměnné</h2>
  <?php $seznam = $vlastni->seznam();
  if (!$seznam): ?><div class="prazdne">Zatím žádné. Hodí se pro texty, které se mění na více místech najednou (kód slevy, termín akce).</div>
  <?php else: ?>
  <div class="tabulka-obal"><table>
    <tr><th>Zápis</th><th>Hodnota</th><th></th></tr>
    <?php foreach ($seznam as $p): ?>
    <tr><td><code>{<?= Ui::h($p['name']) ?>}</code><?= $p['type'] === 'list' ? ' <span class="slabe mala">seznam</span>' : '' ?></td>
      <td><?= nl2br(Ui::h(mb_strimwidth($p['value'], 0, 160, '…'))) ?></td>
      <td class="akce"><a class="tlacitko maly" href="<?= Ui::h(Ui::odkaz('promenne', ['upravit' => $p['name']])) ?>#f-name">Upravit</a>
        <?= $ui->tlacitko('Smazat', 'promenna-smazat', ['name' => $p['name']], 'maly nebezpecne', "Smazat proměnnou „{$p['name']}“? Texty, které ji používají, ji pak zobrazí beze změny.") ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
  <?php Ui::$zPostu = $akce === 'promenna'; ?>
  <form method="post" class="formular karta" action="<?= Ui::h(Ui::odkaz('promenne')) ?>">
    <?= $ui->csrf() ?><input type="hidden" name="akce" value="promenna"><input type="hidden" name="puvodni" value="<?= Ui::h($upravit['name'] ?? ($_POST['puvodni'] ?? '')) ?>">
    <h3 style="margin-top:0"><?= $upravit ? 'Upravit proměnnou' : 'Nová proměnná' ?></h3>
    <div class="radek">
      <?= Ui::pole('Název', 'name', $upravit['name'] ?? '', ['required' => true, 'maxlength' => 40, 'placeholder' => 'kod_slevy']) ?>
      <?= Ui::vyber('Druh', 'type', VlastniPromenne::TYPY, $upravit['type'] ?? 'text') ?>
    </div>
    <?= Ui::oblast('Hodnota', 'value', $upravit['value'] ?? '', ['rows' => 3], 'Seznam: na každém řádku jedna hodnota – opakovaný výskyt v textu bere další řádek.') ?>
    <div class="tlacitka"><button type="submit" class="hlavni">Uložit proměnnou</button><?php if ($upravit): ?> <a href="<?= Ui::h(Ui::odkaz('promenne')) ?>">Zrušit</a><?php endif; ?></div>
  </form>

  <h2>Vyzkoušet</h2>
  <?php Ui::$zPostu = $akce === 'zkusit'; ?>
  <form method="post" class="formular karta">
    <?= $ui->csrf() ?><input type="hidden" name="akce" value="zkusit">
    <?= Ui::oblast('Text', 'text', 'Dnes je {den} {datum}, svátek má {svatek}.', ['rows' => 2]) ?>
    <?php if ($zkouska !== null): ?>
      <p><b>Výsledek:</b> <?= nl2br(Ui::h($zkouska[0])) ?></p>
      <?php if ($zkouska[1]): ?><div class="hlaska hlaska-varovani">Neznámé proměnné: <?= Ui::h(implode(', ', $zkouska[1])) ?></div><?php endif; ?>
    <?php endif; ?>
    <div class="tlacitka"><button type="submit">Vyzkoušet</button></div>
  </form>
</section>
<section>
  <h2 style="margin-top:0">Tabulka dat</h2>
  <p class="slabe mala">Řádky Skupina | Parametr | Hodnota. V textu <code>{Skupina:parametr}</code> (hodnota řádku s tímto parametrem), <code>{Skupina}</code>, když má skupina jediný řádek. Když je parametr datum, <code>{Skupina.nejbližší}</code>, <code>{Skupina.další}</code> a <code>{Skupina.poslední}</code> vyberou řádek podle data (<code>{Skupina.nejbližší.datum}</code> vrátí jeho datum).</p>
  <?php if (!$radky): ?><div class="prazdne">Tabulka je prázdná.</div>
  <?php else: ?>
  <form method="post" data-potvrdit="Smazat vybrané řádky?">
    <?= $ui->csrf() ?><input type="hidden" name="akce" value="radky-smazat">
    <div class="tabulka-obal"><table>
      <tr><th></th><th>Skupina</th><th>Parametr</th><th>Hodnota</th></tr>
      <?php foreach ($radky as $r): ?>
      <tr><td><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" aria-label="Vybrat"></td><td><?= Ui::h($r['grp']) ?></td><td><?= Ui::h($r['param']) ?></td><td><?= Ui::h(mb_strimwidth((string)$r['value'], 0, 120, '…')) ?></td></tr>
      <?php endforeach; ?>
    </table></div>
    <div class="tlacitka"><button type="submit" class="maly nebezpecne">Smazat vybrané</button></div>
  </form>
  <?= $ui->tlacitko('Smazat řádky s minulým datem', 'minule', [], 'maly', 'Smazat řádky, jejichž parametr je datum před dneškem?') ?>
  <?php endif; ?>
  <?php Ui::$zPostu = $akce === 'radek'; ?>
  <form method="post" class="formular karta">
    <?= $ui->csrf() ?><input type="hidden" name="akce" value="radek">
    <h3 style="margin-top:0">Přidat řádek</h3>
    <div class="radek">
      <?= Ui::pole('Skupina', 'grp', '', ['required' => true, 'maxlength' => 100, 'placeholder' => 'Akce']) ?>
      <?= Ui::pole('Parametr', 'param', '', ['required' => true, 'maxlength' => 200, 'placeholder' => 'sleva']) ?>
      <?= Ui::pole('Hodnota', 'value', '', ['maxlength' => 2000, 'placeholder' => '20 %']) ?>
    </div>
    <div class="tlacitka"><button type="submit">Přidat</button></div>
  </form>
  <?php Ui::$zPostu = $akce === 'import'; ?>
  <form method="post" class="formular karta">
    <?= $ui->csrf() ?><input type="hidden" name="akce" value="import">
    <h3 style="margin-top:0">Vložit z Excelu</h3>
    <?= Ui::oblast('Řádky', 'text', '', ['rows' => 5, 'class' => 'kod', 'placeholder' => "Akce\tsleva\t20 %\nAkce\tdo\t30. 11. 2026"], 'Zkopírujte tři sloupce (Skupina, Parametr, Hodnota) z Excelu nebo je oddělte středníkem.') ?>
    <label class="zaskrtavatko"><input type="checkbox" name="nahradit" value="1"> Nahradit dosavadní řádky vložených skupin</label>
    <div class="tlacitka"><button type="submit">Vložit</button></div>
  </form>
  <?php Ui::$zPostu = true; ?>
</section>
</div>
