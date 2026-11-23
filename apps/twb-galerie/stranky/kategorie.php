<?php
declare(strict_types=1);
// Kategorie: složky médií. Nová, přejmenování, smazání (média zůstanou bez kategorie), zveřejnění na webu (0.8.0),
// pořadí přetažením myší (0.8.8; assets/app.js pošle akci „poradi“ a čeká JSON). Proměnné: $app, $ui, $post.
use MediaGalerie\{Ui, Chyba, Web};
use TWB\Zalohy\Zalohy;

$kategorie = $app->kategorie();
$web = $app->web();
$upravit = isset($_GET['upravit']) ? $kategorie->radek((int)$_GET['upravit']) : null;

if ($post && ($_POST['akce'] ?? '') === 'poradi') {
    // přetažení řádku: odpověď JSON pro assets/app.js, bez JS (Accept není JSON) návrat na stránku s hláškou
    $json = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    try {
        $kategorie->nastavPoradi(array_values(array_filter(explode(',', (string)($_POST['poradi'] ?? '')), 'is_numeric')));
        $vysledek = ['ok' => true];
    } catch (Chyba $e) {
        $vysledek = ['ok' => false, 'error' => $e->getMessage()];
    }
    if ($json) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($vysledek, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ui->zprava($vysledek['ok'] ? 'ok' : 'chyba', $vysledek['ok'] ? 'Pořadí kategorií uloženo.' : $vysledek['error']);
    $ui->presmeruj(Ui::odkaz('kategorie'));
}
if ($post) {
    $akce = (string)($_POST['akce'] ?? '');
    $zprava = 'Uloženo.';
    if ($ui->zpracuj(function () use ($akce, $app, $kategorie, $upravit, &$zprava) {
        switch ($akce) {
            case 'ulozit':
                $app->db->transakce(function () use ($app, $kategorie, $upravit) {
                    $id = $kategorie->uloz($_POST, $upravit ? (int)$upravit['id'] : null);
                    $app->web()->ulozKategorii($id, $_POST);
                });
                break;
            case 'smazat':
                $n = $kategorie->smaz((int)($_POST['id'] ?? 0));
                $zprava = 'Kategorie smazána' . ($n ? ", {$n} médií zůstalo v galerii bez kategorie." : '.');
                break;
            default:
                throw new Chyba('Neznámá akce.');
        }
    })) {
        $ui->zprava('ok', $zprava);
        $ui->presmeruj(Ui::odkaz('kategorie'));
    }
}
$seznam = $kategorie->seznam();
$bez = (int)$app->db->q('SELECT COUNT(*) FROM media WHERE category_id IS NULL AND archived_at IS NULL')->fetchColumn();
?>
<div class="dva-sloupce">
<section>
<?php if (!$seznam): ?>
  <div class="prazdne">Zatím žádná kategorie. Kategorie jsou složky, do kterých média roztřídíte.</div>
<?php else: ?>
  <?php if (count($seznam) > 1): ?><p class="slabe mala poradi-napoveda">Pořadí změníte přetažením řádku za úchyt <span aria-hidden="true">⠿</span> (nebo úchyt vyberte klávesou Tab a posuňte šipkami nahoru a dolů). Stejné pořadí platí ve výběrech kategorií, v API i na veřejném webu.
    <span class="poradi-stav" data-poradi-stav role="status" aria-live="polite"></span></p><?php endif; ?>
  <div class="tabulka-obal"><table class="tabulka-kategorie">
    <thead><tr><th class="sloupec-uchyt"><span class="skryte">Pořadí</span></th><th>Kategorie</th><th class="cisla">Médií</th><th class="cisla">Velikost</th><th>Web</th><th></th></tr></thead>
    <tbody data-poradi="<?= Ui::h($app->relace()->csrfToken()) ?>">
    <?php foreach ($seznam as $k): ?>
    <tr data-poradi-id="<?= (int)$k['id'] ?>"><td class="sloupec-uchyt"><button type="button" class="uchyt" data-uchyt title="Přetáhnout – změnit pořadí" aria-label="Přesunout kategorii <?= Ui::h($k['name']) ?> (šipky nahoru a dolů)"><span aria-hidden="true">⠿</span></button></td><td><a href="<?= Ui::h(Ui::odkaz('galerie', ['kategorie' => $k['id']])) ?>"><strong><?= Ui::h($k['name']) ?></strong></a>
        <?php if ($k['note']): ?><div class="slabe mala"><?= Ui::h($k['note']) ?></div><?php endif; ?></td>
      <td class="cisla"><?= Ui::cislo($k['pocet']) ?></td><td class="cisla"><?= Ui::h(Zalohy::velikost((int)$k['velikost'])) ?></td>
      <td><?= !empty($k['web_public']) ? '<a href="' . Ui::h($app->url('?s=galerie&k=' . rawurlencode(Web::slug($k)))) . '">' . Ui::stav('bezi', 'Veřejná · na webu ' . $web->pocetVerejnych((int)$k['id'])) . '</a>' : '<span class="slabe mala">neveřejná</span>' ?></td>
      <td class="bunka-akce"><div class="tlacitka"><a class="tlacitko maly" href="<?= Ui::h(Ui::odkaz('kategorie', ['upravit' => $k['id']])) ?>">Upravit</a>
        <?= $ui->tlacitko('Smazat', 'smazat', ['id' => $k['id']], 'maly nebezpecne', 'Smazat kategorii „' . $k['name'] . '“? Média zůstanou v galerii bez kategorie.') ?></div></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
  <p class="slabe mala"><a href="<?= Ui::h(Ui::odkaz('galerie', ['kategorie' => 0])) ?>">Média bez kategorie</a>: <?= Ui::cislo($bez) ?></p>
</section>
<section>
  <form method="post" class="formular karta" action="<?= Ui::h(Ui::odkaz('kategorie', $upravit ? ['upravit' => $upravit['id']] : [])) ?>">
    <h2 style="margin:0"><?= $upravit ? 'Upravit kategorii' : 'Nová kategorie' ?></h2>
    <?= $ui->csrf() ?>
    <input type="hidden" name="akce" value="ulozit">
    <?= Ui::pole('Název', 'name', $upravit['name'] ?? '', ['required' => true, 'maxlength' => 200]) ?>
    <?= Ui::oblast('Poznámka', 'note', $upravit['note'] ?? '', ['rows' => 3]) ?>
    <h3 style="margin:.5rem 0 0">Veřejný web</h3>
    <label class="zaskrtavatko"><input type="checkbox" name="web_public" value="1"<?= ($_SERVER['REQUEST_METHOD'] === 'POST' ? !empty($_POST['web_public']) : !empty($upravit['web_public'])) ? ' checked' : '' ?>> <span>Zobrazit na veřejném webu</span></label>
    <small class="slabe mala">Na webu budou obrázky této kategorie, které mají zapnutý veřejný odkaz a nejsou v archivu.</small>
    <?= Ui::oblast('Popis na webu', 'web_description', $upravit['web_description'] ?? '', ['rows' => 2, 'maxlength' => 500]) ?>
    <?php if ($upravit): ?><?= Ui::vyber('Obálka', 'web_cover_id', ['' => '— automaticky: nejnovější fotka —'] + $web->obrazkyKategorie((int)$upravit['id']), $upravit['web_cover_id'] ?? '') ?><?php endif; ?>
    <div class="tlacitka"><button type="submit" class="hlavni"><?= $upravit ? 'Uložit' : 'Vytvořit' ?></button>
      <?php if ($upravit): ?><a class="tlacitko" href="<?= Ui::h(Ui::odkaz('kategorie')) ?>">Zrušit</a><?php endif; ?></div>
  </form>
</section>
</div>
