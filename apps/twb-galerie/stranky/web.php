<?php
declare(strict_types=1);
// Veřejný web: karty Základ a texty, Reklama (0.8.3: jen kód pro každou pozici, ads.txt), Měření návštěvnosti (kód, kam vložit),
// GDPR a cookies (souhlas, zásady ochrany osobních údajů). Každá karta má svůj formulář (akce = část). Proměnné: $app, $ui, $post.
use MediaGalerie\{Ui, Chyba, Web};

$web = $app->web();
$karta = (string)($_GET['karta'] ?? '');
if (!isset(Web::CASTI[$karta])) $karta = 'zaklad';
if ($post) {
    $cast = (string)($_POST['akce'] ?? '');
    if (isset(Web::CASTI[$cast])) $karta = $cast;   // po chybě zůstat na kartě formuláře
    if ($ui->zpracuj(function () use ($web, $cast) {
        if (!isset(Web::CASTI[$cast])) throw new Chyba('Neznámá akce.');
        $web->uloz($cast, $_POST);
    })) {
        $ui->zprava('ok', 'Nastavení webu uloženo.');
        $ui->presmeruj(Ui::odkaz('web', ['karta' => $cast]) . '#' . $cast);
    }
}
$n = $web->nastaveni();
$kategorie = $web->kategorie();
$vsechny = $app->kategorie()->seznam();
$uvod = $web->uvodni($kategorie);
$doporucenych = $web->pocetDoporucenych();
$mimoWeb = $doporucenych['celkem'] > $doporucenych['verejne'] ? $web->doporuceneMimoWeb() : [];
$rozsah = static fn(array $od_do): array => array_combine(range($od_do[0], $od_do[1]), array_map('strval', range($od_do[0], $od_do[1])));
// po chybě ukázat odeslaný stav zaškrtávátek jen ve formuláři, který se odesílal (ostatní karty zůstanou podle uloženého nastavení)
$castPole = ['reklama_zapnuto' => 'reklama', 'mereni_zapnuto' => 'mereni', 'cookies_souhlas' => 'gdpr', 'zasady_zapnuto' => 'gdpr'];
$zaskrtnuto = static fn(string $nazev, bool $vychozi): bool => ($post && ($_POST['akce'] ?? '') === ($castPole[$nazev] ?? 'zaklad')) ? !empty($_POST[$nazev]) : $vychozi;
$zaskrt = static fn(string $nazev, string $popis, bool $vychozi, string $napoveda = ''): string => '<label class="zaskrtavatko"><input type="checkbox" name="' . Ui::h($nazev) . '" value="1"'
    . ($zaskrtnuto($nazev, $vychozi) ? ' checked' : '') . '> <span>' . Ui::h($popis) . '</span></label>' . ($napoveda !== '' ? '<small class="slabe mala" style="display:block;margin:-.25rem 0 .5rem 1.6rem">' . $napoveda . '</small>' : '');
/** Vypínač (zaškrtávátko vzhledu přepínače) s nápovědou pod ním. */
$vypinac = static fn(string $nazev, string $popis, bool $vychozi, string $napoveda = ''): string => '<label class="vypinac"><input type="checkbox" role="switch" name="' . Ui::h($nazev) . '" value="1"'
    . ($zaskrtnuto($nazev, $vychozi) ? ' checked' : '') . '> <span>' . Ui::h($popis) . '</span></label>' . ($napoveda !== '' ? '<p class="slabe" style="margin:.5rem 0 0">' . $napoveda . '</p>' : '');
$panel = fn(string $id): string => '<div class="panel-karty" id="' . $id . '" role="tabpanel" aria-labelledby="karta-' . $id . '" tabindex="-1" data-karta'
    . ($id === $karta ? '' : ' hidden') . '>';
$formular = fn(string $cast): string => '<form method="post" action="' . Ui::h(Ui::odkaz('web', ['karta' => $cast]) . '#' . $cast) . '">' . $ui->csrf()
    . '<input type="hidden" name="akce" value="' . $cast . '">';
