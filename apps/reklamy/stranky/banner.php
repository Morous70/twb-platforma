<?php
declare(strict_types=1);
// Nový banner nebo úprava banneru. Proměnné: $app, $ui, $post.
use Reklamy\{Ui, Bannery, Zony, Chyba, Kampane, Cileni};

$bannery = $app->bannery();
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$b = $id !== null ? $bannery->radek($id) : null;
// kampaň, ze které uživatel přišel (?kampan=), jinak první kampaň banneru; banner může být ve více kampaních
$zKampane = (int)($_GET['kampan'] ?? 0);
if ($b && !in_array($zKampane, array_column($b['kampane'], 'id'), true)) $zKampane = (int)($b['campaign_id'] ?? 0);
$kampan = $zKampane > 0 ? $app->kampane()->radek($zKampane) : null;
if (!$b && !$kampan) throw new Chyba('Nový banner se zakládá v kampani.');
$zpet = $kampan ? Ui::odkaz('kampan', ['id' => $kampan['id']]) : Ui::odkaz('bannery');
$vsechnyKampane = $app->kampane()->seznam();
if ($b) foreach ($b['kampane'] as $bk) if (!in_array($bk['id'], array_map('intval', array_column($vsechnyKampane, 'id')), true)) $vsechnyKampane[] = $app->kampane()->radek($bk['id']);
$vybraneKampane = $post && isset($_POST['kampane']) ? array_map('intval', (array)$_POST['kampane']) : ($b ? array_column($b['kampane'], 'id') : [(int)$kampan['id']]);
$typ = $b['type'] ?? (string)($_GET['typ'] ?? 'image');
if (!isset(Bannery::TYPY[$typ])) throw new Chyba('Neznámý druh banneru.');
$titulek = $b ? $b['name'] : 'Nový banner – ' . mb_strtolower(Bannery::TYPY[$typ]);

if ($post) {
    $akce = (string)($_POST['akce'] ?? 'ulozit');
    if ($akce === 'smazat' && $b) {
        if ($ui->zpracuj(fn() => $bannery->smaz((int)$id))) {
            $ui->zprava('ok', 'Banner smazán.');
            $ui->presmeruj($zpet);
        }
    } elseif ($ui->zpracuj(function () use ($bannery, &$id, $kampan, $typ, $vybraneKampane) {
        $vybrane = array_values(array_filter($vybraneKampane, fn($x) => $x > 0));
        if (!$vybrane) throw new Chyba('Vyberte aspoň jednu kampaň. Pokud banner už nechcete, smažte ho.');
        $v = ['type' => $typ] + $_POST;
        $v['kampane'] = $vybrane;
        if ($kampan) $v['campaign_id'] = in_array((int)$kampan['id'], $vybrane, true) ? (int)$kampan['id'] : $vybrane[0];
        unset($v['design']);
        $f = $_FILES['balicek'] ?? null;
        if ($typ === 'html5' && is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ($f['error'] !== UPLOAD_ERR_OK) throw new Chyba($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE
                ? 'Balíček je větší, než server dovolí nahrát.' : 'Balíček se nepodařilo nahrát, zkuste to znovu.');
            $v['balicek'] = ['tmp' => $f['tmp_name'], 'name' => $f['name']];
        }
        $id = $bannery->uloz($v, $id);
    })) {
        $ui->zprava('ok', 'Banner uložen.');
        $ui->presmeruj(Ui::odkaz('banner', ['id' => $id, 'kampan' => $kampan && (int)$kampan['id'] !== (int)$bannery->radek((int)$id)['campaign_id'] && in_array((int)$kampan['id'], $vybraneKampane, true) ? $kampan['id'] : null]));
    }
}
$z = $b ?? ['name' => '', 'status' => 'active', 'width' => null, 'height' => null, 'weight' => 1, 'media_id' => null, 'image_url' => '', 'html' => '',
    'target_url' => '', 'target' => '_blank', 'alt' => '', 'note' => '', 'devices' => null, 'media2x_id' => null, 'image2x_url' => ''];
