<?php
declare(strict_types=1);
// Statistiky: období, seskupení (dny, týdny, měsíce, hodiny, kampaně, bannery, zóny, weby), filtr, graf, tabulka a export CSV.
// Proměnné: $app, $ui, $post.
use Reklamy\{Ui, Statistiky, Konverze};

$st = $app->statistiky();
$dnes = new DateTimeImmutable('today');
$OBDOBI = ['7' => 'Posledních 7 dní', '30' => 'Posledních 30 dní', '90' => 'Posledních 90 dní', 'mesic' => 'Tento měsíc', 'minuly' => 'Minulý měsíc',
    'rok' => 'Letos', 'vlastni' => 'Vlastní období'];
$obdobi = isset($OBDOBI[$_GET['obdobi'] ?? '']) ? (string)$_GET['obdobi'] : '30';
$datum = function (string $k) {
    $v = (string)($_GET[$k] ?? '');
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v ? $d : null;
};
[$od, $do] = match ($obdobi) {
    '7', '30', '90' => [$dnes->modify('-' . ((int)$obdobi - 1) . ' days'), $dnes],
    'mesic' => [$dnes->modify('first day of this month'), $dnes],
    'minuly' => [$dnes->modify('first day of last month'), $dnes->modify('last day of last month')],
    'rok' => [$dnes->setDate((int)$dnes->format('Y'), 1, 1), $dnes],
    'vlastni' => [$datum('od') ?? $dnes->modify('-29 days'), $datum('do') ?? $dnes],
};
if ($od > $do) [$od, $do] = [$do, $od];
if ($od < $do->modify('-10 years')) $od = $do->modify('-10 years');
$podle = isset(Statistiky::PODLE[$_GET['podle'] ?? '']) ? (string)$_GET['podle'] : ($od->diff($do)->days > 120 ? 'mesic' : 'den');
$graf = isset(Statistiky::UKAZATELE[$_GET['graf'] ?? '']) ? (string)$_GET['graf'] : 'impressions';
$filtr = [];
foreach (['kampan', 'banner', 'zona', 'web'] as $k) if (isset($_GET[$k]) && ctype_digit((string)$_GET[$k]) && (int)$_GET[$k] > 0) $filtr[$k] = (int)$_GET[$k];
$rows = $st->prehled($od->format('Y-m-d'), $do->format('Y-m-d'), $podle, $filtr);
$soucet = Statistiky::soucet($rows);

if (isset($_GET['csv'])) {
    ob_end_clean();
    $soubor = 'twb-reklamy-statistiky-' . $od->format('Ymd') . '-' . $do->format('Ymd') . "-$podle.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $soubor . '"');
    header('Cache-Control: no-store');
    echo Statistiky::csv($rows, $podle);
    exit;
}

$parametry = ['obdobi' => $obdobi, 'od' => $obdobi === 'vlastni' ? $od->format('Y-m-d') : null, 'do' => $obdobi === 'vlastni' ? $do->format('Y-m-d') : null,
    'podle' => $podle, 'graf' => $graf] + $filtr;
$cas = in_array($podle, ['den', 'tyden', 'mesic', 'hodina'], true);
$fmt = fn(string $u, int $v) => $u === 'revenue' ? Konverze::kc($v) : Ui::cislo($v);
$procento = fn(int $a, int $b) => $b > 0 ? number_format($a / $b * 100, 2, ',', ' ') . ' %' : '–';
$volby = fn(string $sql) => ['' => 'vše'] + array_column($app->db->q($sql)->fetchAll(), 'name', 'id');

