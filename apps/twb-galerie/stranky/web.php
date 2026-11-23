<?php
declare(strict_types=1);
// Veřejný web (0.8.0): zapnutí, texty hlavní stránky, úvodní fotka, reklamní plochy, stránka O galerii. Proměnné: $app, $ui, $post.
use MediaGalerie\{Ui, Chyba, Web};

$web = $app->web();

if ($post) {
    if ($ui->zpracuj(function () use ($web) {
        if (($_POST['akce'] ?? '') !== 'ulozit') throw new Chyba('Neznámá akce.');
        $web->uloz($_POST);
    })) {
        $ui->zprava('ok', 'Nastavení webu uloženo.');
        $ui->presmeruj(Ui::odkaz('web'));
    }
}
$n = $web->nastaveni();
$kategorie = $web->kategorie();
$vsechny = $app->kategorie()->seznam();
$uvod = $web->uvodni($kategorie);
$zaskrtnuto = static fn(string $nazev, bool $vychozi): bool => ($_SERVER['REQUEST_METHOD'] === 'POST') ? !empty($_POST[$nazev]) : $vychozi;
$zaskrt = static fn(string $nazev, string $popis, bool $vychozi, string $napoveda = ''): string => '<label class="zaskrtavatko"><input type="checkbox" name="' . Ui::h($nazev) . '" value="1"'
    . ($zaskrtnuto($nazev, $vychozi) ? ' checked' : '') . '> <span>' . Ui::h($popis) . '</span></label>' . ($napoveda !== '' ? '<small class="slabe mala" style="display:block;margin:-.25rem 0 .5rem 1.6rem">' . $napoveda . '</small>' : '');
?>
<div class="karta">
  <p style="margin-top:0">Veřejný web je na adrese <a href="<?= Ui::h($app->url()) ?>/"><strong><?= Ui::h($app->url()) ?>/</strong></a>: hlavní stránka, <a href="<?= Ui::h($app->url('?s=galerie')) ?>">Galerie</a> a <a href="<?= Ui::h($app->url('?s=o-galerii')) ?>">O galerii</a>.
    Administrace zůstává na <code>index.php</code>.</p>
  <p class="slabe mala" style="margin-bottom:0">Na webu je jen to, co zveřejníte: kategorie se zaškrtnutým <em>Zobrazit na veřejném webu</em> (stránka <a href="<?= Ui::h(Ui::odkaz('kategorie')) ?>">Kategorie</a>)
    a v nich obrázky se zapnutým veřejným odkazem, které nejsou v archivu. Vypnutím odkazu u média fotka z webu zmizí. Videa a PDF se na webu neukazují.</p>
  <p style="margin-bottom:0"><?= $n['zapnuto'] ? Ui::stav('bezi', 'Web je zapnutý') : Ui::stav('ceka', 'Web je vypnutý – kořen domény vede do administrace') ?>
    · veřejných kategorií s fotkami: <strong><?= count($kategorie) ?></strong> z <?= count($vsechny) ?></p>
</div>

