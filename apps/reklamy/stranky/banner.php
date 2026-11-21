<?php
declare(strict_types=1);
// Nový banner nebo úprava banneru. Proměnné: $app, $ui, $post.
use Reklamy\{Ui, Bannery, Zony, Chyba};

$bannery = $app->bannery();
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$b = $id !== null ? $bannery->radek($id) : null;
$kampan = $app->kampane()->radek((int)($b['campaign_id'] ?? $_GET['kampan'] ?? 0));
$typ = $b['type'] ?? (string)($_GET['typ'] ?? 'image');
if (!isset(Bannery::TYPY[$typ])) throw new Chyba('Neznámý druh banneru.');
$titulek = $b ? $b['name'] : 'Nový banner – ' . mb_strtolower(Bannery::TYPY[$typ]);

if ($post) {
    $akce = (string)($_POST['akce'] ?? 'ulozit');
    if ($akce === 'smazat' && $b) {
        if ($ui->zpracuj(fn() => $bannery->smaz((int)$id))) {
            $ui->zprava('ok', 'Banner smazán.');
            $ui->presmeruj(Ui::odkaz('kampan', ['id' => $kampan['id']]));
        }
    } elseif ($ui->zpracuj(function () use ($bannery, &$id, $kampan, $typ) {
        $id = $bannery->uloz(['campaign_id' => $kampan['id'], 'type' => $typ] + $_POST, $id);
    })) {
        $ui->zprava('ok', 'Banner uložen.');
        $ui->presmeruj(Ui::odkaz('banner', ['id' => $id]));
    }
}
$z = $b ?? ['name' => '', 'status' => 'active', 'width' => null, 'height' => null, 'weight' => 1, 'media_id' => null, 'image_url' => '', 'html' => '',
    'target_url' => '', 'target' => '_blank', 'alt' => '', 'note' => ''];
$rozmery = ['' => 'Běžné rozměry…'];
foreach (Bannery::ROZMERY as $r => $n) $rozmery[$r] = str_replace('x', '×', $r) . ' – ' . $n;
?>
<p class="slabe">Kampaň: <a href="<?= Ui::h(Ui::odkaz('kampan', ['id' => $kampan['id']])) ?>"><?= Ui::h($kampan['name']) ?></a> · <?= Ui::h(Bannery::TYPY[$typ]) ?></p>
<div class="dva-sloupce">
<section>
<form method="post" class="formular karta">
  <?= $ui->csrf() ?>
  <input type="hidden" name="akce" value="ulozit">
  <?= Ui::pole('Název', 'name', $z['name'], ['required' => true, 'maxlength' => 200]) ?>
  <?php if ($typ === 'image'):
      $obrazky = array_values(array_filter($app->galerie()->vypis(), fn($m) => $m['druh'] === 'image' && !$m['archivovano'])); ?>
    <?php if (!$obrazky): ?>
      <div class="hlaska hlaska-varovani">V galerii zatím nejsou obrázky. <a href="<?= Ui::h(Ui::odkaz('galerie')) ?>">Nahrát obrázek</a></div>
    <?php endif; ?>
    <label class="pole" for="f-media_id"><span>Obrázek z galerie</span>
      <select id="f-media_id" name="media_id" required>
        <option value="">Vyberte…</option>
        <?php $vybrany = Ui::hodnota('media_id', $z['media_id']);
        foreach ($obrazky as $m): ?>
          <option value="<?= $m['id'] ?>" data-w="<?= (int)$m['sirka'] ?>" data-h="<?= (int)$m['vyska'] ?>"<?= (string)$m['id'] === $vybrany ? ' selected' : '' ?>>
            <?= Ui::h($m['nazev']) ?> (<?= (int)$m['sirka'] ?>×<?= (int)$m['vyska'] ?>)</option>
        <?php endforeach; ?>
      </select><small>Rozměr banneru se doplní podle obrázku.</small></label>
  <?php elseif ($typ === 'external'): ?>
    <?= Ui::pole('Adresa obrázku', 'image_url', $z['image_url'], ['type' => 'url', 'required' => true, 'placeholder' => 'https://…']) ?>
  <?php elseif ($typ === 'html'): ?>
    <?= Ui::oblast('HTML kód', 'html', $z['html'], ['class' => 'kod', 'rows' => 14, 'spellcheck' => 'false', 'required' => true],
        'Kód se zobrazí v izolovaném rámci (iframe) o rozměru banneru. Proměnné pro adresu prokliku apod. přibudou s doručováním.') ?>
  <?php endif; ?>
  <div class="radek">
    <?= Ui::pole('Šířka (px)', 'width', $z['width'], ['type' => 'number', 'min' => 1, 'max' => Bannery::MAX_ROZMER, 'required' => $typ !== 'image']) ?>
    <?= Ui::pole('Výška (px)', 'height', $z['height'], ['type' => 'number', 'min' => 1, 'max' => Bannery::MAX_ROZMER, 'required' => $typ !== 'image']) ?>
    <label class="pole"><span>&nbsp;</span><select data-rozmery aria-label="Běžné rozměry"><?php foreach ($rozmery as $k => $t): ?><option value="<?= Ui::h($k) ?>"><?= Ui::h($t) ?></option><?php endforeach; ?></select></label>
  </div>
  <?= Ui::pole('Cílová adresa (proklik)', 'target_url', $z['target_url'], ['type' => 'url', 'placeholder' => 'https://…']) ?>
  <div class="radek">
    <?= Ui::vyber('Otevřít', 'target', Bannery::CILE, $z['target']) ?>
    <?= Ui::vyber('Stav', 'status', Bannery::STAVY, $z['status']) ?>
    <?= Ui::pole('Váha', 'weight', $z['weight'], ['type' => 'number', 'min' => 1, 'max' => 1000], 'Poměr střídání v kampani.') ?>
  </div>
  <?php if ($typ !== 'html'): ?>
    <?= Ui::pole('Popis obrázku (alt)', 'alt', $z['alt'], ['maxlength' => 255]) ?>
  <?php endif; ?>
  <?= Ui::oblast('Poznámka', 'note', $z['note'], ['rows' => 2]) ?>
  <div class="tlacitka">
    <button type="submit" class="hlavni"><?= $b ? 'Uložit' : 'Vytvořit banner' ?></button>
    <a href="<?= Ui::h(Ui::odkaz('kampan', ['id' => $kampan['id']])) ?>">Zpět na kampaň</a>
  </div>