$souhlas = Web::kategorieSouhlasu(['cookies_souhlas' => true] + $n);
$adresaZasad = $app->url(Web::ZASADY);
?>
<div class="karta">
  <p style="margin-top:0">Veřejný web je na adrese <a href="<?= Ui::h($app->url()) ?>/"><strong><?= Ui::h($app->url()) ?>/</strong></a>: hlavní stránka, <a href="<?= Ui::h($app->url('?s=galerie')) ?>">Galerie</a> a <a href="<?= Ui::h($app->url('?s=o-galerii')) ?>">O galerii</a>.
    Administrace zůstává na <code>index.php</code>.</p>
  <p class="slabe mala" style="margin-bottom:0">Na webu je jen to, co zveřejníte: kategorie se zaškrtnutým <em>Zobrazit na veřejném webu</em> (stránka <a href="<?= Ui::h(Ui::odkaz('kategorie')) ?>">Kategorie</a>)
    a v nich obrázky se zapnutým veřejným odkazem, které nejsou v archivu. Vypnutím odkazu u média fotka z webu zmizí. Videa a PDF se na webu neukazují.</p>
  <p style="margin-bottom:0"><?= $n['zapnuto'] ? Ui::stav('bezi', 'Web je zapnutý') : Ui::stav('ceka', 'Web je vypnutý – kořen domény vede do administrace') ?>
    · veřejných kategorií s fotkami: <strong><?= count($kategorie) ?></strong> z <?= count($vsechny) ?></p>
</div>

<nav class="karty-nav" role="tablist" aria-label="Oddíly veřejného webu">
<?php foreach (Web::CASTI as $id => $nazev): ?>
  <a href="<?= Ui::h(Ui::odkaz('web', ['karta' => $id]) . '#' . $id) ?>" id="karta-<?= $id ?>" role="tab" aria-controls="<?= $id ?>" aria-selected="<?= $id === $karta ? 'true' : 'false' ?>"<?= $id === $karta ? ' class="aktivni"' : ' tabindex="-1"' ?>><?= Ui::h($nazev) ?></a>
<?php endforeach; ?>
</nav>

<?= $panel('zaklad') ?>
<?= $formular('zaklad') ?>
<div class="formular karta">
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

  <h2 style="margin:1rem 0 0">Doporučené fotografie</h2>
  <p class="slabe" style="margin:0">Hlavní stránka ukáže pod vybranými galeriemi náhodný výběr z fotografií označených jako <strong>doporučená</strong>
    (v detailu média nebo hromadnou akcí v <a href="<?= Ui::h(Ui::odkaz('galerie')) ?>">Galerii</a>). Při každém načtení stránky jiný. Ukážou se jen veřejné fotky
    (veřejná kategorie, zapnutý veřejný odkaz, mimo archiv); bez nich se sekce skryje.</p>
  <p class="karta-poznamka">Doporučených fotografií: <strong><?= $doporucenych['verejne'] ?></strong> na webu<?= $doporucenych['celkem'] > $doporucenych['verejne'] ? ' (dalších ' . ($doporucenych['celkem'] - $doporucenych['verejne']) . ' není veřejných)' : '' ?>
    · <a href="<?= Ui::h(Ui::odkaz('galerie', ['doporucene' => 1])) ?>">zobrazit v Galerii</a></p>
  <?php if ($mimoWeb): ?>
  <details class="doporucene-mimo-web" open>
    <summary>Doporučené, které se na webu neukážou (<?= count($mimoWeb) ?>)</summary>
    <ul>
      <?php foreach ($mimoWeb as $d): ?>
      <li><a href="<?= Ui::h(Ui::odkaz('medium', ['id' => $d['id']])) ?>"><?= Ui::h($d['nazev']) ?></a><?= $d['kategorie'] !== '' ? ' <span class="slabe">· ' . Ui::h($d['kategorie']) . '</span>' : '' ?>
        <br><span class="slabe"><?= Ui::h($d['duvod']) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </details>
  <?php endif; ?>
  <?= Ui::pole('Nadpis', 'doporucene_nadpis', $n['doporucene_nadpis'], ['required' => true, 'maxlength' => 80]) ?>
  <?= Ui::pole('Podnadpis', 'doporucene_podnadpis', $n['doporucene_podnadpis'], ['maxlength' => 160]) ?>
  <div class="radek">
    <?= Ui::vyber('Sloupce', 'doporucene_sloupce', $rozsah(Web::SLOUPCE), (string)$n['doporucene_sloupce']) ?>
    <?= Ui::vyber('Řádky', 'doporucene_radky', $rozsah(Web::RADKY), (string)$n['doporucene_radky'], [], 'Na webu najednou nejvýš sloupce × řádky fotek. Na mobilu se sloupce zúží (až na 2). Když doporučených nestačí, poslední řádek bude neúplný.') ?>
  </div>

  <h2 style="margin:1rem 0 0">Stránka O galerii</h2>
  <?= Ui::oblast('Text', 'o_galerii', $n['o_galerii'], ['rows' => 10, 'maxlength' => 20000],
      'Odstavce oddělte prázdným řádkem. Jde použít <code>**tučně**</code>, odrážky <code>- </code>, nadpisy <code>## </code> a odkazy <code>[text](https://…)</code>.') ?>

  <div class="tlacitka"><button type="submit" class="hlavni">Uložit</button>
    <a class="tlacitko" href="<?= Ui::h($app->url()) ?>/">Zobrazit web</a></div>
