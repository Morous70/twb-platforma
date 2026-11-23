<?php
declare(strict_types=1);
// Zóna: nastavení, přiřazení kampaní a bannerů. Proměnné: $app, $ui, $post.
use Reklamy\{Ui, Zony, Bannery, Kampane, Kody, Vydej, Cileni};

$id = (int)($_GET['id'] ?? 0);
$z = $app->zony()->radek($id);
$titulek = $z['site_name'] . ' / ' . $z['name'];
$akce = $post ? (string)($_POST['akce'] ?? '') : '';
if ($post) {
    if ($ui->zpracuj(function () use ($app, $akce, $id, $z, $ui) {
        switch ($akce) {
            case 'ulozit': $app->zony()->uloz($_POST, $id); break;
            case 'priradit': $app->zony()->prirad($id, (array)($_POST['kampane'] ?? []), (array)($_POST['bannery'] ?? [])); break;
            case 'smazat':
                $app->zony()->smaz($id);
                $ui->zprava('ok', 'Zóna smazána.');
                $ui->presmeruj(Ui::odkaz('web', ['id' => $z['site_id']]));
            default: throw new Reklamy\Chyba('Neznámá akce.');
        }
    })) {
        $ui->zprava('ok', 'Uloženo.');
        $ui->presmeruj(Ui::odkaz('zona', ['id' => $id]));
    }
}
$prirazeni = $app->zony()->prirazeni($id);
if ($akce === 'priradit') $prirazeni = ['kampane' => array_map('intval', (array)($_POST['kampane'] ?? [])), 'bannery' => array_map('intval', (array)($_POST['bannery'] ?? []))];
$kampane = $app->kampane()->seznam();
$bannery = $app->bannery()->seznam();
$efektivni = $app->zony()->bannery($id);
$rozmery = ['' => 'Běžné rozměry…'];
foreach (Bannery::ROZMERY as $r => $n) $rozmery[$r] = str_replace('x', '×', $r) . ' – ' . $n;
?>
<p class="slabe">Web: <a href="<?= Ui::h(Ui::odkaz('web', ['id' => $z['site_id']])) ?>"><?= Ui::h($z['site_name']) ?></a> · zóna #<?= (int)$z['id'] ?> · rozměr <?= Ui::h(Zony::rozmer($z)) ?></p>
<div class="dva-sloupce">
<section>
  <form method="post" class="formular karta">
    <h2 style="margin:0">Co se v zóně zobrazuje</h2>
    <?= $ui->csrf() ?>
    <input type="hidden" name="akce" value="priradit">
    <fieldset><legend>Celé kampaně</legend>
      <p class="slabe mala" style="margin-top:0">Platí i pro bannery, které do kampaně přidáte později<?= Zony::maRozmer($z) ? ' (jen rozměr ' . Ui::h(Zony::rozmer($z)) . ')' : '' ?>.</p>
      <?php if (!$kampane): ?><p class="slabe">Žádné kampaně.</p><?php else: ?>
      <div class="seznam-voleb">
        <?php foreach ($kampane as $k): ?>
        <label><input type="checkbox" name="kampane[]" value="<?= (int)$k['id'] ?>"<?= in_array((int)$k['id'], $prirazeni['kampane'], true) ? ' checked' : '' ?>>
          <?= Ui::h($k['name']) ?> <?= Ui::stav($k['status'], Kampane::STAVY[$k['status']]) ?></label>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </fieldset>
    <fieldset><legend>Jednotlivé bannery</legend>
      <?php if (!$bannery): ?><p class="slabe">Žádné bannery.</p><?php else: ?>
      <div class="seznam-voleb">
        <?php foreach ($bannery as $b): $pasuje = Zony::pasuje($z, $b); ?>
        <label class="<?= $pasuje ? '' : 'nepasuje' ?>"><input type="checkbox" name="bannery[]" value="<?= (int)$b['id'] ?>"<?= in_array((int)$b['id'], $prirazeni['bannery'], true) ? ' checked' : '' ?><?= $pasuje ? '' : ' disabled' ?>>
          <?= Ui::h($b['name']) ?> <span class="slabe mala"><?= (int)$b['width'] ?>×<?= (int)$b['height'] ?> · <?= Ui::h(implode(', ', array_column($b['kampane'], 'name'))) ?></span></label>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </fieldset>
    <div><button type="submit" class="hlavni">Uložit přiřazení</button></div>
  </form>

  <h2>Bannery v zóně (<?= count($efektivni) ?>)</h2>
  <?php if (!$efektivni): ?><div class="prazdne">Zóna zatím nemá co zobrazit<?= $z['fallback'] ? ' – zobrazí se náhradní HTML' : '' ?>.</div><?php else: ?>
  <div class="tabulka-obal"><table>
    <tr><th>Banner</th><th>Kampaně</th><th>Rozměr</th><th>Zařízení</th><th>Stav</th></tr>
    <?php foreach ($efektivni as $b): $bz = Bannery::zarizeni($b); ?>
    <tr>
      <td><a href="<?= Ui::h(Ui::odkaz('banner', ['id' => $b['id']])) ?>"><?= Ui::h($b['name']) ?></a></td>
      <td><?= Ui::kampaneBanneru($b) ?></td>
      <td><?= (int)$b['width'] ?>×<?= (int)$b['height'] ?><?= Bannery::maRetinu($b) ? ' <span class="slabe mala" title="Má obrázek pro displeje s vyšší hustotou">2×</span>' : '' ?></td>
      <td><?= $bz ? Ui::h(implode(', ', array_map(fn($x) => Cileni::ZARIZENI[$x], $bz))) : '<span class="slabe">všechna</span>' ?></td>
      <td><?= Ui::stav($b['status'], Bannery::STAVY[$b['status']]) ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>

  <?php if ($rz = Zony::rozmery($z)): ?>
  <h2>Výběr rozměru podle šířky místa</h2>
  <p class="slabe mala">Skript změří, jak široké je místo na stránce, a vybere nejširší rozměr, který se do něj vejde (v rámci druhu a priority kampaně).
    Banner se nikdy nezmenšuje. Když skript šířku nezná (rám, obrázek, volání ze serveru), použije se šířka okna nebo odhad podle zařízení: mobil <?= Reklamy\Kontext::ODHAD_SIRKY['mobil'] ?> px, tablet <?= Reklamy\Kontext::ODHAD_SIRKY['tablet'] ?> px, počítač bez omezení.</p>
  <div class="tabulka-obal"><table>
    <tr><th>Rozměr</th><th>Použije se, když je místo široké</th><th class="cisla">Bannerů</th></tr>
    <?php foreach ($rz as $i => [$rw, $rh]): $pocet = count(array_filter($efektivni, fn($b) => (int)$b['width'] === $rw && (int)$b['height'] === $rh)); ?>
    <tr><td><?= $rw ?>×<?= $rh ?></td>
      <?php $sirsi = array_values(array_filter(array_column($rz, 0), fn($x) => $x > $rw)); ?>
      <td><?= $sirsi ? "{$rw}–" . (min($sirsi) - 1) . ' px' : "{$rw} px a víc" ?><?= count(array_filter($rz, fn($x) => $x[0] === $rw)) > 1 ? ' <span class="slabe mala">(víc rozměrů této šířky – vyhraje vyšší)</span>' : '' ?></td>
      <td class="cisla"><?= $pocet ?: '<span class="slabe">0 – přeskočí se</span>' ?></td></tr>
    <?php endforeach; ?>
    <tr><td class="slabe">–</td><td class="slabe">méně než <?= $rz[count($rz) - 1][0] ?> px: nic<?= $z['fallback'] ? ' (náhradní HTML)' : '' ?></td><td></td></tr>
  </table></div>
  <?php endif; ?>

  <h2 id="kod">Kód pro vložení na web</h2>
  <?php if (Zony::jeResponzivni($z)): ?>
  <p class="slabe mala">Responzivní zónu vložte <b>asynchronním JavaScriptem</b> (nebo JavaScriptem): jen skript zná šířku místa. Rám (iframe) má pevný rozměr <?= Ui::h(implode('×', $app->zony()->rozmerVlozeni($z))) ?> px
    a banner v něm se vybírá jen podle zařízení z prohlížeče.</p>
  <?php elseif ($z['width'] === null || $z['height'] === null): [$kw, $kh] = $app->zony()->rozmerVlozeni($z);
    $chybi = $z['width'] === null && $z['height'] === null ? 'pevný rozměr' : ($z['width'] === null ? 'pevnou šířku' : 'pevnou výšku'); ?>
  <p class="slabe mala">Zóna nemá <?= $chybi ?>. Rám (iframe) a vyskakovací okno potřebují oba rozměry, proto mají v kódu <?= $kw ?>×<?= $kh ?> px
    (chybějící rozměr podle největšího banneru zóny<?= $efektivni ? '' : ', zatím výchozí' ?>). Asynchronní a JavaScriptové vložení se přizpůsobí samo.</p>
  <?php endif; ?>
  <?php $kody = new Kody($app); $prvni = true;
  foreach (Kody::ZPUSOBY as $zp => [$nazev, $popis]): ?>
  <details class="kod-vlozeni"<?= $prvni ? ' open' : '' ?>>
    <summary><?= Ui::h($nazev) ?></summary>
    <p class="slabe mala"><?= Ui::h($popis) ?></p>
    <textarea id="kod-<?= $zp ?>" class="kod" readonly rows="<?= in_array($zp, ['php', 'xmlrpc', 'json'], true) ? 12 : 4 ?>"><?= Ui::h($kody->kod($z, $zp)) ?></textarea>
    <div class="tlacitka" style="margin-top:.4rem"><button type="button" class="maly" data-kopirovat="kod-<?= $zp ?>">Kopírovat</button></div>
  </details>
  <?php $prvni = false; endforeach; ?>