/** Sloupcový graf v SVG: časová řada svisle, ostatní vodorovně (nejvýš 15 položek). Popisky a hodnoty jsou i v tabulce pod grafem. */
$svg = function (array $rows, string $u) use ($cas, $fmt): string {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $max = max(1, ...array_map(fn($r) => (int)$r[$u], $rows ?: [['impressions' => 0, 'clicks' => 0, 'conversions' => 0, 'revenue' => 0]]));
    // zaokrouhlené maximum osy (1, 2, 5 × 10^n)
    $rad = 10 ** floor(log10($max));
    foreach ([1, 2, 5, 10] as $n) if ($n * $rad >= $max) { $osa = $n * $rad; break; }
    $osa = (int)max(1, $osa ?? $max);
    $cara = fn(float $x1, float $y1, float $x2, float $y2) => '<line x1="' . $x1 . '" y1="' . $y1 . '" x2="' . $x2 . '" y2="' . $y2 . '" class="g-mrizka"/>';
    $sloupec = function (float $x, float $y, float $w, float $hh, bool $svisle) {
        // zaoblený jen datový konec (4 px), základna rovná
        $r = min(4, $w / 2, $hh);
        if ($hh <= 0) return '';
        return $svisle
            ? '<path d="M' . $x . ',' . ($y + $hh) . 'V' . ($y + $r) . 'Q' . $x . ',' . $y . ' ' . ($x + $r) . ',' . $y . 'H' . ($x + $w - $r) . 'Q' . ($x + $w) . ',' . $y . ' ' . ($x + $w) . ',' . ($y + $r) . 'V' . ($y + $hh) . 'Z"/>'
            : '<path d="M' . $x . ',' . $y . 'H' . ($x + $hh - $r) . 'Q' . ($x + $hh) . ',' . $y . ' ' . ($x + $hh) . ',' . ($y + $r) . 'V' . ($y + $w - $r) . 'Q' . ($x + $hh) . ',' . ($y + $w) . ' ' . ($x + $hh - $r) . ',' . ($y + $w) . 'H' . $x . 'Z"/>';
    };
    if ($cas) {
        $n = max(1, count($rows));
        $W = 900; $H = 240; $L = 56; $T = 10; $B = 28; $sirka = ($W - $L - 8) / $n;
        $o = '<svg class="graf" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Graf: ' . $h(Statistiky::UKAZATELE[$u]) . '">';
        for ($i = 0; $i <= 4; $i++) {
            $y = $T + ($H - $T - $B) * (1 - $i / 4);
            $o .= $cara($L, $y, $W - 8, $y) . '<text x="' . ($L - 6) . '" y="' . ($y + 4) . '" class="g-osa" text-anchor="end">' . $h($fmt($u, (int)round($osa * $i / 4))) . '</text>';
        }
        $krok = (int)ceil($n / 12);
        foreach (array_values($rows) as $i => $r) {
            $v = (int)$r[$u];
            $vy = ($H - $T - $B) * $v / $osa;
            $x = $L + $i * $sirka;
            $mezera = min(2, $sirka * .2);
            $o .= '<g class="g-bod"><rect x="' . $x . '" y="' . $T . '" width="' . $sirka . '" height="' . ($H - $T - $B) . '" class="g-cil"/>'
                . $sloupec($x + $mezera / 2, $H - $B - $vy, max(1, $sirka - $mezera), $vy, true)
                . '<title>' . $h($r['popis'] . ': ' . $fmt($u, $v)) . '</title></g>';
            if ($i % $krok === 0) {
                $popis = preg_replace('/^(po|út|st|čt|pá|so|ne) /u', '', $r['popis']);
                $popis = preg_replace('/ \d{4}$/', '', (string)$popis);
                $o .= '<text x="' . ($x + $sirka / 2) . '" y="' . ($H - 8) . '" class="g-osa" text-anchor="middle">' . $h($popis) . '</text>';
            }
        }
        return $o . '<line x1="' . $L . '" y1="' . ($H - $B) . '" x2="' . ($W - 8) . '" y2="' . ($H - $B) . '" class="g-zaklad"/></svg>';
    }
    $rows = array_slice($rows, 0, 15);
    $n = max(1, count($rows));
    $W = 900; $L = 230; $R = 110; $radek = 26; $H = $n * $radek + 8;
    $o = '<svg class="graf" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Graf: ' . $h(Statistiky::UKAZATELE[$u]) . '">';
    foreach ($rows as $i => $r) {
        $v = (int)$r[$u];
        $y = 4 + $i * $radek;
        $d = ($W - $L - $R) * $v / $max;
        $o .= '<g class="g-bod"><rect x="0" y="' . $y . '" width="' . $W . '" height="' . $radek . '" class="g-cil"/>'
            . '<text x="' . ($L - 8) . '" y="' . ($y + 17) . '" class="g-popis" text-anchor="end">' . $h(mb_strimwidth($r['popis'], 0, 34, '…')) . '</text>'
            . $sloupec($L, $y + 5, $radek - 10, $d, false)
            . '<text x="' . ($L + $d + 6) . '" y="' . ($y + 17) . '" class="g-osa">' . $h($fmt($u, $v)) . '</text>'
            . '<title>' . $h($r['popis'] . ': ' . $fmt($u, $v)) . '</title></g>';
    }
    return $o . '<line x1="' . $L . '" y1="0" x2="' . $L . '" y2="' . $H . '" class="g-zaklad"/></svg>';
};
?>
<form method="get" class="formular karta filtr">
  <input type="hidden" name="p" value="statistiky"><input type="hidden" name="graf" value="<?= Ui::h($graf) ?>">
  <div class="radek">
    <?= Ui::vyber('Období', 'obdobi', $OBDOBI, $obdobi) ?>
    <?= Ui::pole('Od', 'od', $od->format('Y-m-d'), ['type' => 'date']) ?>
    <?= Ui::pole('Do', 'do', $do->format('Y-m-d'), ['type' => 'date']) ?>
    <?= Ui::vyber('Podle', 'podle', Statistiky::PODLE, $podle) ?>
  </div>
  <div class="radek">
    <?= Ui::vyber('Kampaň', 'kampan', $volby('SELECT id, name FROM campaigns ORDER BY name'), $filtr['kampan'] ?? '') ?>
    <?= Ui::vyber('Banner', 'banner', $volby('SELECT id, name FROM banners ORDER BY name'), $filtr['banner'] ?? '') ?>
    <?= Ui::vyber('Web', 'web', $volby('SELECT id, name FROM sites ORDER BY name'), $filtr['web'] ?? '') ?>
    <?= Ui::vyber('Zóna', 'zona', $volby('SELECT id, name FROM zones ORDER BY name'), $filtr['zona'] ?? '') ?>
  </div>
  <div class="tlacitka"><button type="submit" class="hlavni">Zobrazit</button>
    <a class="tlacitko" href="<?= Ui::h(Ui::odkaz('statistiky', $parametry + ['csv' => 1])) ?>">Stáhnout CSV</a>
    <span class="slabe mala"><?= Ui::h(Statistiky::popisObdobi($od->format('Y-m-d'), 'den')) ?> – <?= Ui::h(Statistiky::popisObdobi($do->format('Y-m-d'), 'den')) ?></span></div>
