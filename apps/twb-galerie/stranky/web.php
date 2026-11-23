<?php
declare(strict_types=1);
// Veřejný web: karty Základ a texty (0.8.8: skupiny polí ve vlastních kartách), Reklama (0.8.3: jen kód pro každou pozici, ads.txt), Měření návštěvnosti (kód, kam vložit),
// GDPR a cookies (souhlas, zásady ochrany osobních údajů), 0.8.12: u každé reklamní pozice štítek „Reklama“ a karta Vlastní CSS. Každá karta má svůj formulář (akce = část). Proměnné: $app, $ui, $post.
use MediaGalerie\{Ui, Chyba, Web};

$web = $app->web();
$karta = (string)($_GET['karta'] ?? '');
if (!isset(Web::CASTI[$karta])) $karta = 'zaklad';
if ($post) {
    $cast = (string)($_POST['akce'] ?? '');
    if (isset(Web::CASTI[$cast])) $karta = $cast;   // po chybě zůstat na kartě formuláře
    if ($ui->zpracuj(function () use ($web, $cast) {
        if (!isset(Web::CASTI[$cast])) throw new Chyba('Neznámá akce.');
        // 0.8.11: vlastní logo (nahrání nebo odebrání) jen s kartou Základ a texty; nejdřív soubor, ať chyba v něm nic neuloží napůl
        if ($cast === 'zaklad') {
            if (!empty($_POST['logo_smazat'])) $web->smazLogo();
            elseif (($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) $web->ulozLogo($_FILES['logo']);
        }
        $web->uloz($cast, $_POST);
    })) {
        $ui->zprava('ok', 'Nastavení webu uloženo.');
        $ui->presmeruj(Ui::odkaz('web', ['karta' => $cast]) . '#' . $cast);
    }
}
$n = $web->nastaveni();
$kategorie = $web->kategorie();
$vsechny = $app->kategorie()->seznam();
$uvodni = [];   // 0.8.11: zvolené úvodní fotky podle id (náhled u výběru)
foreach ($web->uvodniFotky() as $m) $uvodni[(int)$m['id']] = $m;
$automaticka = $uvodni ? null : $web->uvodni($kategorie);
$logo = $web->logo();
$moznosti = $web->moznosti();
$doporucenych = $web->pocetDoporucenych();
$mimoWeb = $doporucenych['celkem'] > $doporucenych['verejne'] ? $web->doporuceneMimoWeb() : [];
$rozsah = static fn(array $od_do): array => array_combine(range($od_do[0], $od_do[1]), array_map('strval', range($od_do[0], $od_do[1])));
// po chybě ukázat odeslaný stav zaškrtávátek jen ve formuláři, který se odesílal (ostatní karty zůstanou podle uloženého nastavení)
$castPole = ['reklama_zapnuto' => 'reklama', 'mereni_zapnuto' => 'mereni', 'cookies_souhlas' => 'gdpr', 'zasady_zapnuto' => 'gdpr', 'vlastni_css_zapnuto' => 'css']
    + array_fill_keys(array_map(fn($k) => "reklama_stitek_{$k}", array_keys(Web::POZICE)), 'reklama');
$zaskrtnuto = static fn(string $nazev, bool $vychozi): bool => ($post && ($_POST['akce'] ?? '') === ($castPole[$nazev] ?? 'zaklad')) ? !empty($_POST[$nazev]) : $vychozi;
/** Vypínač (zaškrtávátko vzhledu přepínače) s nápovědou pod ním. */
$vypinac = static fn(string $nazev, string $popis, bool $vychozi, string $napoveda = ''): string => '<label class="vypinac"><input type="checkbox" role="switch" name="' . Ui::h($nazev) . '" value="1"'
    . ($zaskrtnuto($nazev, $vychozi) ? ' checked' : '') . '> <span>' . Ui::h($popis) . '</span></label>' . ($napoveda !== '' ? '<p class="slabe" style="margin:.5rem 0 0">' . $napoveda . '</p>' : '');
$panel = fn(string $id): string => '<div class="panel-karty" id="' . $id . '" role="tabpanel" aria-labelledby="karta-' . $id . '" tabindex="-1" data-karta'
    . ($id === $karta ? '' : ' hidden') . '>';
$formular = fn(string $cast): string => '<form method="post"' . ($cast === 'zaklad' ? ' enctype="multipart/form-data"' : '') . ' action="' . Ui::h(Ui::odkaz('web', ['karta' => $cast]) . '#' . $cast) . '">' . $ui->csrf()
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
<?php /* 0.8.8: karta rozdělená do skupin – každá skupina má vlastní kartu s nadpisem a krátkým popisem, pole vedle sebe, kde se to hodí */ ?>
<div class="web-zaklad">
<section class="karta formular web-skupina" aria-labelledby="sk-stav">
  <header class="skupina-hlavicka"><h2 id="sk-stav">Stav webu</h2><p class="slabe">Zapnutí webu a co uvidí návštěvníci a vyhledávače.</p></header>
  <?= $vypinac('zapnuto', 'Veřejný web zapnutý', (bool)$n['zapnuto'], 'Vypnutý web přesměruje návštěvníky na přihlášení do administrace.') ?>
  <?= $vypinac('odkaz_administrace', 'Tlačítko „Přihlášení do administrace“', (bool)$n['odkaz_administrace'], 'Bez tlačítka se do administrace dostanete na adrese …/index.php.') ?>
  <?= $vypinac('skryt_vyhledavacum', 'Skrýt web před vyhledávači (noindex)', (bool)$n['skryt_vyhledavacum']) ?>
</section>

<section class="karta formular web-skupina" aria-labelledby="sk-logo">
  <header class="skupina-hlavicka"><h2 id="sk-logo">Logo a patička</h2><p class="slabe">Logo a název v záhlaví každé stránky webu, na přihlášení a v administraci; text v patičce.</p></header>
  <?php /* 0.8.11: vlastní grafické logo; bez něj znak s názvem webu */ ?>
  <div class="logo-vyber">
    <div class="logo-nahled<?= $logo ? '' : ' logo-nahled-prazdne' ?>"><?php if ($logo): ?><img src="<?= Ui::h($web->logoAdresa()) ?>" alt="Vlastní logo"><?php else: ?><span>Bez vlastního loga – znak a název</span><?php endif; ?></div>
    <div class="logo-pole">
      <label class="pole" for="f-logo"><span><?= $logo ? 'Nahradit logo' : 'Vlastní logo' ?></span>
        <input id="f-logo" type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml,.svg">
        <small>PNG, JPG, WebP, GIF nebo SVG, nejvýš 1 MB. V záhlaví se zmenší na výšku 48 px (v administraci 36 px) – nejlépe vypadá logo na šířku s průhledným pozadím, aspoň 96 px vysoké nebo SVG.
          <?= $logo ? '<br>Teď: <strong>' . Ui::h(strtoupper(Web::LOGO_TYPY[$logo['mime']] ?? '')) . '</strong>' . ($logo['width'] ? ', ' . $logo['width'] . ' × ' . $logo['height'] . ' px' : '') . ', nahráno ' . date('j. n. Y H:i', $logo['updated_at']) . '.' : '' ?></small></label>
      <?php if ($logo): ?><label class="zaskrtavatko"><input type="checkbox" name="logo_smazat" value="1"> <span>Odebrat vlastní logo (vrátí se znak s názvem)</span></label><?php endif; ?>
    </div>
  </div>
  <input type="hidden" name="logo_nazev_pole" value="1">
  <?= $vypinac('logo_nazev', 'Vedle loga zobrazit i název webu', (bool)$n['logo_nazev'], 'Vypněte, když logo název už obsahuje. Bez vlastního loga se název ukazuje vždy.') ?>
  <?= Ui::pole('Název webu', 'nazev', $n['nazev'], ['required' => true, 'maxlength' => 60], 'V záhlaví, v titulku stránek a jako popis loga pro čtečky.') ?>
  <?= Ui::pole('Patička', 'patka', $n['patka'], ['maxlength' => 200, 'placeholder' => '© …']) ?>
</section>

<section class="karta formular web-skupina web-skupina-siroka" aria-labelledby="sk-uvod">
  <header class="skupina-hlavicka"><h2 id="sk-uvod">Úvodní část hlavní stránky</h2><p class="slabe">Velká fotka nahoře s nadpisem a tlačítkem do Galerie.
    Zvolte až <?= Web::MAX_UVODNI ?> fotek – při každém načtení stránky se náhodně ukáže jedna z nich.</p></header>
  <?php /* 0.8.11: až pět úvodních fotek (uvod_1 … uvod_5), náhled se mění s výběrem (assets/app.js, data-uvod-vyber) */ ?>
  <div class="uvod-sloty">
  <?php for ($i = 1; $i <= Web::MAX_UVODNI; $i++):
      $id = (int)($n['uvod_ids'][$i - 1] ?? 0);
      $m = $uvodni[$id] ?? null;
      $nahled = $m ? 'media.php?id=' . (int)$m['id'] . '&nahled=1&v=' . substr((string)$m['md5'], 0, 8) : ''; ?>
    <div class="uvod-slot">
      <div class="uvod-nahled" data-uvod-nahled="f-uvod_<?= $i ?>"><img src="<?= Ui::h($nahled) ?>" alt=""<?= $nahled === '' ? ' hidden' : '' ?>><span<?= $nahled !== '' ? ' hidden' : '' ?>><?= $i === 1 && $automaticka ? 'Automaticky: obálka první galerie' : 'Volné místo' ?></span></div>
      <?= Ui::vyber('Fotka ' . $i, 'uvod_' . $i, ['' => $i === 1 ? '— automaticky: obálka první veřejné galerie —' : '— žádná —'] + $moznosti, $m ? (string)$id : '', ['data-uvod-vyber' => true]) ?>
    </div>
  <?php endfor; ?>
  </div>
  <small class="slabe mala">Jen obrázky se zapnutým veřejným odkazem, které nejsou v archivu (nejnovějších 500). Přidat fotku jde i tlačítkem <em>Přidat mezi úvodní fotky</em> v detailu média.
    Když nezvolíte žádnou, ukáže se obálka první veřejné galerie<?= $automaticka ? ' (teď <strong>' . Ui::h($automaticka['original_name']) . '</strong>)' : '' ?>.</small>
  <div class="radek radek-2">
    <?= Ui::pole('Štítek nad nadpisem', 'stitek', $n['stitek'], ['maxlength' => 60]) ?>
    <?= Ui::pole('Nadpis', 'nadpis', $n['nadpis'], ['required' => true, 'maxlength' => 120]) ?>
  </div>
  <?= Ui::pole('Podnadpis', 'podnadpis', $n['podnadpis'], ['maxlength' => 300]) ?>
  <div class="radek radek-2">
    <?= Ui::pole('Text tlačítka', 'tlacitko', $n['tlacitko'], ['required' => true, 'maxlength' => 40]) ?>
    <?= Ui::pole('Poznámka pod tlačítkem', 'poznamka', $n['poznamka'], ['maxlength' => 120]) ?>
  </div>
</section>

<section class="karta formular web-skupina" aria-labelledby="sk-vybrane">
  <header class="skupina-hlavicka"><h2 id="sk-vybrane">Vybrané galerie</h2><p class="slabe">Karty kategorií na hlavní stránce, v pořadí ze stránky <a href="<?= Ui::h(Ui::odkaz('kategorie')) ?>">Kategorie</a>.</p></header>
  <?= Ui::pole('Nadpis', 'vybrane_nadpis', $n['vybrane_nadpis'], ['required' => true, 'maxlength' => 80]) ?>
  <?= Ui::pole('Podnadpis', 'vybrane_podnadpis', $n['vybrane_podnadpis'], ['maxlength' => 160]) ?>
  <?= Ui::pole('Počet karet', 'vybrane_pocet', (string)$n['vybrane_pocet'], ['type' => 'number', 'min' => 0, 'max' => 12],
      'Prvních N veřejných kategorií (0 = sekce se skryje). Všechny jsou na stránce Galerie.') ?>
</section>

<section class="karta formular web-skupina" aria-labelledby="sk-doporucene">
  <header class="skupina-hlavicka"><h2 id="sk-doporucene">Doporučené fotografie</h2><p class="slabe">Náhodný výběr z fotek označených jako <strong>doporučená</strong>, při každém načtení jiný.</p></header>
  <p class="doporucene-souhrn"><?= Ui::stav($doporucenych['verejne'] > 0 ? 'bezi' : 'ceka', 'Na webu: ' . $doporucenych['verejne']) ?>
    <?php if ($mimoWeb): ?><?= Ui::stav('ceka', 'Neukáže se: ' . count($mimoWeb)) ?><?php endif; ?>
    <a href="<?= Ui::h(Ui::odkaz('galerie', ['doporucene' => 1])) ?>">Zobrazit v Galerii</a></p>
  <?php if ($mimoWeb): ?>
  <details class="doporucene-mimo-web">
    <summary>Proč se <?= count($mimoWeb) ?> doporučených neukáže</summary>
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
  <div class="radek radek-2">
    <?= Ui::vyber('Sloupce', 'doporucene_sloupce', $rozsah(Web::SLOUPCE), (string)$n['doporucene_sloupce']) ?>
    <?= Ui::vyber('Řádky', 'doporucene_radky', $rozsah(Web::RADKY), (string)$n['doporucene_radky']) ?>
  </div>
  <small class="slabe mala">Najednou nejvýš sloupce × řádky fotek, na mobilu se sloupce zúží (až na 2). Když doporučených nestačí, poslední řádek bude neúplný.</small>
</section>

<section class="karta formular web-skupina web-skupina-siroka" aria-labelledby="sk-o-galerii">
  <header class="skupina-hlavicka"><h2 id="sk-o-galerii">Stránka O galerii</h2><p class="slabe">Odstavce oddělte prázdným řádkem. Jde použít <code>**tučně**</code>, odrážky <code>- </code>, nadpisy <code>## </code> a odkazy <code>[text](https://…)</code>.</p></header>
  <?= Ui::oblast('Text', 'o_galerii', $n['o_galerii'], ['rows' => 8, 'maxlength' => 20000]) ?>
</section>
</div>
<div class="tlacitka ulozit-lista"><button type="submit" class="hlavni">Uložit</button>
  <a class="tlacitko" href="<?= Ui::h($app->url()) ?>/">Zobrazit web</a></div>
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
  <?= $vypinac("reklama_stitek_{$k}", 'Nad bannerem malým písmem zobrazit „Reklama“', Web::reklamaStitek($n, $k)) ?>
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

<?= $panel('css') ?>
<?= $formular('css') ?>
<?php /* 0.8.12: vlastní CSS – web ho vydá jako samostatný soubor (Web::vlastniCssAdresa), načte se za styly webu */ ?>
<div class="karta">
  <?= $vypinac('vlastni_css_zapnuto', 'Použít vlastní CSS na veřejných stránkách', (bool)$n['vlastni_css_zapnuto'],
      'Styly se načtou <strong>za styly webu</strong>, takže je můžete přepsat – barvy, písmo, mezery, vzhled reklamních ploch (<code>.reklama</code>, <code>.reklama-stitek</code>)…'
      . ' Platí pro hlavní stránku, galerie, O galerii i zásady; <strong>přihlášení a administrace zůstávají beze změny</strong>, takže se k nastavení vždycky dostanete.'
      . ' Vypínačem styly dočasně vypnete, aniž byste je smazali.') ?>
</div>
<div class="karta formular">
  <h2 style="margin:0">Vlastní CSS</h2>
  <p class="slabe" style="margin:0">Jen CSS, bez značek <code>&lt;style&gt;</code>. Web ho vydá jako samostatný soubor
    <?php if (($cssAdresa = Web::vlastniCssAdresa($n)) !== ''): ?>(<a href="<?= Ui::h($app->url($cssAdresa)) ?>"><code>vlastni.css</code></a>)<?php else: ?><code>vlastni.css</code><?php endif; ?>,
    proto nic z něj nemůže změnit HTML stránky ani spustit skript. Proměnné vzhledu webu: <code>--krem</code>, <code>--plocha</code>, <code>--text</code>, <code>--slaby</code>, <code>--okraj</code>…</p>
  <textarea name="vlastni_css" id="f-vlastni_css" class="kod" rows="16" spellcheck="false" maxlength="<?= Web::MAX_CSS ?>" aria-label="Vlastní CSS"
    placeholder=".reklama-stitek { color: #999; }&#10;.hlavicka { background: #fff; }"><?= Ui::h($post && $karta === 'css' ? (string)($_POST['vlastni_css'] ?? '') : $n['vlastni_css']) ?></textarea>
</div>
<div class="tlacitka"><button type="submit" class="hlavni">Uložit</button>
  <a class="tlacitko" href="<?= Ui::h($app->url()) ?>/">Zobrazit web</a></div>
</form>
</div>
