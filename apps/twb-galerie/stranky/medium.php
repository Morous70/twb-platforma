<?php
declare(strict_types=1);
// Detail média: náhled, údaje, název, poznámka, kategorie, tagy, veřejný odkaz, nahrazení souboru, komprese, úprava obrázku (0.8.13),
// ohnisko a zobrazení na webu (0.8.15), archiv, smazání.
// Proměnné: $app, $ui, $post.
use MediaGalerie\{App, Ui, Chyba, Media, Vstup};
use TWB\Licence\Licence;
use TWB\Zalohy\Zalohy;
use TWB\Obrazky\Obrazky;

$galerie = $app->galerie();
$media = $app->media();
$id = (int)($_GET['id'] ?? 0);
$m = $media->radek($id);
$odkaz = Ui::odkaz('medium', ['id' => $id]);

if ($post) {
    $akce = (string)($_POST['akce'] ?? '');
    $zprava = 'Uloženo.';
    $kam = $odkaz;
    if ($ui->zpracuj(function () use ($akce, $app, $galerie, $media, $id, $m, &$zprava, &$kam) {
        switch ($akce) {
            case 'ulozit':
                // nejdřív kontrola všech polí, pak zápis najednou (chyba v tagu nesmí uložit půlku formuláře)
                $nazev = Vstup::text($_POST, 'nazev', 'Název', 200);
                $pozn = Vstup::text($_POST, 'note', 'Poznámka', 5000, false);
                $k = (string)($_POST['kategorie'] ?? '');
                $kategorie = $k === '' ? null : (int)$k;
                if ($kategorie !== null) $app->kategorie()->radek($kategorie);
                $tagy = $galerie->tagy()->vycistiSeznam((string)($_POST['tagy'] ?? ''));
                $autor = Vstup::text($_POST, 'author', 'Autor', 200, false);
                $licence = Vstup::text($_POST, 'license', 'Licence', 2000, false);
                $licenceUrl = Vstup::text($_POST, 'license_url', 'Odkaz na licenci', 500, false);
                if ($licenceUrl !== null && !preg_match('~^https?://\S+$~i', $licenceUrl)) throw new Chyba('Odkaz na licenci musí začínat http:// nebo https://.');
                try { [$kod, $verze] = Licence::vycisti((string)($_POST['license_code'] ?? ''), (string)($_POST['license_version'] ?? '')); }
                catch (InvalidArgumentException $e) { throw new Chyba($e->getMessage()); }
                $app->db->transakce(function () use ($galerie, $media, $id, $m, $nazev, $pozn, $kategorie, $tagy, $autor, $licence, $licenceUrl, $kod, $verze) {
                    if ($nazev !== $m['original_name']) $galerie->prejmenuj($id, $nazev);
                    $media->nastavPoznamku($id, $pozn);
                    $media->nastavAutora($id, $autor, $licence, $licenceUrl);
                    $media->nastavLicenci($id, $kod, $verze);
                    $media->nastavKategorii([$id], $kategorie);
                    $galerie->tagy()->nastav($id, $tagy);
                });
                break;
            case 'odkaz-novy':
                $media->novyOdkaz($id);
                $zprava = 'Vytvořen nový veřejný odkaz. Předchozí odkaz už nefunguje.';
                break;
            case 'odkaz-zrusit':
                $media->zrusOdkaz($id);
                $zprava = 'Veřejný odkaz je vypnutý.';
                break;
            case 'doporucit':
            case 'nedoporucit':
                $media->nastavDoporucene([$id], $akce === 'doporucit');
                $zprava = $akce === 'doporucit' ? 'Fotografie je doporučená.' : 'Doporučení zrušeno.';
                break;
            case 'web-uvodni':
                // 0.8.11: úvodních fotek je až pět – tlačítko fotku přidá, nebo (je-li už mezi nimi) odebere
                if ($app->web()->jeUvodni($id)) {
                    $app->web()->odeberUvodni($id);
                    $zprava = 'Fotka už není mezi úvodními fotkami webu.';
                } else {
                    $app->web()->nastavUvodni($id);
                    $zprava = 'Fotka je mezi úvodními fotkami webu (' . count($app->web()->nastaveni()['uvod_ids']) . ' z ' . \MediaGalerie\Web::MAX_UVODNI . ').';
                }
                break;
            case 'komprimovat':
                $z = $galerie->komprimuj($id, true);
                $zprava = $z ? 'Zkomprimováno: ' . $z : 'Obrázek už je dost malý, komprese ho nezmenší.';
                if ($z) $app->log('info', 'Obrázek zkomprimován: ' . $z);
                break;
            case 'upravit':
                // 0.8.13: ořez, otočení, překlopení; původní soubor zůstane (návrat tlačítkem Vrátit původní soubor)
                try { $u = Obrazky::upravaZFormulare($_POST); }
                catch (InvalidArgumentException $e) { throw new Chyba($e->getMessage()); }
                try { $v = $galerie->upravObrazek($id, $u); }
                catch (RuntimeException $e) { throw new Chyba($e->getMessage()); }
                $media->uklidNahledy();
                $zprava = 'Obrázek upraven: ' . $v['popis'] . '. Původní soubor zůstal uložený.';
                if ($media->zrusOhnisko($id)) $zprava .= ' Ohnisko pro web bylo zrušeno (po úpravě by ukazovalo jinam) – nastavte ho znovu.';
                break;
            case 'ohnisko':
                // 0.8.15: bod, kolem kterého se fotka na webu ořízne (procenta), a volba celé fotky s rozmazaným okolím
                $sou = [];
                foreach (['focus_x' => 'vodorovně', 'focus_y' => 'svisle'] as $pole => $popis) {
                    $t = trim((string)($_POST[$pole] ?? ''));
                    if ($t !== '' && !preg_match('~^\d{1,3}([.,]\d+)?$~', $t)) throw new Chyba("Ohnisko {$popis}: zadejte číslo 0–100.");
                    $sou[] = $t === '' ? null : (int)round((float)str_replace(',', '.', $t));
                }
                $cela = !empty($_POST['cela']);
                $media->nastavOhnisko($id, $sou[0], $sou[1], $cela);
                $zprava = $cela ? 'Uloženo: na webu se ukáže celá fotka s rozmazaným okolím.'
                    : ($sou[0] !== null ? "Ohnisko uloženo ({$sou[0]} % zleva, {$sou[1]} % shora). Web fotku ořízne kolem něj." : 'Ohnisko zrušeno, web fotku ořízne od středu.');
                $kam .= '#ohnisko';
                break;
            case 'ohnisko-zrusit':
                $media->nastavOhnisko($id, null, null, false);
                $zprava = 'Ohnisko zrušeno, web fotku ořízne od středu.';
                $kam .= '#ohnisko';
                break;
            case 'original':
                if (!$galerie->obnovPuvodni($id)) throw new Chyba('Původní soubor není k dispozici.');
                $app->log('info', "Obnoven původní soubor: {$m['original_name']}");
                $media->uklidNahledy();
                $zprava = 'Vrácen původní soubor.';
                if ($m['edits'] && $media->zrusOhnisko($id)) $zprava .= ' Ohnisko pro web bylo zrušeno – nastavte ho znovu.';
                break;
            case 'archivovat':
            case 'vratit':
                $galerie->archivuj([$id], $akce === 'archivovat');
                $zprava = $akce === 'archivovat' ? 'Přesunuto do archivu. Veřejný odkaz dál funguje.' : 'Vráceno z archivu.';
                break;
            case 'smazat':
                $galerie->smaz([$id]);
                $media->uklidNahledy();
                $zprava = "Smazáno: {$m['original_name']}.";
                $kam = Ui::odkaz('galerie');
                break;
            default:
                throw new Chyba('Neznámá akce.');
        }
    })) {
        $ui->zprava('ok', $zprava);
        $ui->presmeruj($kam);
    }
}