</section>
<section>
  <h2 style="margin-top:0">Náhled</h2>
  <?php $vydej = new Vydej($app); [$nw, $nh] = $app->zony()->rozmerVlozeni($z); ?>
  <div class="nahled" style="margin-bottom:1rem">
    <iframe src="<?= Ui::h($app->url('d/ram.php') . '?' . http_build_query(['zona' => $id, 'nahled' => $vydej->podpisNahledu($id), 'cb' => random_int(1, 1_000_000)])) ?>"
      width="<?= $nw ?>" height="<?= $nh ?>" title="Náhled zóny" style="background:transparent"></iframe>
  </div>
  <p class="slabe mala" style="margin-top:-.5rem">Náhled vybere banner stejně jako na webu (obnovením stránky se střídají), ale nepočítá se do statistik.</p>
  <?php Ui::$zPostu = $akce === 'ulozit'; ?>
  <form method="post" class="formular karta">
    <h2 style="margin:0">Nastavení zóny</h2>
    <?= $ui->csrf() ?>
    <input type="hidden" name="akce" value="ulozit">
    <?= Ui::pole('Název', 'name', $z['name'], ['required' => true, 'maxlength' => 200]) ?>
    <?= Ui::vyber('Druh', 'kind', Zony::DRUHY, $z['kind']) ?>
    <div class="radek">
      <?= Ui::pole('Šířka (px)', 'width', $z['width'], ['type' => 'number', 'min' => 1, 'max' => Bannery::MAX_ROZMER]) ?>
      <?= Ui::pole('Výška (px)', 'height', $z['height'], ['type' => 'number', 'min' => 1, 'max' => Bannery::MAX_ROZMER]) ?>
    </div>
    <?= Ui::pole('Responzivní zóna – rozměry pro různé šířky', 'sizes', implode(', ', array_map(fn($x) => "{$x[0]}x{$x[1]}", Zony::rozmery($z))),
        ['placeholder' => '970x250, 728x90, 300x250', 'maxlength' => 200],
        'Pro PC, tablet i mobil: zóna bere bannery všech uvedených rozměrů a na stránce vybere nejširší, který se vejde do místa. Šířku a výšku výše pak nechte prázdné.') ?>
    <label class="pole"><span>Běžné rozměry</span><select data-rozmery><?php foreach ($rozmery as $k => $t): ?><option value="<?= Ui::h($k) ?>"><?= Ui::h($t) ?></option><?php endforeach; ?></select>
      <small>Prázdný rozměr je libovolný: bez šířky i výšky zóna přijme banner jakékoli velikosti, jen se šířkou (nebo jen s výškou) bannery s touto šířkou (výškou) a libovolným druhým rozměrem. Přímo přiřazené bannery, které po změně nesedí, se odeberou.</small></label>
    <?= Ui::oblast('Náhradní HTML', 'fallback', $z['fallback'], ['class' => 'kod', 'rows' => 4, 'spellcheck' => 'false'], 'Zobrazí se, když zóna nemá žádný banner k dispozici. Prázdné = nic.') ?>
    <?= Ui::oblast('Poznámka', 'note', $z['note'], ['rows' => 2]) ?>
    <div><button type="submit" class="hlavni">Uložit</button></div>
  </form>
  <?php Ui::$zPostu = true; ?>
  <?= $ui->tlacitko('Smazat zónu', 'smazat', [], 'maly nebezpecne', 'Smazat zónu „' . $z['name'] . '“? Kód vložený na webu přestane zobrazovat reklamy.') ?>
</section>
</div>