$vybranaZarizeni = $post && ($_POST['akce'] ?? '') === 'ulozit' ? array_map('strval', (array)($_POST['zarizeni'] ?? [])) : Bannery::zarizeni($z);
if (!$vybranaZarizeni) $vybranaZarizeni = array_keys(Cileni::ZARIZENI);
$rozmery = ['' => 'Běžné rozměry…'];
foreach (Bannery::ROZMERY as $r => $n) $rozmery[$r] = str_replace('x', '×', $r) . ' – ' . $n;
$promenneNapoveda = 'Proměnné: <code>{datum}</code>, <code>{den}</code>, <code>{svatek}</code>, <code>{odpocet:24. 12. 2026}</code>, <code>{Skupina:parametr}</code> z tabulky dat a vlastní proměnné '
    . '(<a href="' . Ui::h(Ui::odkaz('promenne')) . '">Proměnné</a>) se dosadí při každém zobrazení.';
if ($typ === 'slide' && !$b):
    // nový slide: nejdřív rozměr plátna, pak editor ?>
<p class="slabe">Kampaň: <a href="<?= Ui::h(Ui::odkaz('kampan', ['id' => $kampan['id']])) ?>"><?= Ui::h($kampan['name']) ?></a> · <?= Ui::h(Bannery::TYPY[$typ]) ?></p>
<form method="get" action="slide.php" class="formular karta">
  <input type="hidden" name="kampan" value="<?= (int)$kampan['id'] ?>">
  <p>Slide se navrhne v editoru (texty s efekty, obrázky z galerie, obdélníky, proměnné) a uloží jako obrázkový banner. Zvolte rozměr plátna – v editoru ho lze změnit.</p>
  <div class="radek">
    <?= Ui::pole('Šířka (px)', 'w', 300, ['type' => 'number', 'min' => 16, 'max' => Bannery::MAX_ROZMER, 'required' => true]) ?>
    <?= Ui::pole('Výška (px)', 'h', 250, ['type' => 'number', 'min' => 16, 'max' => Bannery::MAX_ROZMER, 'required' => true]) ?>
    <label class="pole"><span>&nbsp;</span><select data-rozmery data-sirka="f-w" data-vyska="f-h" aria-label="Běžné rozměry"><?php foreach ($rozmery as $k => $t): ?><option value="<?= Ui::h($k) ?>"><?= Ui::h($t) ?></option><?php endforeach; ?></select></label>
  </div>
  <div class="tlacitka">
    <button type="submit" class="hlavni">Otevřít editor</button>
    <a href="<?= Ui::h(Ui::odkaz('kampan', ['id' => $kampan['id']])) ?>">Zpět na kampaň</a>
  </div>
