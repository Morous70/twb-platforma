<?php
declare(strict_types=1);
// Přehled: počty médií, upozornění (e-mail pro obnovu, zálohy, cron, nová verze), naposledy nahrané (0.8.8: mřížka 8 × 2 = 16 médií;
// na užší ploše 4 × 4 nebo 2 × 8 – počet sloupců je vždy dělitel 16, takže žádné médium nezmizí ani nezůstane neúplný řádek).
// 0.8.10: pod nimi seznam kategorií (počet, odkaz do Galerie) a rychlé nahrání (stejné nahrávání po částech jako v Galerii, assets/app.js #nahravani).
// Proměnné: $app, $ui.
use MediaGalerie\{App, Ui, Media};
use TWB\Zalohy\Zalohy;

$s = $app->media()->souhrn();
$velikost = $s['velikost'];
$posledni = $app->media()->seznam(['razeni' => 'nove'], Media::NA_PREHLEDU)['polozky'];
$kategorie = $app->kategorie()->seznam();
$bezKategorie = (int)$app->db->q('SELECT COUNT(*) FROM media WHERE category_id IS NULL AND archived_at IS NULL')->fetchColumn();
$n = $app->nastaveni();
$upozorneni = [];
if ($app->emailObnovy() === '') $upozorneni[] = ['Není nastavený e-mail pro obnovu hesla.', Ui::odkaz('nastaveni', ['karta' => 'zabezpeceni']) . '#zabezpeceni'];
if ((string)$n->dej('backup_password', '') === '') $upozorneni[] = ['Zálohy nejsou nastavené – zadejte heslo záloh.', Ui::odkaz('nastaveni', ['karta' => 'zalohy']) . '#zalohy'];
$cron = (int)$n->dej('cron_last_run', 0);
if ($cron < time() - 2 * 86400) $upozorneni[] = [$cron ? 'Cron neběží od ' . Ui::datum($cron) . '.' : 'Cron zatím nikdy neběžel (denní záloha, kontrola aktualizací).', Ui::odkaz('nastaveni', ['karta' => 'system']) . '#system'];
if ($dalsi = $app->aktualizace()->stav()['available'] ?? null) $upozorneni[] = ['Je k dispozici nová verze ' . $dalsi['version'] . '.', Ui::odkaz('nastaveni', ['karta' => 'aktualizace']) . '#aktualizace'];
?>
<div class="mrizka">
  <?= Ui::dlazdice('Obrázky', Ui::cislo($s['image']), 'obrazky') ?>
  <?= Ui::dlazdice('Videa', Ui::cislo($s['video']), 'videa') ?>
  <?= Ui::dlazdice('Obsazené místo', $velikost > 0 ? Ui::h(Zalohy::velikost($velikost)) : '0 B', 'velikost') ?>
  <?= Ui::dlazdice('V archivu', Ui::cislo($s['archiv']), 'galerie') ?>
</div>
<?php foreach ($upozorneni as [$text, $kam]): ?>
  <div class="hlaska hlaska-varovani"><?= Ui::h($text) ?> <a href="<?= Ui::h($kam) ?>">Opravit</a></div>
<?php endforeach; ?>
<?php if ($posledni): ?>
<h2>Naposledy nahrané</h2>
<div class="posledni-obal"><div class="galerie galerie-mala posledni-mrizka" data-okno-skupina>
  <?php foreach ($posledni as $m): ?>
  <figure<?= Ui::okno($m, $app->media()->verejnaAdresa($m)) ?>><a class="nahled-medium" href="<?= Ui::h(Ui::odkaz('medium', ['id' => $m['id']])) ?>" data-otevrit-okno title="<?= Ui::h($m['original_name']) ?>">
    <?php if ($m['kind'] === 'image'): ?><img src="media.php?id=<?= (int)$m['id'] ?>&amp;nahled=1&amp;v=<?= Ui::h(substr((string)$m['md5'], 0, 8)) ?>" alt="" loading="lazy">
    <?php elseif ($m['kind'] === 'video'): ?><video src="media.php?id=<?= (int)$m['id'] ?>#t=0.5" preload="metadata" muted playsinline></video><span class="znacka">Video</span>
    <?php else: ?><span class="pdf-ikona">PDF</span><?php endif; ?></a>
    <figcaption><strong><?= Ui::h($m['original_name']) ?></strong></figcaption></figure>
  <?php endforeach; ?>
