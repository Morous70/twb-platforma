<?php
declare(strict_types=1);
// Komprimovat obrázky (hromadná akce galerie, 0.8.0): průběh po krocích a souhrn. Krok volá assets/app.js (JSON), bez JS tlačítko Pokračovat.
// Proměnné: $app, $ui, $post.
use MediaGalerie\{Ui, Chyba, Komprese};
use TWB\Zalohy\Zalohy;

$komprese = $app->komprese();

if ($post) {
    $akce = (string)($_POST['akce'] ?? '');
    $json = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    if ($akce === 'krok') {
        try {
            $stav = Komprese::stav($komprese->krok());
            $chyba = null;
        } catch (Chyba $e) {
            $stav = null;
            $chyba = $e->getMessage();
        }
        if ($json) {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode($stav !== null ? ['ok' => true] + $stav : ['ok' => false, 'error' => $chyba], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($chyba !== null) $ui->zprava('chyba', $chyba);
        $ui->presmeruj(Ui::odkaz('komprese'));
    }
    if ($akce === 'zavrit') {
        $u = $komprese->uloha();
        $komprese->zrus();
        $ui->presmeruj((string)($u['zpet'] ?? Ui::odkaz('galerie')));
    }
    if ($akce === 'zrusit') {
        $u = $komprese->uloha();
        $komprese->zrus();
        if ($u && $u['konec'] === null) $app->log('info', 'Hromadná komprese přerušena po ' . (int)$u['i'] . ' z ' . count($u['ids']) . ' obrázků: ' . Komprese::souhrn($u));
        $ui->zprava('ok', 'Komprese přerušena. Už zkomprimované obrázky zůstávají zkomprimované.');
        $ui->presmeruj((string)($u['zpet'] ?? Ui::odkaz('galerie')));
    }
    throw new Chyba('Neznámá akce.');
}

$u = $komprese->uloha();
if (!$u): ?>
<div class="prazdne">Žádná komprese neprobíhá. V <a href="<?= Ui::h(Ui::odkaz('galerie')) ?>">galerii</a> vyberte obrázky a zvolte akci <strong>Komprimovat obrázky</strong>.</div>
<?php return; endif;
$s = Komprese::stav($u);
$v = $u['volby'];
$popisVoleb = Komprese::FORMATY[$v['format']] . ", kvalita {$v['kvalita']}, " . ($v['rozmer'] ? "nejvýš {$v['rozmer']} px" : 'bez zmenšení')
    . ', ' . ($v['ponechat'] ? 'originály se ponechají' : 'originály se smažou') . ', ' . ($v['metadata'] ? 'metadata zůstanou' : 'bez metadat');
$procent = $s['celkem'] ? (int)floor($s['zpracovano'] * 100 / $s['celkem']) : 100;
?>
<div class="karta komprese" <?= $s['hotovo'] ? '' : 'data-komprese' ?> data-csrf="<?= Ui::h($app->relace()->csrfToken()) ?>">
  <p class="slabe mala"><?= Ui::h($popisVoleb) ?><?= $u['jine'] ? ' · ' . (int)$u['jine'] . ' vybraných médií nejsou obrázky, ty se přeskočí' : '' ?></p>
  <progress max="<?= (int)$s['celkem'] ?>" value="<?= (int)$s['zpracovano'] ?>" data-komprese-prubeh style="width:100%;height:1.2em"><?= $procent ?> %</progress>
  <p data-komprese-text><?php if ($s['hotovo']): ?><strong>Hotovo:</strong> <?= Ui::h(Komprese::souhrn($u)) ?>
    <?php else: ?>Zpracováno <?= (int)$s['zpracovano'] ?> z <?= (int)$s['celkem'] ?> obrázků, zatím ušetřeno <?= Ui::h($s['usetreno_text']) ?>. Nechte stránku otevřenou, obrázky se zpracovávají po dávkách.<?php endif; ?></p>
  <?php if ($s['hotovo'] && $u['zkomprimovano']): ?>
  <div class="mrizka" style="margin:1rem 0">
    <?= Ui::dlazdice('Zkomprimováno', Ui::cislo($u['zkomprimovano']) . ' / ' . Ui::cislo($s['celkem']), 'obrazky') ?>
    <?= Ui::dlazdice('Soubory menší o', Ui::h(Zalohy::velikost(max(0, $u['pred'] - $u['po']))), 'velikost') ?>
    <?= Ui::dlazdice('Místo na disku', Ui::h(($u['disk_pred'] >= $u['disk_po'] ? '−' : '+') . Zalohy::velikost(abs($u['disk_pred'] - $u['disk_po']))), 'velikost') ?>
  </div>
  <?php if ($v['ponechat']): ?><p class="slabe mala">Originály zůstaly na disku, takže v detailu média jde komprese vrátit tlačítkem <em>Vrátit původní soubor</em>.
    Místo na disku uvolníte, když kompresi spustíte znovu bez volby <em>Ponechat originál</em> (vychází se vždy z originálu).</p><?php endif; ?>
  <?php endif; ?>
  <?php if ($u['chyby']): ?>
  <details<?= $s['hotovo'] ? ' open' : '' ?>><summary>Chyby (<?= count($u['chyby']) ?>)</summary><ul class="mala">
    <?php foreach (array_slice($u['chyby'], 0, 100) as $c): ?><li><?= Ui::h($c) ?></li><?php endforeach; ?></ul></details>
  <?php endif; ?>
  <div class="tlacitka">
    <?php if ($s['hotovo']): ?>
      <?= $ui->tlacitko('Zpět do galerie', 'zavrit', [], 'hlavni') ?>
    <?php else: ?>
      <form method="post" class="inline"><?= $ui->csrf() ?><input type="hidden" name="akce" value="krok"><button type="submit" class="hlavni" data-komprese-pokracovat>Pokračovat</button></form>
      <?= $ui->tlacitko('Přerušit', 'zrusit', [], 'maly nebezpecne', 'Přerušit kompresi? Už zkomprimované obrázky zůstanou zkomprimované.') ?>
    <?php endif; ?>
  </div>
</div>