</form>

<div class="mrizka" style="grid-template-columns:repeat(auto-fill,minmax(10rem,1fr));margin:1rem 0">
  <div class="dlazdice"><div class="cislo"><?= Ui::cislo($soucet['impressions']) ?></div><div class="popis">Zobrazení</div></div>
  <div class="dlazdice"><div class="cislo"><?= Ui::cislo($soucet['clicks']) ?></div><div class="popis">Prokliky</div></div>
  <div class="dlazdice"><div class="cislo"><?= Statistiky::ctr($soucet['impressions'], $soucet['clicks']) ?></div><div class="popis">CTR</div></div>
  <div class="dlazdice"><div class="cislo"><?= Ui::cislo($soucet['conversions']) ?></div><div class="popis">Konverze (<?= $procento($soucet['conversions'], $soucet['clicks']) ?> z prokliků)</div></div>
  <div class="dlazdice"><div class="cislo"><?= Ui::h(Konverze::kc($soucet['revenue'])) ?></div><div class="popis">Hodnota konverzí</div></div>
</div>

<section class="karta">
  <div class="tlacitka zalozky" role="tablist" aria-label="Ukazatel grafu">
    <?php foreach (Statistiky::UKAZATELE as $k => $t): ?>
      <a role="tab" aria-selected="<?= $k === $graf ? 'true' : 'false' ?>" class="tlacitko maly<?= $k === $graf ? ' aktivni' : '' ?>" href="<?= Ui::h(Ui::odkaz('statistiky', ['graf' => $k] + $parametry)) ?>"><?= Ui::h($t) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if (!$soucet['impressions'] && !$soucet['clicks'] && !$soucet['conversions']): ?>
    <div class="prazdne">V tomto období nejsou žádná data.</div>
  <?php else: ?>
    <?= $svg($rows, $graf) ?>
    <?php if (!$cas && count($rows) > 15): ?><p class="slabe mala">Graf ukazuje 15 položek s nejvíce zobrazeními, tabulka všechny.</p><?php endif; ?>
  <?php endif; ?>
</section>

<div class="tabulka-obal" style="margin-top:1rem"><table class="statistiky">
  <tr><th><?= Ui::h(Statistiky::PODLE[$podle]) ?></th><th class="cisla">Zobrazení</th><th class="cisla">Prokliky</th><th class="cisla">CTR</th><th class="cisla">Konverze</th><th class="cisla">Konv. poměr</th><th class="cisla">Hodnota</th></tr>
  <?php foreach ($rows as $r): if ($cas && $podle !== 'hodina' && !$r['impressions'] && !$r['clicks'] && !$r['conversions'] && count($rows) > 60) continue; ?>
  <tr><td><?php if ($r['odkaz']): ?><a href="<?= Ui::h(Ui::odkaz(...$r['odkaz'])) ?>"><?= Ui::h($r['popis']) ?></a>
      <a class="mala" href="<?= Ui::h(Ui::odkaz('statistiky', [$podle => (int)$r['klic'], 'podle' => 'den'] + $parametry)) ?>" title="Statistiky jen pro tuto položku">po dnech</a>
    <?php else: ?><?= Ui::h($r['popis']) ?><?php endif; ?></td>
    <td class="cisla"><?= Ui::cislo($r['impressions']) ?></td><td class="cisla"><?= Ui::cislo($r['clicks']) ?></td>
    <td class="cisla"><?= Statistiky::ctr($r['impressions'], $r['clicks']) ?></td><td class="cisla"><?= Ui::cislo($r['conversions']) ?></td>
    <td class="cisla"><?= $procento($r['conversions'], $r['clicks']) ?></td><td class="cisla"><?= Ui::h(Konverze::kc($r['revenue'])) ?></td></tr>
  <?php endforeach; ?>
  <tr class="soucet"><th>Celkem</th><th class="cisla"><?= Ui::cislo($soucet['impressions']) ?></th><th class="cisla"><?= Ui::cislo($soucet['clicks']) ?></th>
    <th class="cisla"><?= Statistiky::ctr($soucet['impressions'], $soucet['clicks']) ?></th><th class="cisla"><?= Ui::cislo($soucet['conversions']) ?></th>
    <th class="cisla"><?= $procento($soucet['conversions'], $soucet['clicks']) ?></th><th class="cisla"><?= Ui::h(Konverze::kc($soucet['revenue'])) ?></th></tr>
</table></div>
<?php if (!$cas && !$rows): ?><p class="slabe">V tomto období nejsou žádná data.</p><?php endif; ?>
