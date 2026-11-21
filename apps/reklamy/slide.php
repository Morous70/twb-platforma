<?php
declare(strict_types=1);
// Editor slidu (modul slidy). slide.php?kampan=K&w=300&h=250 = nový slide v kampani, slide.php?id=N = úprava banneru se slidem.
use Reklamy\{App, Ui, Chyba};

/** @var App $app */
$app = require __DIR__ . '/lib/bootstrap.php';
if (!$app->nainstalovano()) { header('Location: install.php'); exit; }
$app->migruj();
$ui = new Ui($app);
if (!$app->relace()->jePrihlasen($app->limitNecinnosti())) $ui->presmeruj('index.php');

try {
    if (!empty($_GET['id'])) {
        $b = $app->bannery()->radek((int)$_GET['id']);
        if ($b['type'] !== 'slide') throw new Chyba('Banner není slide.');
        $kampan = $app->kampane()->radek((int)$b['campaign_id']);
        $titulek = $b['name'];
    } else {
        $kampan = $app->kampane()->radek((int)($_GET['kampan'] ?? 0));
        $titulek = 'Nový slide';
    }
} catch (Chyba $e) {
    $ui->zprava('chyba', $e->getMessage());
    $ui->presmeruj(Ui::odkaz('kampane'));
}
$v = Ui::h($app->verze);
$data = ['csrf' => $app->relace()->csrfToken(), 'kampan' => (int)$kampan['id'], 'zpet' => Ui::odkaz('kampan', ['id' => $kampan['id']]),
    'formaty' => array_keys(Reklamy\SlideBannery::formaty())];
ob_start(); ?>
<link rel="stylesheet" href="asset.php?f=slide-editor.css&amp;v=<?= $v ?>">
<p class="slabe">Kampaň: <a href="<?= Ui::h($data['zpet']) ?>"><?= Ui::h($kampan['name']) ?></a> · slide se uloží jako banner (obrázek JPEG) a vykreslí se znovu, když se změní proměnné v textu.</p>
<div class="pm slide-stranka">
  <div id="main"><p class="hint">Načítám editor…</p></div>
  <div class="modal" id="modal" hidden><div class="modal-box" id="modalBox"></div></div>
  <div class="toasts" id="toasts"></div>
</div>
<script>window.REKLAMY = <?= json_encode($data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="assets/slide-shim.js?v=<?= $v ?>" defer></script>
<script src="asset.php?f=slide-editor.js&amp;v=<?= $v ?>" defer></script>
<script src="assets/slide-init.js?v=<?= $v ?>" defer></script>
<?php
$ui->stranka($titulek, 'kampane', (string)ob_get_clean());
