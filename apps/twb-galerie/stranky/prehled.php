<?php
declare(strict_types=1);
// Přehled: počty médií, upozornění (e-mail pro obnovu, zálohy, cron, nová verze), naposledy nahrané (0.8.8: mřížka 8 × 2 = 16 médií;
// na užší ploše 4 × 4 nebo 2 × 8 – počet sloupců je vždy dělitel 16, takže žádné médium nezmizí ani nezůstane neúplný řádek). Proměnné: $app, $ui.
use MediaGalerie\{Ui, Media};
use TWB\Zalohy\Zalohy;

$s = $app->media()->souhrn();
$velikost = $s['velikost'];
$posledni = $app->media()->seznam(['razeni' => 'nove'], Media::NA_PREHLEDU)['polozky'];
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
<div class="karta">
  <h2 style="margin-top:0">Začínáme</h2>
  <p>Média nahrajete na stránce <a href="<?= Ui::h(Ui::odkaz('galerie')) ?>">Galerie</a> a roztřídíte do <a href="<?= Ui::h(Ui::odkaz('kategorie')) ?>">kategorií</a>. Každý soubor má veřejný odkaz pro vložení na web. Přihlášení přes Google a Passkey nastavíte v <a href="<?= Ui::h(Ui::odkaz('nastaveni')) ?>">Nastavení</a>.</p>
</div>
