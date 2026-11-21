<?php
declare(strict_types=1);
// Externí data: vzdálené zdroje JSON / CSV (modul data). Hodnoty se v textech slidů a HTML banerů píšou jako {Zdroj:cesta}.
// Proměnné: $app, $ui, $post.
use Reklamy\{Ui, Chyba};
use TWB\Data\Vzdalene;

$zdroje = $app->zdroje();
$akce = (string)($_POST['akce'] ?? '');
/** Bannery, jejichž text zdroj jmenuje ({Zdroj:…}). @return list<string> */
$pouziti = fn(string $nazev): array => $app->db->q("SELECT name FROM banners WHERE html LIKE ? OR content LIKE ? ORDER BY name",
    ['%{' . $nazev . ':%', '%{' . $nazev . ':%'])->fetchAll(PDO::FETCH_COLUMN);
if ($post) {
    $zprava = 'Uloženo.';
    $kam = Ui::odkaz('zdroje');
    if ($ui->zpracuj(function () use ($akce, $zdroje, $app, &$zprava, &$kam) {
        $id = (int)($_POST['id'] ?? 0);
        switch ($akce) {
            case 'ulozit':
                try {
                    $r = $zdroje->uloz($id ?: null, (string)($_POST['name'] ?? ''), (string)($_POST['url'] ?? ''), (int)($_POST['interval_min'] ?? 15),
                        (string)($_POST['format'] ?? 'json'), !empty($_POST['csv_header']));
                } catch (InvalidArgumentException $e) { throw new Chyba($e->getMessage()); }
                $zprava = $r['ok'] ? 'Zdroj uložen a stažen.' : 'Zdroj uložen, ale stažení selhalo: ' . $r['error'];
                $kam = Ui::odkaz('zdroje', ['zdroj' => $r['id']]);
                $app->log('info', 'Externí data: zdroj ' . trim((string)$_POST['name']) . ' uložen');
                break;
            case 'stahnout':
                $s = null;
                foreach ($zdroje->vsechny(true) as $x) if ((int)$x['id'] === $id) $s = $x;
                if (!$s) throw new Chyba('Zdroj nenalezen.');
                $r = $zdroje->stahni($s);
                $zprava = $r['ok'] ? ($r['changed'] ? 'Staženo, data se změnila.' : 'Staženo, data jsou stejná.') : 'Stažení selhalo: ' . $r['error'];
                $kam = Ui::odkaz('zdroje', ['zdroj' => $id]);
                break;
            case 'smazat':
                $zdroje->smaz($id);
                $zprava = 'Zdroj smazán.';
                break;
            default: throw new Chyba('Neznámá akce.');
        }
    })) {
        $ui->zprava(str_starts_with($zprava, 'Zdroj uložen, ale') || str_starts_with($zprava, 'Stažení selhalo') ? 'varovani' : 'ok', $zprava);
        $ui->presmeruj($kam);
    }
}
$seznam = $zdroje->vsechny(true);
$detail = null;
$upravit = null;
foreach ($seznam as $s) {
    if ((int)$s['id'] === (int)($_GET['zdroj'] ?? 0)) $detail = $s;
    if ((int)$s['id'] === (int)($_GET['upravit'] ?? 0)) $upravit = $s;
}
$cas = fn($t) => $t ? date('j. n. Y H:i', (int)$t) : '–';
?>
<p class="slabe">Externí data jsou zdroje na jiných serverech (JSON nebo CSV, např. ceník, kurz, výsledky, tabulka Google publikovaná jako CSV).
Aplikace je stahuje sama podle intervalu (cron) a hodnoty se dosazují do textů slidů a HTML banerů jako <code>{Zdroj:cesta}</code>, např. <code>{Ceník:káva.cena}</code>.
Když zdroj neodpovídá, bannery dál ukazují poslední stažená data.</p>
<div class="dva-sloupce">
<section>
  <h2 style="margin-top:0">Zdroje</h2>
  <?php if (!$seznam): ?><div class="prazdne">Zatím žádný zdroj.</div>
  <?php else: ?>
  <div class="tabulka-obal"><table>
    <tr><th>Název</th><th>Formát</th><th>Interval</th><th>Staženo</th><th></th></tr>
    <?php foreach ($seznam as $s): ?>
    <tr>
      <td><a href="<?= Ui::h(Ui::odkaz('zdroje', ['zdroj' => $s['id']])) ?>"><?= Ui::h($s['name']) ?></a><br>
        <span class="slabe mala"><?= Ui::h(mb_strimwidth(Vzdalene::bezpecnaAdresa((string)$s['url']), 0, 70, '…')) ?></span></td>
      <td><?= $s['format'] === 'csv' ? 'CSV' : 'JSON' ?></td>
      <td><?= (int)$s['interval_min'] ?> min</td>
      <td class="mala"><?= $s['error'] ? '<span class="stav stav-paused" title="' . Ui::h($s['error']) . '">chyba</span> ' : '' ?><?= Ui::h($cas($s['ok_at'])) ?></td>
      <td><div class="akce">
        <?= $ui->tlacitko('Stáhnout', 'stahnout', ['id' => $s['id']]) ?>
        <a class="tlacitko maly" href="<?= Ui::h(Ui::odkaz('zdroje', ['upravit' => $s['id']])) ?>#f-name">Upravit</a>
        <?php $pb = $pouziti((string)$s['name']); ?>
        <?= $ui->tlacitko('Smazat', 'smazat', ['id' => $s['id']], 'maly nebezpecne', "Smazat zdroj „{$s['name']}“?"
            . ($pb ? ' Používají ho bannery: ' . implode(', ', array_slice($pb, 0, 5)) . ' – jeho proměnné v nich zůstanou nedosazené.' : '')) ?>
      </div></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>

  <?php Ui::$zPostu = $akce === 'ulozit'; ?>
  <form method="post" class="formular karta" action="<?= Ui::h(Ui::odkaz('zdroje')) ?>">
    <?= $ui->csrf() ?><input type="hidden" name="akce" value="ulozit"><input type="hidden" name="id" value="<?= (int)($upravit['id'] ?? ($_POST['id'] ?? 0)) ?>">
    <h3 style="margin-top:0"><?= $upravit ? 'Upravit zdroj' : 'Nový zdroj' ?></h3>
    <?= Ui::pole('Název', 'name', $upravit['name'] ?? '', ['required' => true, 'maxlength' => 60, 'placeholder' => 'Ceník'], 'V textu banneru {Název:cesta}. Bez znaků { } | : a tečky.') ?>
    <?= Ui::pole('Adresa', 'url', $upravit['url'] ?? '', ['type' => 'url', 'required' => true, 'placeholder' => 'https://…'], 'JSON, CSV nebo odkaz na tabulku Google (publikovanou na webu).') ?>
    <div class="radek">
      <?= Ui::vyber('Formát', 'format', ['json' => 'JSON', 'csv' => 'CSV (tabulka)'], $upravit['format'] ?? 'json') ?>
      <?= Ui::pole('Stahovat každých (min)', 'interval_min', $upravit['interval_min'] ?? 15, ['type' => 'number', 'min' => 5, 'max' => 1440], 'Nejčastěji podle toho, jak často běží cron.') ?>
    </div>
    <label class="zaskrtavatko"><input type="checkbox" name="csv_header" value="1"<?= (int)($upravit['csv_header'] ?? 1) ? ' checked' : '' ?>> CSV má v prvním řádku názvy sloupců</label>
    <div class="tlacitka"><button type="submit" class="hlavni"><?= $upravit ? 'Uložit a stáhnout' : 'Přidat a stáhnout' ?></button><?php if ($upravit): ?> <a href="<?= Ui::h(Ui::odkaz('zdroje')) ?>">Zrušit</a><?php endif; ?></div>
  </form>
  <?php Ui::$zPostu = true; ?>
