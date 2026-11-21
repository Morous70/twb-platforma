<?php
declare(strict_types=1);
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/layout.php';
require_login();
layout_head('Knihovna médií', 'knihovna.php');
?>
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
          <h1 class="h3 mb-0">Knihovna médií</h1>
          <div class="d-flex gap-2 align-items-center">
            <span class="text-muted small" id="libInfo"></span>
            <input type="file" id="libFile" accept="image/*,video/mp4,video/quicktime" multiple hidden>
            <a class="btn btn-outline-primary" href="slide.php" id="slideNew" title="Obrázek s textem, daty a počasím, který se před zveřejněním sám aktualizuje"><i class="align-middle me-1" data-feather="layout"></i> Nový slide</a>
            <button class="btn btn-outline-primary" id="libWeb" title="Wikimedia Commons, Openverse, Pixabay, Pexels – obrázek se stáhne i s autorem a licencí"><i class="align-middle me-1" data-feather="globe"></i> Z internetu</button>
            <button class="btn btn-primary" id="libUpload"><i class="align-middle me-1" data-feather="upload-cloud"></i> Nahrát soubory</button>
          </div>
        </div>
        <div class="card">
          <div class="card-body pb-2">
            <div class="row g-2 mb-2">
              <div class="col-md-6"><input type="search" id="q" class="form-control" placeholder="Hledat podle názvu nebo tagu…" autocomplete="off"></div>
              <div class="col-6 col-md-3"><select id="typeF" class="form-select"><option value="">Obrázky i videa</option><option value="image">Jen obrázky</option><option value="video">Jen videa</option></select></div>
              <div class="col-6 col-md-3 text-end"><button class="btn btn-outline-secondary w-100" id="tagManage" type="button">🏷 Správa tagů</button></div>
            </div>
            <div class="tag-bar mb-2" id="tagBar"></div>
            <div class="alert alert-secondary py-2 px-3 mb-2 small" id="archNote" hidden>
              <strong>Archiv:</strong> soubory tady zůstávají v příspěvcích a dál se publikují, jen se nenabízejí ve výběru z knihovny.
              Vrátit je můžete tlačítkem <em>Vrátit z archivu</em>.
            </div>
            <div class="lib-selbar" id="selBar" hidden>
              <strong id="selN"></strong>
              <button class="btn btn-sm btn-outline-primary" id="selTags">🏷 Tagy…</button>
              <button class="btn btn-sm btn-outline-secondary" id="selArch">🗄 Do archivu</button>
              <button class="btn btn-sm btn-outline-danger" id="selDel">Smazat</button>
              <button class="btn btn-sm btn-link" id="selAll">Vybrat vše zobrazené</button>
              <button class="btn btn-sm btn-link" id="selClear">Zrušit výběr</button>
            </div>
          </div>
          <div class="card-body pt-0">
            <div class="lib-grid" id="grid"><div class="text-muted">Načítám…</div></div>
            <div class="lib-drop mt-3" id="libDrop">Soubory můžete také přetáhnout sem. Obrázky se převedou na JPEG (nejvýše 2 048 px), videa zůstávají beze změny.
              Nové soubory dostanou tag, který je právě vybraný. Na počítači přetáhnete kartu (nebo vybrané karty) na tag v řadě nahoře a tag se přidá.</div>
          </div>
        </div>
<?php layout_foot(['tags.js', 'stock.js', 'knihovna.js']);