$titulek = (string)$m['original_name'];
$url = $media->verejnaAdresa($m);
$kategorie = $app->kategorie()->volby();
$tagy = $galerie->tagy()->vsechny();
$src = 'media.php?id=' . $id . '&v=' . substr((string)$m['md5'], 0, 8);
// úprava obrázku: ne u animovaného GIFu (přišel by o animaci)
$lzeUpravit = $m['kind'] === 'image' && !($m['mime'] === 'image/gif' && Obrazky::jeAnimovanyGif($app->data . '/media/' . basename((string)$m['filename'])));
?>
<p><a href="<?= Ui::h(Ui::odkaz('galerie', $m['archived_at'] ? ['archiv' => 1] : [])) ?>">← <?= $m['archived_at'] ? 'Archiv' : 'Galerie' ?></a>
  <?php if ($m['archived_at']): ?> <?= Ui::stav('paused', 'v archivu') ?><?php endif; ?></p>
<div class="dva-sloupce">
<section>
  <div class="nahled nahled-detail" data-okno-skupina<?= Ui::okno($m, $url, null, false) ?>>
    <?php if ($m['kind'] === 'image'): ?><a href="<?= Ui::h($src) ?>" data-otevrit-okno title="Zvětšit"><img src="<?= Ui::h($src) ?>" alt=""></a>
    <?php elseif ($m['kind'] === 'video'): ?><video src="<?= Ui::h($src) ?>" controls preload="metadata" playsinline></video>
    <?php else: ?><iframe src="<?= Ui::h($src) ?>" title="PDF"></iframe><p class="mala"><a href="<?= Ui::h($src) ?>" data-otevrit-okno>Zvětšit PDF</a></p><?php endif; ?>
  </div>
  <div class="karta" style="margin-top:1.5rem">
    <dl class="udaje">
      <dt>Druh</dt><dd><?= Ui::h(Media::DRUHY[$m['kind']] ?? $m['kind']) ?> (<?= Ui::h($m['mime']) ?>)</dd>
      <?php if ($m['width']): ?><dt>Rozměr</dt><dd><?= (int)$m['width'] ?>×<?= (int)$m['height'] ?> px</dd><?php endif; ?>
      <dt>Velikost</dt><dd><?= Ui::h(Zalohy::velikost((int)$m['size'])) ?></dd>
      <?php if ($m['orig_filename']): ?><dt>Originál</dt><dd><?= (int)$m['orig_w'] ?>×<?= (int)$m['orig_h'] ?> px, <?= Ui::h(Zalohy::velikost((int)$m['orig_size'])) ?>
        · <span data-okno-skupina<?= Ui::okno($m, null, $src . '&original=1', false, (int)$m['orig_w'] . '×' . (int)$m['orig_h'] . ' · originál před ' . ($m['edits'] ? 'úpravou' : 'kompresí')) ?>><a href="<?= Ui::h($src . '&original=1') ?>" data-otevrit-okno>zobrazit</a></span></dd><?php endif; ?>
      <?php if ($m['edits']): ?><dt>Upraveno</dt><dd><?= Ui::h((string)$m['edits']) ?></dd><?php endif; ?>
      <?php if ($m['kind'] === 'image' && ($m['show_whole'] || $m['focus_x'] !== null)): $oh = Media::ohnisko($m); ?><dt>Na webu</dt><dd><?= $m['show_whole'] ? 'celá fotka s rozmazaným okolím' : 'ořez kolem ohniska ' . $oh['x'] . ' % zleva, ' . $oh['y'] . ' % shora' ?></dd><?php endif; ?>
      <dt>Nahráno</dt><dd><?= Ui::h(Ui::datum(strtotime((string)$m['created_at']))) ?><?= $m['upload_name'] && $m['upload_name'] !== $m['original_name'] ? ' jako ' . Ui::h($m['upload_name']) : '' ?></dd>
      <?php if ($m['author']): ?><dt>Autor</dt><dd><?= Ui::h($m['author']) ?></dd><?php endif; ?>
      <?php $licOdkaz = Licence::odkaz($m['license_code'], $m['license_version'], $m['license_url']); $licNazev = Licence::nazev($m['license_code'], $m['license_version']); ?>
      <?php if ($licNazev || $m['license'] || $licOdkaz): ?><dt>Licence</dt><dd><?= Ui::h(implode(' · ', array_filter([$licNazev, $m['license'] !== $licNazev ? (string)$m['license'] : '']))) ?>
        <?php if ($licOdkaz): ?><?= $licNazev || $m['license'] ? ' · ' : '' ?><a href="<?= Ui::h($licOdkaz) ?>" target="_blank" rel="noopener noreferrer">podmínky licence</a><?php endif; ?></dd><?php endif; ?>
      <?php $uv = Media::uvedeni($m); if ($uv['text'] !== ''): ?><dt>Uvedení autora</dt><dd><?= Ui::h($uv['text']) ?><?= $uv['povinne'] ? ' <span class="slabe mala">(licence ho vyžaduje)</span>' : '' ?></dd><?php endif; ?>
      <dt>Kontrolní součet</dt><dd class="mala"><code>MD5 <?= Ui::h($m['md5']) ?></code></dd>
    </dl>
    <div class="tlacitka" style="margin-top:1rem">
      <a class="tlacitko maly" href="<?= Ui::h($src . '&stahnout=1') ?>">Stáhnout</a>
      <?php if ($m['kind'] === 'image' && !$m['orig_filename']): ?><?= $ui->tlacitko('Zkomprimovat', 'komprimovat', [], 'maly') ?><?php endif; ?>
      <?php if ($lzeUpravit): ?><a class="tlacitko maly" href="#uprava" data-otevrit-upravu>Upravit obrázek</a><?php endif; ?>
      <?php if ($m['kind'] === 'image'): ?><a class="tlacitko maly" href="#ohnisko" data-otevrit-ohnisko>Ohnisko na webu</a><?php endif; ?>
      <?php if ($m['orig_filename']): ?><?= $ui->tlacitko('Vrátit původní soubor', 'original', [], 'maly', $m['edits'] ? 'Vrátit původní soubor? Úpravy (ořez, otočení) i komprese se zahodí.' : 'Vrátit původní (nekomprimovaný) soubor?') ?><?php endif; ?>
      <?php if ($m['kind'] === 'image' && $m['public_key'] && !$m['archived_at']): ?><?= $app->web()->jeUvodni((int)$m['id']) ? $ui->tlacitko('Odebrat z úvodních fotek', 'web-uvodni', [], 'maly') : $ui->tlacitko('Přidat mezi úvodní fotky', 'web-uvodni', [], 'maly') ?><?php endif; ?>
      <?php if ($m['kind'] === 'image' || $m['featured']): ?><?= $m['featured'] ? $ui->tlacitko('★ Doporučená – zrušit', 'nedoporucit', [], 'maly') : $ui->tlacitko('☆ Označit jako doporučenou', 'doporucit', [], 'maly') ?><?php endif; ?>
    </div>
    <?php if ($m['featured'] && ($proc = $app->web()->procNeniNaWebu($m)) !== ''): ?><p class="slabe mala" style="margin:.5rem 0 0">Doporučená fotka se na webu zatím neukáže: <?= Ui::h($proc) ?></p><?php endif; ?>
  </div>
  <?php if ($lzeUpravit): ?>
  <details class="karta uprava" id="uprava" data-uprava data-src="<?= Ui::h($src) ?>">
    <summary><h2>Upravit obrázek</h2></summary>
    <p class="slabe mala">Ořez, otočení a překlopení. Výřez nakreslete nebo posuňte myší (prstem), velikost měňte za rohy.
      <?= $m['orig_filename'] ? 'Původní soubor zůstává uložený' : 'Dosavadní soubor se uloží jako původní' ?> – úpravu vrátíte tlačítkem <em>Vrátit původní soubor</em>.</p>
    <div class="tlacitka" role="group" aria-label="Otočení a překlopení">
      <button type="button" class="maly" data-uprava-akce="vlevo" title="Otočit o 90° doleva">⟲ Doleva</button>
      <button type="button" class="maly" data-uprava-akce="vpravo" title="Otočit o 90° doprava">⟳ Doprava</button>
      <button type="button" class="maly" data-uprava-akce="vodorovne" title="Překlopit vodorovně (zrcadlově)">⇋ Překlopit vodorovně</button>
      <button type="button" class="maly" data-uprava-akce="svisle" title="Překlopit svisle (vzhůru nohama)">⇅ Překlopit svisle</button>
    </div>
    <div class="tlacitka uprava-pomery" role="group" aria-label="Poměr stran ořezu">
      <span class="mala">Poměr stran:</span>
      <button type="button" class="maly" data-pomer="" aria-pressed="true">Volný</button>
      <?php foreach (array_keys(Obrazky::POMERY) as $p): ?><button type="button" class="maly" data-pomer="<?= Ui::h($p) ?>" aria-pressed="false"><?= Ui::h($p) ?></button><?php endforeach; ?>
      <label class="mala"><input type="checkbox" data-prohodit> Prohodit (na výšku, např. 9:16)</label>
    </div>
    <div class="uprava-plocha" data-plocha-upravy>
      <canvas data-platno aria-label="Náhled úpravy"></canvas>
      <div class="uprava-vyrez" data-vyrez tabindex="0" aria-label="Výřez – šipkami posunete" hidden>
        <?php foreach (['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'] as $u): ?><span class="uchyt uchyt-<?= $u ?>" data-uchyt="<?= $u ?>"></span><?php endforeach; ?>
      </div>
    </div>
    <p class="mala" data-uprava-info aria-live="polite"></p>
    <form method="post" class="tlacitka" data-uprava-formular>
      <?= $ui->csrf() ?>
      <input type="hidden" name="akce" value="upravit">
      <?php foreach (['otoceni', 'preklopit_h', 'preklopit_v', 'orez_x', 'orez_y', 'orez_w', 'orez_h', 'pomer'] as $n): ?><input type="hidden" name="<?= $n ?>" value=""><?php endforeach; ?>
      <button type="submit" class="hlavni" data-uprava-ulozit disabled>Uložit úpravu</button>
      <button type="button" class="maly" data-uprava-zrusit-vyrez>Zrušit výřez</button>
      <button type="button" class="maly" data-uprava-reset>Začít znovu</button>
    </form>
    <noscript><p class="hlaska hlaska-chyba">Úprava obrázku potřebuje zapnutý JavaScript.</p></noscript>
  </details>
  <?php endif; ?>
  <?php if ($m['kind'] === 'image'): $oh = Media::ohnisko($m); $x = $oh['x'] ?? null; $y = $oh['y'] ?? null; $cela = (bool)$m['show_whole']; ?>
  <details class="karta uprava ohnisko-karta" id="ohnisko" data-ohnisko>
    <summary><h2>Ohnisko a zobrazení na webu</h2></summary>
    <p class="slabe mala">Klikněte do fotky na místo, které musí zůstat vidět (třeba obličej). Úvodní fotka, mřížky fotek a karty galerií
      na veřejném webu se pak oříznou kolem něj. Když nejde nic useknout (panorama), zapněte <em>Zobrazit celou fotku</em>. Soubor se nemění.</p>
    <div class="ohnisko-plocha" data-ohnisko-plocha>
      <img src="<?= Ui::h($src) ?>" alt="Fotka – kliknutím zvolíte ohnisko" loading="lazy" draggable="false">
      <span class="ohnisko-bod" data-ohnisko-bod<?= $oh ? ' style="left:' . $x . '%;top:' . $y . '%"' : ' hidden' ?>></span>
    </div>
    <p class="slabe mala">Náhled na webu<span data-ohnisko-bez<?= $oh || $cela ? ' hidden' : '' ?>> (zatím bez ohniska – ořez od středu)</span>:</p>
    <div class="ohnisko-nahledy<?= $cela ? ' cela' : '' ?>" data-ohnisko-nahledy>
      <?php foreach ([['pc', 'Úvodní fotka – počítač', 60], ['mobil', 'Úvodní fotka – mobil', 60], ['mrizka', 'Mřížka fotek', 50], ['karta', 'Karta galerie', 50]] as [$k, $popis, $vy]): ?>
      <figure class="ohnisko-nahled ohnisko-nahled-<?= $k ?>">
        <div class="ohnisko-vyrez"><img class="pozadi" src="<?= Ui::h($src) ?>" alt="" loading="lazy"><img class="popredi" src="<?= Ui::h($src) ?>" alt="" loading="lazy" data-vychozi-y="<?= $vy ?>" style="object-position:<?= $x ?? 50 ?>% <?= $y ?? $vy ?>%"></div>
        <figcaption class="mala slabe"><?= Ui::h($popis) ?></figcaption>
      </figure>
      <?php endforeach; ?>
    </div>
    <form method="post" class="ohnisko-formular" data-ohnisko-formular>
      <?= $ui->csrf() ?>
      <input type="hidden" name="akce" value="ohnisko">
      <div class="tlacitka">
        <?= Ui::pole('Ohnisko zleva (%)', 'focus_x', $x, ['type' => 'number', 'min' => 0, 'max' => 100, 'step' => 1, 'placeholder' => 'střed', 'data-ohnisko-x' => '']) ?>
        <?= Ui::pole('Ohnisko shora (%)', 'focus_y', $y, ['type' => 'number', 'min' => 0, 'max' => 100, 'step' => 1, 'placeholder' => 'střed', 'data-ohnisko-y' => '']) ?>
      </div>
      <label class="mala ohnisko-cela"><input type="checkbox" name="cela" value="1" data-ohnisko-cela<?= $cela ? ' checked' : '' ?>>
        <span><strong>Zobrazit celou fotku</strong> – nic se neusekne, prázdné okolí doplní rozmazaná kopie téže fotky</span></label>
      <div class="tlacitka"><button type="submit" class="hlavni">Uložit</button></div>
    </form>
    <?php if ($oh || $cela): ?><div class="tlacitka"><?= $ui->tlacitko('Zrušit ohnisko', 'ohnisko-zrusit', [], 'maly') ?></div><?php endif; ?>
  </details>
  <?php endif; ?>