<form method="post" class="formular karta" action="<?= Ui::h(Ui::odkaz('web')) ?>">
  <?= $ui->csrf() ?>
  <input type="hidden" name="akce" value="ulozit">
  <h2 style="margin:0">Základ</h2>
  <?= $zaskrt('zapnuto', 'Veřejný web zapnutý', (bool)$n['zapnuto'], 'Vypnutý web přesměruje návštěvníky na přihlášení do administrace.') ?>
  <?= $zaskrt('odkaz_administrace', 'Ukázat tlačítko „Přihlášení do administrace“', (bool)$n['odkaz_administrace'], 'Bez tlačítka se do administrace dostanete na adrese …/index.php.') ?>
  <?= $zaskrt('skryt_vyhledavacum', 'Skrýt web před vyhledávači (noindex)', (bool)$n['skryt_vyhledavacum']) ?>
  <?= Ui::pole('Název webu (logo)', 'nazev', $n['nazev'], ['required' => true, 'maxlength' => 60]) ?>
  <?= Ui::pole('Patička', 'patka', $n['patka'], ['maxlength' => 200]) ?>

  <h2 style="margin:1rem 0 0">Úvodní fotka a texty</h2>
  <?= Ui::vyber('Úvodní fotka', 'uvod_id', ['' => '— automaticky: obálka první veřejné galerie —'] + $web->moznosti(), $n['uvod_id'] ?? '', [],
      'Jen obrázky se zapnutým veřejným odkazem (nejnovějších 500). Jde nastavit i tlačítkem v detailu média.'
      . ($uvod ? ' Teď se ukazuje: <strong>' . Ui::h($uvod['original_name']) . '</strong>.' : ' Zatím žádná – místo fotky bude barevný přechod.')) ?>
  <?= Ui::pole('Štítek nad nadpisem', 'stitek', $n['stitek'], ['maxlength' => 60]) ?>
  <?= Ui::pole('Nadpis', 'nadpis', $n['nadpis'], ['required' => true, 'maxlength' => 120]) ?>
  <?= Ui::pole('Podnadpis', 'podnadpis', $n['podnadpis'], ['maxlength' => 300]) ?>
  <?= Ui::pole('Text tlačítka', 'tlacitko', $n['tlacitko'], ['required' => true, 'maxlength' => 40]) ?>
  <?= Ui::pole('Poznámka pod tlačítkem', 'poznamka', $n['poznamka'], ['maxlength' => 120]) ?>

  <h2 style="margin:1rem 0 0">Vybrané galerie</h2>
  <?= Ui::pole('Nadpis', 'vybrane_nadpis', $n['vybrane_nadpis'], ['required' => true, 'maxlength' => 80]) ?>
  <?= Ui::pole('Podnadpis', 'vybrane_podnadpis', $n['vybrane_podnadpis'], ['maxlength' => 160]) ?>
  <?= Ui::pole('Počet karet na hlavní stránce', 'vybrane_pocet', (string)$n['vybrane_pocet'], ['type' => 'number', 'min' => 0, 'max' => 12],
      'Prvních N veřejných kategorií podle pořadí nastaveného u kategorie (0 = sekce se skryje). Všechny jsou na stránce Galerie.') ?>

  <?php foreach (Web::BANNERY as $b => $popis): $hb = $n[$b]; ?>
  <h2 style="margin:1rem 0 0"><?= Ui::h($popis) ?></h2>
  <?= $zaskrt("{$b}_zobrazit", 'Zobrazit', (bool)$hb['zobrazit']) ?>
  <?= Ui::pole('Štítek vlevo', "{$b}_stitek", $hb['stitek'], ['maxlength' => 40]) ?>
  <?= Ui::pole('Text', "{$b}_text", $hb['text'], ['maxlength' => 200]) ?>
  <?= Ui::pole('Text tlačítka', "{$b}_tlacitko", $hb['tlacitko'], ['maxlength' => 40]) ?>
  <?= Ui::pole('Odkaz tlačítka', "{$b}_odkaz", $hb['odkaz'], ['maxlength' => 500, 'placeholder' => 'https://…'],
      'https://…, mailto:…, tel:… nebo adresa na tomto webu (např. <code>?s=o-galerii</code>). Bez odkazu se tlačítko nezobrazí.') ?>
  <?php endforeach; ?>

  <h2 style="margin:1rem 0 0">Stránka O galerii</h2>
  <?= Ui::oblast('Text', 'o_galerii', $n['o_galerii'], ['rows' => 10, 'maxlength' => 20000],
      'Odstavce oddělte prázdným řádkem. Jde použít <code>**tučně**</code>, odrážky <code>- </code>, nadpisy <code>## </code> a odkazy <code>[text](https://…)</code>.') ?>

  <div class="tlacitka"><button type="submit" class="hlavni">Uložit</button>
    <a class="tlacitko" href="<?= Ui::h($app->url()) ?>/">Zobrazit web</a></div>
</form>