</form>
<?php return; endif;
$balicek = $typ === 'html5' && $b ? (json_decode((string)$b['content'], true) ?: null) : null;
?>
<p class="slabe"><?= $b ? 'Kampaně: ' . Ui::kampaneBanneru($b) : 'Kampaň: <a href="' . Ui::h($zpet) . '">' . Ui::h($kampan['name']) . '</a>' ?> · <?= Ui::h(Bannery::TYPY[$typ]) ?></p>
<div class="dva-sloupce">
<section>
<form method="post" class="formular karta"<?= $typ === 'html5' ? ' enctype="multipart/form-data"' : '' ?>>
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
    <label class="pole" for="f-media2x_id"><span>Obrázek pro displeje s vyšší hustotou (2×, nepovinné)</span>
      <select id="f-media2x_id" name="media2x_id">
        <option value="">Bez obrázku 2×</option>
        <?php $vybrany2 = Ui::hodnota('media2x_id', $z['media2x_id']);
        foreach ($obrazky as $m): ?>
          <option value="<?= $m['id'] ?>"<?= (string)$m['id'] === $vybrany2 ? ' selected' : '' ?>><?= Ui::h($m['nazev']) ?> (<?= (int)$m['sirka'] ?>×<?= (int)$m['vyska'] ?>)</option>
        <?php endforeach; ?>
      </select><small>Stejný banner v dvojnásobném rozměru (např. 600×500 k banneru 300×250). Mobily a displeje Retina si ho vyberou samy a banner je na nich ostrý; rozměr na stránce zůstává stejný.</small></label>
  <?php elseif ($typ === 'external'): ?>
    <?= Ui::pole('Adresa obrázku', 'image_url', $z['image_url'], ['type' => 'url', 'required' => true, 'placeholder' => 'https://…']) ?>
    <?= Ui::pole('Adresa obrázku 2× (nepovinné)', 'image2x_url', $z['image2x_url'], ['type' => 'url', 'placeholder' => 'https://…'], 'Obrázek v dvojnásobném rozměru pro ostré zobrazení na mobilech a displejích Retina.') ?>
  <?php elseif ($typ === 'html'): ?>
    <?= Ui::oblast('HTML kód', 'html', $z['html'], ['class' => 'kod', 'rows' => 14, 'spellcheck' => 'false', 'required' => true],
        'Kód se zobrazí v izolovaném rámci (iframe) o rozměru banneru. Odkazy: <code>{clickurl}</code> (proklik na cílovou adresu) nebo <code>{clickurl}https://…</code> (proklik na jinou adresu stejné domény nebo uvedenou v kódu). Dále <code>{random}</code>, <code>{timestamp}</code>, <code>{bannerid}</code>, <code>{zoneid}</code>, <code>{width}</code>, <code>{height}</code>, <code>{targeturl}</code>. '
        . $promenneNapoveda . ' Písmo z editoru slidů (např. <code>font-family: \'Montserrat\'</code>) se do banneru vloží samo.') ?>
  <?php elseif ($typ === 'html5'): ?>
    <?php if ($balicek): ?>
      <p class="mala">Balíček: <b><?= Ui::h($balicek['nazev'] ?? '') ?></b> · <?= (int)($balicek['soubory'] ?? 0) ?> souborů · <?= Ui::h(number_format((int)($balicek['bajty'] ?? 0) / 1024, 0, ',', ' ')) ?> kB · hlavní soubor <code><?= Ui::h($balicek['index'] ?? '') ?></code></p>
    <?php endif; ?>
    <label class="pole" for="f-balicek"><span><?= $balicek ? 'Nahradit balíček (ZIP)' : 'Balíček HTML5 (ZIP)' ?></span>
      <input type="file" id="f-balicek" name="balicek" accept=".zip,application/zip"<?= $balicek ? '' : ' required' ?>>
      <small>ZIP s <code>index.html</code> (Google Web Designer, Adobe Animate, Sklik, IAB). Nejvýš 10 MB. Proklik: banner použije proměnnou <code>clickTag</code>
        (také <code>clickTAG</code>), kterou mu server doplní; vede na cílovou adresu níže a počítá se. Rozměr se doplní z <code>&lt;meta name="ad.size"&gt;</code>.</small></label>
  <?php elseif ($typ === 'slide'): ?>
    <p><a class="tlacitko" href="slide.php?id=<?= (int)$b['id'] ?>">Upravit slide v editoru</a> <span class="slabe mala"><?= (int)$b['width'] ?>×<?= (int)$b['height'] ?> px – rozměr se mění v editoru.</span></p>
  <?php endif; ?>
  <?php if ($typ !== 'slide'): ?>
  <div class="radek">
    <?= Ui::pole('Šířka (px)', 'width', $z['width'], ['type' => 'number', 'min' => 1, 'max' => Bannery::MAX_ROZMER, 'required' => $typ !== 'image' && $typ !== 'html5']) ?>
    <?= Ui::pole('Výška (px)', 'height', $z['height'], ['type' => 'number', 'min' => 1, 'max' => Bannery::MAX_ROZMER, 'required' => $typ !== 'image' && $typ !== 'html5']) ?>
    <label class="pole"><span>&nbsp;</span><select data-rozmery aria-label="Běžné rozměry"><?php foreach ($rozmery as $k => $t): ?><option value="<?= Ui::h($k) ?>"><?= Ui::h($t) ?></option><?php endforeach; ?></select></label>
  </div>
  <?php endif; ?>
  <?= Ui::pole('Cílová adresa (proklik)', 'target_url', $z['target_url'], ['type' => 'url', 'placeholder' => 'https://…']) ?>
  <div class="radek">
    <?= Ui::vyber('Otevřít', 'target', Bannery::CILE, $z['target']) ?>
    <?= Ui::vyber('Stav', 'status', Bannery::STAVY, $z['status']) ?>
    <?= Ui::pole('Váha', 'weight', $z['weight'], ['type' => 'number', 'min' => 1, 'max' => 1000], 'Poměr střídání v kampani.') ?>
  </div>
  <?php if (in_array($typ, Bannery::OBRAZKOVE, true)): ?>
    <?= Ui::pole('Popis obrázku (alt)', 'alt', $z['alt'], ['maxlength' => 255]) ?>
  <?php endif; ?>
  <fieldset><legend>Zobrazovat na zařízeních</legend>
    <input type="hidden" name="zarizeni[]" value="">
    <div class="seznam-voleb">
      <?php foreach (Cileni::ZARIZENI as $zk => $zn): ?>
      <label><input type="checkbox" name="zarizeni[]" value="<?= $zk ?>"<?= in_array($zk, $vybranaZarizeni, true) ? ' checked' : '' ?>> <?= Ui::h($zn) ?></label>
      <?php endforeach; ?>
    </div>
    <p class="slabe mala" style="margin-bottom:0">Např. mobilní verze banneru s jiným obsahem nebo odkazem. Zařízení se pozná podle šířky okna (mobil pod <?= Reklamy\Kontext::TABLET_OD ?> px, tablet pod <?= Reklamy\Kontext::POCITAC_OD ?> px),
      u rámu a obrázku podle prohlížeče. Rozměr podle místa na stránce řeší responzivní zóna.</p>
  </fieldset>
  <fieldset><legend>Kampaně</legend>
    <p class="slabe mala" style="margin-top:0">Stejný banner může běžet ve více kampaních; v každé se řídí jejím termínem, prioritou, limity a cílením a statistiky se počítají zvlášť.</p>
    <input type="hidden" name="kampane[]" value="0">
    <div class="seznam-voleb">
      <?php foreach ($vsechnyKampane as $vk): ?>
      <label><input type="checkbox" name="kampane[]" value="<?= (int)$vk['id'] ?>"<?= in_array((int)$vk['id'], $vybraneKampane, true) ? ' checked' : '' ?>>
        <?= Ui::h($vk['name']) ?> <?= Ui::stav($vk['status'], Kampane::STAVY[$vk['status']]) ?></label>
      <?php endforeach; ?>
    </div>
  </fieldset>
  <?= Ui::oblast('Poznámka', 'note', $z['note'], ['rows' => 2]) ?>
  <div class="tlacitka">
    <button type="submit" class="hlavni"><?= $b ? 'Uložit' : 'Vytvořit banner' ?></button>
    <a href="<?= Ui::h($zpet) ?>"><?= $kampan ? 'Zpět na kampaň' : 'Zpět na bannery' ?></a>
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
    <iframe sandbox="allow-scripts allow-popups" srcdoc="<?= Ui::h($app->vykresleni()->dokumentHtml($b, 0)) ?>" width="<?= (int)$b['width'] ?>" height="<?= (int)$b['height'] ?>" title="Náhled banneru"></iframe>
  <?php elseif ($typ === 'html5' && $b): ?>
    <iframe sandbox="allow-scripts allow-popups" src="<?= Ui::h($app->vykresleni()->dokument($b, 0)) ?>" width="<?= (int)$b['width'] ?>" height="<?= (int)$b['height'] ?>" title="Náhled banneru"></iframe>
  <?php elseif ($typ === 'slide' && $b): ?>
    <img src="media.php?slide=<?= (int)$b['id'] ?>&amp;v=<?= Ui::h($app->slideBannery()->klic($b)) ?>" alt="" width="<?= (int)$b['width'] ?>" height="<?= (int)$b['height'] ?>">
    <p class="slabe mala">Texty s proměnnými se dosazují podle aktuálního času; obrázek se vykreslí znovu, když se změní.</p>
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
