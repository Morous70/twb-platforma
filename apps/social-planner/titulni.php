<?php
declare(strict_types=1);
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/layout.php';
require_login();
layout_head('Titulní stránka', 'titulni.php');
$slotInfo = [
    ['Reklamní plocha 1', 'Světlý pruh pod úvodem stránky (na šířku obsahu, cca 1200 px). Vhodný je široký banner, např. 970×90 nebo 728×90, případně responzivní jednotka.'],
    ['Reklamní plocha 2', 'Pruh pod třemi kartami funkcí (stejná šířka). Vhodný je široký banner nebo responzivní jednotka.'],
    ['Reklamní plocha 3', 'Pruh pod sekcí „Jak to funguje“ (stejná šířka). Vhodný je široký banner nebo responzivní jednotka.'],
    ['Reklamní plocha 4', 'Pruh na konci stránky, těsně nad patičkou (stejná šířka). Vhodný je široký banner nebo responzivní jednotka.'],
];
?>
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
          <h1 class="h3 mb-0">Titulní stránka</h1>
          <a class="btn btn-outline-secondary btn-sm" href="index.php" target="_blank" rel="noopener"><i class="align-middle me-1" data-feather="external-link"></i> Otevřít titulní stránku</a>
        </div>
        <form id="form" novalidate>
          <div class="row">
            <div class="col-xl-8">
<?php foreach ($slotInfo as $i => [$title, $hint]): ?>
              <div class="card">
                <div class="card-header"><h5 class="card-title mb-0"><?= h($title) ?></h5><div class="small text-muted mt-1"><?= h($hint) ?></div></div>
                <div class="card-body">
                  <div class="mb-3">
                    <label class="form-label">Co zobrazit</label>
                    <div class="btn-group w-100 flex-wrap" role="group">
                      <input type="radio" class="btn-check" name="slot<?= $i ?>_mode" id="s<?= $i ?>code" value="code"><label class="btn btn-outline-primary" for="s<?= $i ?>code">Reklamní kód</label>
                      <input type="radio" class="btn-check" name="slot<?= $i ?>_mode" id="s<?= $i ?>demo" value="demo"><label class="btn btn-outline-primary" for="s<?= $i ?>demo">Ukázková plocha</label>
                      <input type="radio" class="btn-check" name="slot<?= $i ?>_mode" id="s<?= $i ?>off" value="off"><label class="btn btn-outline-primary" for="s<?= $i ?>off">Skrýt</label>
                    </div>
                    <div class="form-text" data-mode-hint="<?= $i ?>"></div>
                  </div>
                  <div data-code-wrap="<?= $i ?>">
                    <div class="mb-3">
                      <label class="form-label" for="slot<?= $i ?>_code">Reklamní kód</label>
                      <textarea class="form-control code-area" id="slot<?= $i ?>_code" name="slot<?= $i ?>_code" rows="7" spellcheck="false" placeholder="&lt;ins class=&quot;adsbygoogle&quot; …&gt;&lt;/ins&gt;&#10;&lt;script&gt;(adsbygoogle = window.adsbygoogle || []).push({});&lt;/script&gt;"></textarea>
                      <div class="form-text">Vložte kód přesně tak, jak ho dává reklamní síť (Google AdSense, Sklik, vlastní banner &lt;a&gt;&lt;img&gt;…). Vloží se do stránky beze změny, včetně skriptů.</div>
                    </div>
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" id="slot<?= $i ?>_label" name="slot<?= $i ?>_label" value="1"><label class="form-check-label" for="slot<?= $i ?>_label">Nad reklamu přidat popisek „Reklama“</label>
                    </div>
                  </div>
                </div>
              </div>
<?php endforeach; ?>
              <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Kód do hlavičky stránky</h5><div class="small text-muted mt-1">Vloží se do &lt;head&gt; titulní stránky. Některé sítě ho vyžadují, např. načítací skript AdSense nebo ověřovací značku.</div></div>
                <div class="card-body">
                  <label class="form-label" for="head_code">Kód do &lt;head&gt;</label>
                  <textarea class="form-control code-area" id="head_code" name="head_code" rows="4" spellcheck="false" placeholder="&lt;script async src=&quot;https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-…&quot; crossorigin=&quot;anonymous&quot;&gt;&lt;/script&gt;"></textarea>
                  <div class="form-text">Nepovinné. Nechte prázdné, pokud ho reklamní síť nechce.</div>
                </div>
              </div>
            </div>

            <div class="col-xl-4">
              <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Obecné</h5></div>
                <div class="card-body">
                  <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="enabled" name="enabled" value="1"><label class="form-check-label" for="enabled">Zobrazovat titulní stránku</label>
                    <div class="form-text">Vypnuto: adresa webu rovnou otevře přihlášení.</div>
                  </div>
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="indexable" name="indexable" value="1"><label class="form-check-label" for="indexable">Povolit vyhledávačům indexovat</label>
                    <div class="form-text">Vypnuto: stránka dostane značku <code>noindex</code>. Administrace a přihlášení se neindexují nikdy.</div>
                  </div>
                </div>
              </div>
              <div class="card">
                <div class="card-body">
                  <button type="submit" class="btn btn-primary w-100" id="btnSave">Uložit nastavení</button>
                  <div class="small text-muted mt-3">Reklamní kód se vkládá do stránky tak, jak je, a spouští se v prohlížeči návštěvníků. Vkládejte jen kód z důvěryhodného zdroje. Na hostingu může reklamní síť vyžadovat i soubor <code>ads.txt</code> v kořeni webu, ten nahrajte přes FTP.</div>
                </div>
              </div>
            </div>
          </div>
        </form>
<?php layout_foot(['titulni.js']);