</section>

<section>
  <div class="karta">
    <h2 style="margin-top:0">Veřejný odkaz</h2>
    <?php if ($url): ?>
      <div class="tlacitka"><input id="verejny-odkaz" readonly value="<?= Ui::h($url) ?>" style="flex:1"><button type="button" class="maly" data-kopirovat="verejny-odkaz">Kopírovat</button>
        <span data-okno-skupina<?= Ui::okno($m, $url, $url, false, 'veřejný odkaz') ?>><a class="tlacitko maly" href="<?= Ui::h($url) ?>" data-otevrit-okno>Otevřít</a></span></div>
      <p class="slabe mala">Kdo zná odkaz, soubor zobrazí bez přihlášení (vložení do webu, e-mailu, reklamy). Po nahrazení souboru zůstává stejný.</p>
      <div class="tlacitka">
        <?= $ui->tlacitko('Nový odkaz', 'odkaz-novy', [], 'maly', 'Vytvořit nový odkaz? Starý přestane fungovat.') ?>
        <?= $ui->tlacitko('Vypnout odkaz', 'odkaz-zrusit', [], 'maly nebezpecne', 'Vypnout veřejný odkaz? Soubor přestane být dostupný na webech, kde je vložený.') ?>
      </div>
    <?php else: ?>
      <p class="slabe">Veřejný odkaz je vypnutý, soubor je jen v administraci.</p>
      <?= $ui->tlacitko('Zapnout veřejný odkaz', 'odkaz-novy', [], 'maly') ?>
    <?php endif; ?>
  </div>

  <form method="post" class="formular karta">
    <h2 style="margin:0">Údaje</h2>
    <?= $ui->csrf() ?>
    <input type="hidden" name="akce" value="ulozit">
    <?= Ui::pole('Název', 'nazev', $m['original_name'], ['required' => true, 'maxlength' => 200]) ?>
    <?= Ui::vyber('Kategorie', 'kategorie', ['' => '— bez kategorie —'] + $kategorie, (string)($m['category_id'] ?? '')) ?>
    <?= Ui::pole('Tagy', 'tagy', implode(', ', Media::tagy($m)), ['list' => 'seznam-tagu', 'placeholder' => 'Napište tag a stiskněte Enter', 'data-stitky' => ''], 'Nejvýš 10 tagů po 30 znacích.') ?>
    <datalist id="seznam-tagu"><?php foreach ($tagy as $t): ?><option value="<?= Ui::h($t['name']) ?>"><?php endforeach; ?></datalist>
    <?= Ui::oblast('Poznámka', 'note', $m['note'], ['rows' => 3]) ?>
    <?= Ui::pole('Autor', 'author', $m['author'], ['maxlength' => 200, 'placeholder' => 'např. Jan Novák']) ?>
    <?= Ui::vyber('Licence', 'license_code', ['' => '— neuvedena —'] + Licence::KODY, (string)($m['license_code'] ?? ''), ['data-licence' => ''],
        implode(' ', array_map(fn($k, $t) => '<span data-licence-napoveda="' . Ui::h($k) . '" hidden>' . Ui::h($t) . '</span>', array_keys(Licence::NAPOVEDA), Licence::NAPOVEDA))) ?>
    <?= Ui::pole('Verze / název licence', 'license_version', $m['license_version'], ['maxlength' => Licence::MAX_VERZE, 'placeholder' => 'u CC verze, např. 4.0; u Jiné přesný název', 'data-licence-verze' => '']) ?>
    <?= Ui::oblast('Podrobnosti licence', 'license', $m['license'], ['rows' => 2, 'maxlength' => 2000, 'placeholder' => 'např. zakoupeno na fotobance, jen pro web; text z metadat…']) ?>
    <?= Ui::pole('Odkaz na licenci', 'license_url', $m['license_url'], ['type' => 'url', 'maxlength' => 500, 'placeholder' => 'https://…'],
        'U nového obrázku se autor, licence, název a štítky načtou z metadat souboru, pokud v něm jsou.') ?>
    <div><button type="submit" class="hlavni">Uložit</button></div>
  </form>

  <div class="karta nahravani" id="nahravani" data-csrf="<?= Ui::h($app->relace()->csrfToken()) ?>" data-cast="<?= App::CAST_NAHRANI ?>" data-max="<?= App::MAX_NAHRANI ?>" data-nahradit="<?= $id ?>">
    <h2 style="margin-top:0">Nahradit soubor</h2>
    <p class="slabe mala">Nový soubor stejného druhu (<?= Ui::h(Media::DRUHY[$m['kind']] ?? $m['kind']) ?>). Odkaz, kategorie, tagy a poznámka zůstanou.</p>
    <div class="nahravani-plocha" data-plocha>
      <p><strong>Přetáhněte sem soubor</strong> nebo <label class="odkaz-tlacitko">vyberte z počítače<input type="file" hidden data-vyber></label></p>
    </div>
    <ul class="nahravani-seznam" data-seznam></ul>
  </div>

  <div class="karta tlacitka">
    <?php if ($m['archived_at']): ?><?= $ui->tlacitko('Vrátit z archivu', 'vratit', [], 'maly') ?>
    <?php else: ?><?= $ui->tlacitko('Přesunout do archivu', 'archivovat', [], 'maly') ?><?php endif; ?>
    <?= $ui->tlacitko('Smazat', 'smazat', [], 'maly nebezpecne', 'Smazat „' . $m['original_name'] . '“ i se souborem? Veřejný odkaz přestane fungovat.') ?>
  </div>
</section>
</div>