</div>
</form>
</div>

<?= $panel('reklama') ?>
<?= $formular('reklama') ?>
<div class="karta">
  <?= $vypinac('reklama_zapnuto', 'Zobrazovat reklamu na veřejných stránkách', (bool)$n['reklama_zapnuto'],
      'Do každé pozice vložte <strong>kód ze svého reklamního systému</strong> (HTML, případně i <code>&lt;script&gt;</code>) – AdSense, Sklik, Revive, Etarget…'
      . ' Vypíše se přesně tak, jak ho sem napíšete – vkládejte proto jen kód, kterému věříte. Prázdná pozice se nezobrazí. Do administrace se reklama nevkládá.') ?>
</div>
<?php foreach (Web::POZICE as $k => [$nazev, $popis]): ?>
<div class="karta formular">
  <h2 style="margin:0"><?= Ui::h($nazev) ?></h2>
  <p class="slabe" style="margin:0"><?= Ui::h($popis) ?></p>
  <textarea name="reklama_<?= $k ?>" class="kod" rows="4" spellcheck="false" maxlength="<?= Web::MAX_KOD ?>" aria-label="Kód reklamy – <?= Ui::h($nazev) ?>"
    placeholder="<ins …></ins>&#10;<script async src=&quot;https://…&quot;></script>"><?= Ui::h($post && $karta === 'reklama' ? (string)($_POST["reklama_{$k}"] ?? '') : $n['reklama'][$k]) ?></textarea>
</div>
<?php endforeach; ?>
<div class="karta formular">
  <h2 style="margin:0">ads.txt</h2>
  <p class="slabe" style="margin:0">Web ho vydá na <a href="<?= Ui::h($app->url('ads.txt')) ?>"><code>/ads.txt</code></a> – vyžaduje ho např. AdSense (<em>Weby → ads.txt</em>). Prázdné = soubor neexistuje.</p>
  <textarea name="ads_txt" class="kod" rows="3" spellcheck="false" maxlength="<?= Web::MAX_KOD ?>" aria-label="Obsah ads.txt"
    placeholder="google.com, pub-0000000000000000, DIRECT, f08c47fec0942fa0"><?= Ui::h($post && $karta === 'reklama' ? (string)($_POST['ads_txt'] ?? '') : $n['ads_txt']) ?></textarea>
</div>
<div class="tlacitka"><button type="submit" class="hlavni">Uložit</button></div>
</form>
</div>

<?= $panel('mereni') ?>
<?= $formular('mereni') ?>
<div class="karta">
  <?= $vypinac('mereni_zapnuto', 'Měřit návštěvnost veřejných stránek', (bool)$n['mereni_zapnuto'],
      'Vložte <strong>měřicí kód</strong> ze svého nástroje – typicky Matomo (v něm <em>Administrace → Webové stránky → Sledovací kód</em>) nebo Google Analytics, ale funguje jakýkoli kód.'
      . ' Vypíše se přesně tak, jak ho sem napíšete. Měří se jen <strong>veřejné stránky</strong> (hlavní stránka, galerie, O galerii, zásady); do administrace se kód nevkládá.') ?>
