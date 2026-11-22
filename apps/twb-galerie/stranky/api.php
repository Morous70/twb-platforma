<?php
declare(strict_types=1);
// API klíče: vytvoření (klíč se ukáže jednou), seznam, zrušení; adresa API a odkaz na popis. Proměnné: $app, $ui, $post.
use MediaGalerie\{Ui, Chyba, ApiKlice, Api};

$klice = $app->apiKlice();
$novy = null;
if ($post) {
    $akce = (string)($_POST['akce'] ?? '');
    if ($akce === 'vytvorit') {
        // nový klíč se ukáže hned na této stránce (bez přesměrování), do relace se neukládá
        $ui->zpracuj(function () use ($klice, &$novy) {
            $novy = $klice->vytvor((string)($_POST['nazev'] ?? ''), (string)($_POST['opravneni'] ?? ''));
        });
    } elseif ($ui->zpracuj(function () use ($akce, $klice) {
        if ($akce !== 'smazat') throw new Chyba('Neznámá akce.');
        $klice->smaz((int)($_POST['id'] ?? 0));
    })) {
        $ui->zprava('ok', 'Klíč zrušen. Požadavky s ním už neprojdou.');
        $ui->presmeruj(Ui::odkaz('api'));
    }
}
$seznam = $klice->seznam();
$adresa = $app->url('api.php/' . Api::VERZE);
?>
<?php if ($novy): ?>
<div class="karta hlaska-ok">
  <p style="margin-top:0"><b>Nový API klíč</b> – zkopírujte si ho teď, znovu už ho neuvidíte:</p>
  <div class="tlacitka"><input id="novy-klic" readonly value="<?= Ui::h($novy) ?>" style="flex:1;font-family:monospace"><button type="button" class="maly" data-kopirovat="novy-klic">Kopírovat</button></div>
</div>
<?php endif; ?>
<div class="dva-sloupce">
<section>
  <div class="karta">
    <h2 style="margin-top:0">Adresa API</h2>
    <div class="tlacitka"><input id="adresa-api" readonly value="<?= Ui::h($adresa) ?>" style="flex:1"><button type="button" class="maly" data-kopirovat="adresa-api">Kopírovat</button></div>
    <p class="mala">Klíč se posílá v hlavičce <code>Authorization: Bearer tgk_…</code>. Přehled cest, polí a příkladů je v <a href="<?= Ui::h(Ui::odkaz('napoveda', ['s' => 'api'])) ?>">nápovědě k API</a>.</p>
    <pre class="kod mala">curl -H "Authorization: Bearer tgk_…" <?= Ui::h($adresa) ?>/media</pre>
  </div>
  <?php if (!$seznam): ?>
    <div class="prazdne">Zatím žádný klíč. Bez klíče API nic nevrátí.</div>
  <?php else: ?>
  <div class="tabulka-obal"><table>
    <tr><th>Klíč</th><th>Oprávnění</th><th>Vytvořen</th><th>Naposledy</th><th></th></tr>
    <?php foreach ($seznam as $k): ?>
    <tr><td><strong><?= Ui::h($k['name']) ?></strong><div class="slabe mala"><code><?= Ui::h($k['prefix']) ?>…</code></div></td>
      <td><?= Ui::stav($k['scope'] === 'write' ? 'paused' : 'active', ApiKlice::OPRAVNENI[$k['scope']] ?? $k['scope']) ?></td>
      <td class="mala"><?= Ui::h(Ui::datum($k['created_at'])) ?></td><td class="mala"><?= Ui::h(Ui::datum($k['last_used_at'])) ?></td>
      <td><?= $ui->tlacitko('Zrušit', 'smazat', ['id' => $k['id']], 'maly nebezpecne', 'Zrušit klíč „' . $k['name'] . '“? Skripty, které ho používají, přestanou fungovat.') ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</section>
<section>
  <form method="post" class="formular karta">
    <h2 style="margin:0">Nový klíč</h2>
    <?= $ui->csrf() ?>
    <input type="hidden" name="akce" value="vytvorit">
    <?php Ui::$zPostu = !$novy; ?>
    <?= Ui::pole('Název', 'nazev', '', ['required' => true, 'maxlength' => 100, 'placeholder' => 'např. web kazbo.eu'], 'Podle názvu klíč poznáte v seznamu a v protokolu.') ?>
    <?= Ui::vyber('Oprávnění', 'opravneni', ApiKlice::OPRAVNENI, 'read', [], 'Čtení stačí pro výpis a stažení souborů. Zápis smí nahrávat, upravovat a mazat.') ?>
    <?php Ui::$zPostu = true; ?>
    <div><button type="submit" class="hlavni">Vytvořit klíč</button></div>
  </form>
</section>
</div>