</div></div>
<?php endif; ?>
<div class="prehled-dole">
<section class="karta prehled-kategorie" aria-labelledby="h-kategorie">
  <div class="karta-hlavicka"><h2 id="h-kategorie">Kategorie</h2><a href="<?= Ui::h(Ui::odkaz('kategorie')) ?>">Spravovat</a></div>
  <?php if (!$kategorie): ?>
  <p class="slabe" style="margin:0">Zatím žádná kategorie. <a href="<?= Ui::h(Ui::odkaz('kategorie')) ?>">Založit kategorii</a></p>
  <?php else: ?>
  <ul class="seznam-kategorii">
    <?php foreach ($kategorie as $k): ?>
    <li><a href="<?= Ui::h(Ui::odkaz('galerie', ['kategorie' => $k['id']])) ?>"><span class="nazev"><?= Ui::h($k['name']) ?></span>
      <?php if (!empty($k['web_public'])): ?><span class="stitek stitek-kategorie" title="Zobrazená na veřejném webu">web</span><?php endif; ?>
      <span class="pocet"><?= Ui::cislo($k['pocet']) ?></span></a></li>
    <?php endforeach; ?>
    <?php if ($bezKategorie): ?><li><a href="<?= Ui::h(Ui::odkaz('galerie', ['kategorie' => 0])) ?>"><span class="nazev slabe">Bez kategorie</span><span class="pocet"><?= Ui::cislo($bezKategorie) ?></span></a></li><?php endif; ?>
  </ul>
  <?php endif; ?>
</section>
<section class="karta nahravani prehled-nahrani" id="nahravani" aria-labelledby="h-nahrani" data-csrf="<?= Ui::h($app->relace()->csrfToken()) ?>" data-cast="<?= App::CAST_NAHRANI ?>" data-max="<?= App::MAX_NAHRANI ?>">
  <div class="karta-hlavicka"><h2 id="h-nahrani">Rychlé nahrání</h2><a href="<?= Ui::h(Ui::odkaz('galerie')) ?>">Do Galerie</a></div>
  <div class="nahravani-plocha" data-plocha>
    <p><strong>Přetáhněte sem soubory</strong> nebo <label class="odkaz-tlacitko">vyberte z počítače<input type="file" multiple hidden data-vyber
      accept="<?= Ui::h(implode(',', array_map(fn($p) => '.' . $p, [...array_keys(TWB\Galerie\Galerie::POVOLENE), 'zip']))) ?>"></label></p>
    <p class="slabe mala">Obrázky, videa, PDF a ZIP s obrázky, nejvýš <?= (int)(App::MAX_NAHRANI / 1048576) ?> MB na soubor.</p>
  </div>
  <div class="radek-nahrani">
    <?= Ui::vyber('Do kategorie', 'nahrat_kategorie', ['' => '— bez kategorie —'] + $app->kategorie()->volby(), '') ?>
    <?= Ui::pole('Tagy', 'nahrat_tagy', '', ['placeholder' => 'Napište tag a stiskněte Enter', 'list' => 'seznam-tagu', 'data-stitky' => '']) ?>
  </div>
  <ul class="nahravani-seznam" data-seznam></ul>
</section>
</div>
<datalist id="seznam-tagu"><?php foreach ($app->galerie()->tagy()->vsechny() as $t): ?><option value="<?= Ui::h($t['name']) ?>"><?php endforeach; ?></datalist>
<div class="karta">
  <h2 style="margin-top:0">Začínáme</h2>
  <p>Média nahrajete na stránce <a href="<?= Ui::h(Ui::odkaz('galerie')) ?>">Galerie</a> a roztřídíte do <a href="<?= Ui::h(Ui::odkaz('kategorie')) ?>">kategorií</a>. Každý soubor má veřejný odkaz pro vložení na web. Přihlášení přes Google a Passkey nastavíte v <a href="<?= Ui::h(Ui::odkaz('nastaveni')) ?>">Nastavení</a>.</p>
</div>
