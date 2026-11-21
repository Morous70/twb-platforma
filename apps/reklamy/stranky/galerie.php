<?php
declare(strict_types=1);
// Galerie obrázků pro bannery. Proměnné: $app, $ui, $post.
use Reklamy\{Ui, Chyba};

$galerie = $app->galerie();
if ($post) {
    $akce = (string)($_POST['akce'] ?? '');
    $hlasky = [];
    if ($ui->zpracuj(function () use ($galerie, $akce, $app, &$hlasky) {
        if ($akce === 'nahrat') {
            $f = $_FILES['soubory'] ?? null;
            if (!is_array($f) || !is_array($f['name'] ?? null)) throw new Chyba('Vyberte soubory.');
            $tmp = $app->data . '/tmp';
            if (!is_dir($tmp)) @mkdir($tmp, 0775, true);
            $chyby = [];
            foreach ($f['name'] as $i => $nazev) {
                if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                if ($f['error'][$i] !== UPLOAD_ERR_OK) { $chyby[] = "$nazev: soubor se nepodařilo nahrát (je moc velký?)."; continue; }
                $cil = $tmp . '/upload-' . bin2hex(random_bytes(8));
                if (!move_uploaded_file($f['tmp_name'][$i], $cil)) { $chyby[] = "$nazev: soubor se nepodařilo přesunout."; continue; }
                try {
                    $galerie->uloz($cil, (string)$nazev);
                    $hlasky[] = (string)$nazev;
                } catch (RuntimeException $e) { $chyby[] = $e->getMessage(); }
            }
            if ($chyby) throw new Chyba(implode(' ', $chyby) . ($hlasky ? ' Ostatní soubory se nahrály.' : ''));
            if (!$hlasky) throw new Chyba('Vyberte soubory.');
        } elseif ($akce === 'smazat') {
            if (!$galerie->smaz([(int)($_POST['id'] ?? 0)])) throw new Chyba('Obrázek nenalezen.');
        } elseif ($akce === 'prejmenovat') {
            $galerie->prejmenuj((int)($_POST['id'] ?? 0), (string)($_POST['nazev'] ?? ''));
        } else throw new Chyba('Neznámá akce.');
    })) {
        $ui->zprava('ok', $akce === 'nahrat' ? 'Nahráno: ' . implode(', ', $hlasky) : 'Uloženo.');
        $ui->presmeruj(Ui::odkaz('galerie'));
    }
}
$seznam = array_values(array_filter($galerie->vypis(), fn($m) => $m['druh'] === 'image'));
$pouziti = [];
foreach ($app->db->q('SELECT media_id, COUNT(*) AS n FROM banners WHERE media_id IS NOT NULL GROUP BY media_id')->fetchAll() as $r) $pouziti[(int)$r['media_id']] = (int)$r['n'];
$max = ini_get('upload_max_filesize');
?>
<form method="post" enctype="multipart/form-data" class="karta tlacitka">
  <?= $ui->csrf() ?>
  <input type="hidden" name="akce" value="nahrat">
  <input type="file" name="soubory[]" multiple accept="image/jpeg,image/png,image/gif,image/webp" required style="flex:1;min-width:220px">
  <button type="submit" class="hlavni">Nahrát</button>
  <span class="slabe mala">JPG, PNG, GIF (i animovaný), WebP. Obrázky se nezmenšují – banner má přesně rozměr obrázku. Největší soubor: <?= Ui::h($max) ?>.</span>
</form>
<?php if (!$seznam): ?>
  <div class="prazdne">Galerie je prázdná.</div>
<?php else: ?>
<div class="galerie">
  <?php foreach ($seznam as $m): $n = $pouziti[$m['id']] ?? 0; ?>
  <figure>
    <a href="media.php?id=<?= $m['id'] ?>" target="_blank"><img src="media.php?id=<?= $m['id'] ?>" alt="" loading="lazy"></a>
    <figcaption>
      <strong><?= Ui::h($m['nazev']) ?></strong>
      <span class="slabe"><?= (int)$m['sirka'] ?>×<?= (int)$m['vyska'] ?> · <?= Ui::h(TWB\Zalohy\Zalohy::velikost((int)$m['velikost'])) ?><?= $n ? " · v bannerech: $n" : '' ?></span>
      <span><?= $n ? '' : $ui->tlacitko('Smazat', 'smazat', ['id' => $m['id']], 'maly nebezpecne', 'Smazat obrázek „' . $m['nazev'] . '“?') ?></span>
    </figcaption>
  </figure>
  <?php endforeach; ?>
</div>
<?php endif;