</div>
<div class="karta formular">
  <h2 style="margin:0">Měřicí kód</h2>
  <p class="slabe" style="margin:0">Celý úsek včetně <code>&lt;script&gt;</code> (klidně i s <code>&lt;noscript&gt;</code>). Prázdné pole = neměří se nic.</p>
  <textarea name="mereni_kod" class="kod" rows="12" spellcheck="false" maxlength="<?= Web::MAX_KOD ?>" aria-label="Měřicí kód"
    placeholder="<!-- Matomo -->&#10;<script>&#10;  var _paq = window._paq = window._paq || [];&#10;  …&#10;</script>"><?= Ui::h($post && $karta === 'mereni' ? (string)($_POST['mereni_kod'] ?? '') : $n['mereni_kod']) ?></textarea>
  <?= Ui::vyber('Kam kód vložit', 'mereni_umisteni', Web::UMISTENI_MERENI, $n['mereni_umisteni']) ?>
</div>
<div class="tlacitka"><button type="submit" class="hlavni">Uložit</button></div>
</form>
</div>

<?= $panel('gdpr') ?>
<?= $formular('gdpr') ?>
<div class="karta">
  <h2 style="margin:0 0 .5rem">Souhlas s cookies</h2>
  <?= $vypinac('cookies_souhlas', 'Ptát se návštěvníků na souhlas s cookies', (bool)$n['cookies_souhlas'],
      'Se zapnutým souhlasem se <strong>měřicí ani reklamní kód vůbec nenačte</strong>, dokud ho návštěvník nepovolí – nečeká v stránce „připravený“, ale jen jako neživý text.'
      . ' Volbu si prohlížeč pamatuje rok a změnit se dá odkazem <em>Nastavení cookies</em> v patičce.') ?>
  <p class="karta-poznamka"><?= $souhlas ? 'Lišta se bude ptát na: <strong>' . Ui::h(implode(', ', $souhlas)) . '</strong>.'
      : 'Teď není na co se ptát – reklama ani měření nemají vyplněný kód, lišta se nezobrazí.' ?></p>
</div>
<div class="karta formular">
  <h2 style="margin:0">Zásady ochrany osobních údajů</h2>
  <?= $vypinac('zasady_zapnuto', 'Zveřejnit stránku se zásadami', (bool)$n['zasady_zapnuto'],
      'Adresa: <a href="' . Ui::h($adresaZasad) . '"><code>' . Ui::h($adresaZasad) . '</code></a>. Odkaz se přidá do patičky webu a do cookie lišty. Stránka funguje i při vypnutém webu.') ?>
  <div class="radek radek-zasady">
    <?= Ui::pole('Provozovatel (jméno nebo firma)', 'provozovatel', $n['provozovatel'], ['maxlength' => 120]) ?>
    <?= Ui::pole('IČO', 'ico', $n['ico'], ['maxlength' => 12, 'inputmode' => 'numeric', 'placeholder' => '12345678']) ?>
    <?= Ui::pole('Kontaktní e-mail', 'email', $n['email'], ['type' => 'email', 'maxlength' => 120]) ?>
  </div>
  <div class="radek radek-zasady">
    <?= Ui::pole('Adresa', 'adresa', $n['adresa'], ['maxlength' => 200]) ?>
    <?= Ui::pole('Název stránky', 'nazev_stranky', $n['nazev_stranky'], ['maxlength' => 80, 'placeholder' => $web->nazevStranky(['nazev_stranky' => ''] + $n)]) ?>
  </div>
  <?= Ui::oblast('Vlastní text (Markdown)', 'zasady_text', $n['zasady_text'], ['rows' => 16, 'maxlength' => 30000, 'class' => 'kod', 'placeholder' => "## Kdo zpracovává vaše údaje\n\n…"],
      'Prázdné = použije se vzorový text sestavený z údajů výše, který popisuje, co web <strong>skutečně</strong> dělá (záznamy serveru, cookie s volbou souhlasu, měření a reklama podle toho, co je zapnuté).'
      . ' <strong>Projděte si ho</strong> a upravte podle sebe – je to podklad, ne právní posudek. Tlačítkem níž si ho vložíte do pole a můžete v něm dál psát (vzor je z uložených údajů, po jejich změně nejdřív uložte).') ?>
  <div><button type="button" data-vlozit-vzor="f-zasady_text" data-vzor="<?= Ui::h($web->vzorZasad($n)) ?>">Vložit vzorový text do pole</button></div>
</div>
<div class="tlacitka"><button type="submit" class="hlavni">Uložit</button>
  <?php if ($n['zasady_zapnuto']): ?><a class="tlacitko" href="<?= Ui::h($adresaZasad) ?>">Zobrazit zásady</a><?php endif; ?></div>
</form>
</div>
