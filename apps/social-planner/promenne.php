<?php
declare(strict_types=1);
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/layout.php';
require_login();
layout_head('Proměnné a data', 'promenne.php');
?>
        <h1 class="h3 mb-2">Proměnné a data</h1>
        <p class="text-muted">Do textu příspěvku vložíte proměnnou jako <code>{{nazev}}</code> (v Novém příspěvku tlačítkem <em>＋ Další</em>). Dosadí se až při zveřejnění –
          podle jeho data a času a s čerstvými daty ze zdrojů. Hodí se hlavně u opakovaných a předem naplánovaných příspěvků.</p>
        <ul class="nav nav-tabs mb-3" id="pdTabs" role="tablist">
          <li class="nav-item"><a class="nav-link active" href="#promenne" data-bs-toggle="tab" data-bs-target="#tabVars" role="tab">Proměnné</a></li>
          <li class="nav-item"><a class="nav-link" href="#data" data-bs-toggle="tab" data-bs-target="#tabData" role="tab">Tabulka dat</a></li>
          <li class="nav-item"><a class="nav-link" href="#zdroje" data-bs-toggle="tab" data-bs-target="#tabSrc" role="tab">Vzdálené zdroje</a></li>
          <li class="nav-item"><a class="nav-link" href="#pocasi" data-bs-toggle="tab" data-bs-target="#tabWx" role="tab">Počasí</a></li>
        </ul>
        <div class="tab-content">
        <div class="tab-pane fade show active" id="tabVars" role="tabpanel">
        <div class="row">
          <div class="col-lg-5">
            <div class="card">
              <div class="card-header"><h5 class="card-title mb-0" id="formTitle">Nová proměnná</h5></div>
              <div class="card-body">
                <form id="varForm" novalidate>
                  <input type="hidden" id="vId">
                  <div class="mb-3">
                    <label class="form-label" for="vName">Název</label>
                    <input class="form-control" id="vName" maxlength="31" placeholder="např. tip_tydne" autocomplete="off">
                    <div class="form-text">Písmena bez diakritiky, číslice a podtržítko. V textu: <code>{{<span id="vNamePreview">nazev</span>}}</code></div>
                  </div>
                  <div class="mb-3">
                    <label class="form-label" for="vType">Druh</label>
                    <select class="form-select" id="vType">
                      <option value="text">Pevný text – vždy stejná hodnota</option>
                      <option value="list">Seznam – při každém opakování další hodnota</option>
                    </select>
                  </div>
                  <div class="mb-3">
                    <label class="form-label" for="vValue" id="vValueLabel">Hodnota</label>
                    <textarea class="form-control" id="vValue" rows="6"></textarea>
                    <div class="form-text" id="vValueHelp"></div>
                  </div>
                  <button type="submit" class="btn btn-primary" id="vSave">Uložit</button>
                  <button type="button" class="btn btn-link" id="vReset" hidden>Zrušit úpravu</button>
                </form>
              </div>
            </div>

            <div class="card">
              <div class="card-header"><h5 class="card-title mb-0">Vestavěné proměnné</h5></div>
              <div class="table-responsive"><table class="table my-0"><tbody id="builtin"></tbody></table></div>
            </div>
          </div>

          <div class="col-lg-7">
            <div class="card">
              <div class="card-header"><h5 class="card-title mb-0">Vaše proměnné</h5></div>
              <div class="table-responsive">
                <table class="table table-hover align-middle my-0">
                  <thead><tr><th>Název</th><th>Druh</th><th>Hodnota</th><th class="text-end">Akce</th></tr></thead>
                  <tbody id="rows"><tr><td colspan="4" class="text-center text-muted py-4">Načítám…</td></tr></tbody>
                </table>
              </div>
              <div class="card-footer text-muted small">U seznamu se hodnoty střídají podle pořadí výskytu opakovaného příspěvku: 1. výskyt = 1. řádek, 2. výskyt = 2. řádek … a po posledním se začíná znovu.</div>
            </div>
          </div>
        </div>
        </div>

        <div class="tab-pane fade" id="tabData" role="tabpanel">
          <div class="card">
            <div class="card-body">
              <p class="text-muted small">Hodnoty ve tvaru <b>Skupina | Parametr | Hodnota</b>. Parametr může být datum (např. losování) nebo libovolný text (položka ceníku).
                V textu: <code>{{Sportka.nejbližší|mil}}</code> = hodnota s nejbližším datem od dne zveřejnění, <code>{{Sportka.nejbližší.parametr|datum:den}}</code> její datum,
                <code>{{Sportka.další}}</code>, <code>{{Sportka.poslední}}</code>, <code>{{Sportka:26.11.2026}}</code>, <code>{{Ceník:Káva|kc}}</code>.</p>
              <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                <div class="tag-bar flex-grow-1" id="dGroups"></div>
                <input type="search" class="form-control form-control-sm w-auto" id="dQ" placeholder="Hledat…">
                <button class="btn btn-sm btn-outline-secondary" id="dPast"></button>
                <button class="btn btn-sm btn-outline-primary" id="dImport">Vložit z Excelu</button>
                <button class="btn btn-sm btn-primary" id="dAdd">+ Řádek</button>
              </div>
              <div id="dHint"></div>
              <div class="table-responsive"><table class="table table-sm align-middle dtable mb-0">
                <thead><tr><th>Skupina</th><th>Parametr</th><th>Hodnota</th><th></th></tr></thead><tbody id="dBody"></tbody></table></div>
              <datalist id="dGrpList"></datalist>
            </div>
          </div>
        </div>

        <div class="tab-pane fade" id="tabSrc" role="tabpanel">
          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Vzdálené zdroje</h5><button class="btn btn-sm btn-primary" id="srcAdd">+ Zdroj</button></div>
            <div class="card-body">
              <p class="text-muted small">Data (JSON nebo CSV) stažená z jiné adresy, např. výsledky a jackpoty Allwyn, ceník z tabulky Google. Stahují se automaticky podle intervalu
                (cron) a ještě před zveřejněním příspěvku. Když zdroj nebude dostupný, příspěvky použijí poslední stažené hodnoty a do logu se zapíše jedno varování.
                Hodnotu vložíte v Novém příspěvku tlačítkem <em>＋ Další → Zdroje</em>, zápis vypadá např. <code>{{Allwyn:sportka.superjackpot|mil}}</code>.</p>
              <div id="srcList" class="text-muted">Načítám…</div>
            </div>
          </div>
        </div>

        <div class="tab-pane fade" id="tabWx" role="tabpanel">
          <div class="row">
            <div class="col-lg-6"><div class="card"><div class="card-header"><h5 class="card-title mb-0">Nastavení počasí (OpenWeather)</h5></div><div class="card-body" id="wxForm">Načítám…</div></div></div>
            <div class="col-lg-6"><div class="card"><div class="card-header"><h5 class="card-title mb-0">Proměnné</h5></div><div class="card-body small">
              <table class="table table-sm mb-2"><tbody>
                <tr><td><code>{{Počasí:teplota}}</code></td><td>teplota ve výchozím místě (také <code>pocitová</code>, <code>popis</code>, <code>vlhkost</code>, <code>vítr</code>, <code>místo</code>)</td></tr>
                <tr><td><code>{{Počasí:zítra.max}}</code></td><td>nejvyšší teplota zítra (<code>dnes</code>, <code>zítra</code>, <code>pozítří</code>; <code>.max</code>, <code>.min</code>, <code>.popis</code>, <code>.den</code>)</td></tr>
                <tr><td><code>{{Počasí:Brno.teplota}}</code></td><td>počasí pro jiné místo (název; souřadnice jen jako výchozí místo)</td></tr>
              </tbody></table>
              <p class="text-muted mb-0">„Dnes“ a „zítra“ se počítají od dne zveřejnění. Předpověď sahá asi 5 dní dopředu – u příspěvku naplánovaného dál se hodnota
                doplní, až bude předpověď k dispozici (data se stahují i těsně před zveřejněním).</p>
            </div></div></div>
          </div>
        </div>
        </div>
<?php layout_foot(['varpick.js', 'promenne.js', 'promenne-data.js']);