</section>
<section>
  <?php if (!$detail): ?>
    <h2 style="margin-top:0">Hodnoty</h2>
    <p class="slabe">Klikněte na název zdroje – ukážou se jeho hodnoty a zápis, který vložíte do textu banneru. V editoru slidů je najdete v nabídce proměnných.</p>
  <?php else:
    $strom = $zdroje->strom($detail);
    $pb = $pouziti((string)$detail['name']); ?>
    <h2 style="margin-top:0"><?= Ui::h($detail['name']) ?></h2>
    <p class="mala">Staženo <?= Ui::h($cas($detail['ok_at'])) ?><?= $detail['fetched_at'] && $detail['fetched_at'] !== $detail['ok_at'] ? ' · poslední pokus ' . Ui::h($cas($detail['fetched_at'])) : '' ?>
      <?= $pb ? '<br>Používají ho bannery: ' . Ui::h(implode(', ', $pb)) : '' ?></p>
    <?php if ($detail['error']): ?><div class="hlaska hlaska-chyba">Poslední stažení selhalo: <?= Ui::h($detail['error']) ?>. Bannery používají poslední stažená data.</div><?php endif; ?>
    <?php if (!$strom): ?><div class="prazdne">Zdroj zatím nemá žádná data.</div>
    <?php else: $n = 0; ?>
    <?php foreach ($strom as $sk): if ($n >= 300) break; ?>
      <h3><?= Ui::h($sk['title']) ?></h3>
      <div class="tabulka-obal"><table>
        <tr><th>Zápis</th><th>Hodnota</th></tr>
        <?php foreach ($sk['items'] as $it): if ($it['tech'] || $n++ >= 300) continue; ?>
        <tr><td><code>{<?= Ui::h($detail['name'] . ':' . $it['path']) ?>}</code></td><td><?= Ui::h(mb_strimwidth((string)$it['value'], 0, 120, '…')) ?></td></tr>
        <?php endforeach; ?>
      </table></div>
    <?php endforeach; ?>
    <?php if ($n >= 300): ?><p class="slabe mala">Zobrazeno prvních 300 hodnot.</p><?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</section>
</div>
