<?php
declare(strict_types=1);
// Nápověda: obsah, stránka (Markdown z help/ vykreslený modulem napoveda), hledání. Proměnné: $app, $ui, $post.
use MediaGalerie\Ui;
use TWB\Napoveda\Markdown;

$nap = $app->napoveda();
$toc = $nap->obsah();
$q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
$id = is_string($_GET['s'] ?? null) ? (string)$_GET['s'] : '';
$stranka = $q === '' ? $nap->stranka($id !== '' ? $id : ($nap->poradi()[0] ?? '')) : null;
if ($id !== '' && $q === '' && !$stranka) throw new MediaGalerie\Chyba('Stránka nápovědy nenalezena.');
$md = new Markdown(fn(string $s) => Ui::odkaz('napoveda', ['s' => $s]));
$odkaz = fn(string $s) => Ui::h(Ui::odkaz('napoveda', ['s' => $s]));
$titulek = 'Nápověda';
?>
<div class="napoveda">
<nav class="napoveda-obsah" aria-label="Obsah nápovědy">
  <form method="get" class="hledani" role="search">
    <input type="hidden" name="p" value="napoveda">
    <input type="search" name="q" value="<?= Ui::h($q) ?>" placeholder="Hledat v nápovědě" aria-label="Hledat v nápovědě">
  </form>
  <?php foreach ($toc['sections'] as $sekce): ?>
    <h3><?= Ui::h($sekce['title']) ?></h3>
    <?php foreach ($sekce['groups'] as $g): ?>
      <?php if ($g['title']): ?><div class="skupina"><?= Ui::h($g['title']) ?></div><?php endif; ?>
      <ul>
        <?php foreach ($g['pages'] as $pol): ?>
        <li><a href="<?= $odkaz($pol['id']) ?>"<?= $stranka && $stranka['id'] === $pol['id'] ? ' class="aktivni" aria-current="page"' : '' ?>><?= Ui::h($pol['title']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php endforeach; ?>
  <?php endforeach; ?>
</nav>
<article class="karta napoveda-text">
<?php if ($q !== ''): $vysledky = $nap->hledej($q); ?>
  <h2 style="margin-top:0">Hledání: <?= Ui::h($q) ?></h2>
  <?php if (!$vysledky): ?><p class="slabe">Nic nenalezeno. Zkuste jiné slovo nebo projděte obsah vlevo.</p><?php endif; ?>
  <?php foreach ($vysledky as $v): ?>
    <div class="vysledek"><a href="<?= $odkaz($v['id']) ?>"><b><?= Ui::h($v['title']) ?></b></a><div class="slabe mala"><?= Ui::h($v['snippet']) ?></div></div>
  <?php endforeach; ?>
<?php elseif ($stranka): ?>
  <?= $md->html($stranka['md']) ?>
  <?php if ($stranka['related']): ?>
    <div class="souvisi"><b>Související:</b>
      <?= implode(' · ', array_map(fn($r) => '<a href="' . $odkaz($r['id']) . '">' . Ui::h($r['title']) . '</a>', $stranka['related'])) ?></div>
  <?php endif; ?>
  <div class="tlacitka sousedni">
    <?php if ($stranka['prev']): ?><a class="tlacitko maly" href="<?= $odkaz($stranka['prev']['id']) ?>">← <?= Ui::h($stranka['prev']['title']) ?></a><?php endif; ?>
    <?php if ($stranka['next']): ?><a class="tlacitko maly" href="<?= $odkaz($stranka['next']['id']) ?>" style="margin-left:auto"><?= Ui::h($stranka['next']['title']) ?> →</a><?php endif; ?>
  </div>
<?php else: ?>
  <p class="slabe">Nápověda není k dispozici.</p>
<?php endif; ?>
</article>
</div>