</form>
<?php if ($b): ?>
  <?= $ui->tlacitko('Smazat banner', 'smazat', [], 'maly nebezpecne', 'Smazat banner „' . $b['name'] . '“?') ?>
<?php endif; ?>
</section>
<section>
  <h2 style="margin-top:0">Náhled</h2>
  <div class="nahled">
  <?php if ($typ === 'image'): ?>
    <img id="nahled-galerie" src="<?= $z['media_id'] ? 'media.php?id=' . (int)$z['media_id'] : '' ?>" alt="" <?= $b ? 'width="' . (int)$b['width'] . '" height="' . (int)$b['height'] . '"' : '' ?>>
  <?php elseif ($typ === 'external' && $b): ?>
    <img src="<?= Ui::h($b['image_url']) ?>" alt="" width="<?= (int)$b['width'] ?>" height="<?= (int)$b['height'] ?>" referrerpolicy="no-referrer">
  <?php elseif ($typ === 'html' && $b): ?>
    <iframe sandbox="allow-scripts allow-popups" srcdoc="<?= Ui::h($b['html']) ?>" width="<?= (int)$b['width'] ?>" height="<?= (int)$b['height'] ?>" title="Náhled banneru"></iframe>
  <?php else: ?>
    <p class="slabe">Náhled se zobrazí po uložení.</p>
  <?php endif; ?>
  </div>
  <?php if ($b): ?>
  <h2>Zobrazuje se v zónách</h2>
  <?php $zony = $app->zony()->zonyBanneru($b);
  if (!$zony): ?><p class="slabe">Zatím v žádné zóně.</p>
  <?php else: ?><ul><?php foreach ($zony as $zn): ?>
    <li><a href="<?= Ui::h(Ui::odkaz('zona', ['id' => $zn['id']])) ?>"><?= Ui::h($zn['site_name'] . ' / ' . $zn['name']) ?></a> <span class="slabe mala">(<?= Ui::h(Zony::rozmer($zn)) ?>)</span></li>
  <?php endforeach; ?></ul><?php endif; ?>
  <?php endif; ?>
</section>
</div>
