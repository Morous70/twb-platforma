<?php
declare(strict_types=1);
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/layout.php';
require_login();
layout_head('Hromadné plánování', 'hromadne.php');
?>
        <h1 class="h3 mb-3">Hromadné plánování</h1>
        <p class="text-muted">Vložte tabulku (zkopírovanou z Excelu nebo Google Tabulek) nebo nahrajte CSV. Každý řádek bude jeden příspěvek. Před vytvořením uvidíte kontrolu všech řádků.</p>

        <div class="card">
          <div class="card-header"><h5 class="card-title mb-0">1. Data</h5></div>
          <div class="card-body">
            <textarea id="data" class="form-control font-monospace small" rows="8" placeholder="Text&#9;Datum a čas&#9;Účty nebo skupina&#9;Odkaz&#9;Štítky&#9;První komentář&#9;Obrázky&#10;Nová kolekce je tady!&#9;1.12.2026 10:00&#9;Hlavní účty&#9;https://eshop.cz/p/1&#9;novinka eshop&#9;&#9;https://eshop.cz/img/1.jpg"></textarea>
            <div class="d-flex flex-wrap gap-2 mt-2 align-items-center">
              <input type="file" id="file" accept=".csv,.tsv,.txt" class="form-control form-control-sm w-auto">
              <button class="btn btn-outline-secondary btn-sm" id="sample" type="button">Stáhnout vzor CSV</button>
              <button class="btn btn-outline-secondary btn-sm" id="fillSample" type="button">Vložit příklad</button>
            </div>
            <details class="mt-3"><summary>Jaké sloupce jsou podporované</summary>
              <div class="small mt-2">První řádek musí být hlavička. Pořadí sloupců je libovolné, rozpoznají se tyto názvy:
                <ul class="mb-0">
                  <li><code>Text</code> (nebo Příspěvek, Zpráva), povinné, nebo obrázek či odkaz</li>
                  <li><code>Datum a čas</code> (nebo Datum, Kdy), např. <code>1.12.2026 10:00</code>, <code>2026-12-01 10:00</code></li>
                  <li><code>Účty</code> (nebo Skupina): názvy účtů nebo skupin oddělené čárkou nebo <code>|</code>. Prázdné = výchozí volba níže</li>
                  <li><code>Odkaz</code>, <code>Štítky</code> (nebo Hashtagy), <code>Sada</code> (název uložené sady hashtagů), <code>První komentář</code></li>
                  <li><code>Obrázky</code>: adresy URL nebo názvy souborů z knihovny médií, oddělené <code>|</code> nebo mezerou (max. 10)</li>
                </ul></div>
            </details>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><h5 class="card-title mb-0">2. Nastavení</h5></div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-5"><label class="form-label" for="defAcc">Výchozí účty (pro řádky bez účtů)</label><select id="defAcc" class="form-select"></select></div>
              <div class="col-md-7"><label class="form-label d-block">Co vytvořit</label>
                <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="mode" id="mSched" value="sched" checked><label class="form-check-label" for="mSched">Naplánované příspěvky</label></div>
                <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="mode" id="mDraft" value="draft"><label class="form-check-label" for="mDraft">Jen koncepty (ke kontrole)</label></div>
              </div>
            </div>
            <button class="btn btn-primary mt-3" id="check" type="button">Zkontrolovat</button>
          </div>
        </div>

        <div class="card" id="prevCard" hidden>
          <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"><h5 class="card-title mb-0">3. Kontrola</h5><div id="sum" class="small"></div></div>
          <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>#</th><th>Text</th><th>Termín</th><th>Účty</th><th>Obrázky</th><th>Stav</th></tr></thead><tbody id="rows"></tbody></table></div>
          <div class="card-footer d-flex gap-2 align-items-center flex-wrap"><button class="btn btn-success" id="go" type="button">Vytvořit</button><span class="small text-muted">Řádky s chybou se přeskočí. Můžete je opravit v datech a spustit kontrolu znovu (už vytvořené řádky z textu odstraňte).</span></div>
        </div>
<?php layout_foot(['hromadne.js']);
