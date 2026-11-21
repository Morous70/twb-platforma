<?php
declare(strict_types=1);
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/layout.php';
require_login();
layout_head('Účty a propojení', 'ucty.php');
?>
        <h1 class="h3 mb-3">Účty a propojení</h1>

        <div class="card">
          <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <div class="flex-grow-1" style="flex-basis:320px">
              <h5 class="card-title text-dark mb-1">Propojit nebo obnovit účty</h5>
              <div class="text-muted small">Facebook stránky a k nim připojené Instagram účty se propojují přes přihlášení k Metě, Threads zvlášť. Stejné tlačítko použijte i ve chvíli, kdy účtu vyprší oprávnění.</div>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <a class="btn btn-primary" href="connect.php"><i class="align-middle me-1" data-feather="facebook"></i> Facebook a Instagram</a>
              <a class="btn btn-dark" href="threads_connect.php"><i class="align-middle me-1" data-feather="at-sign"></i> Threads</a>
            </div>
          </div>
        </div>

        <p class="text-muted">Zakázané účty se nenabízejí při psaní příspěvku a přeskočí se i u už naplánovaných příspěvků.</p>

        <div class="row">
          <div class="col-6 col-lg-3"><div class="card"><div class="card-body"><h6 class="card-title text-muted mb-2">Účtů celkem</h6><div class="h2 mb-0" id="statTotal">–</div></div></div></div>
          <div class="col-6 col-lg-3"><div class="card"><div class="card-body"><h6 class="card-title text-muted mb-2">Povoleno</h6><div class="h2 mb-0 text-success" id="statOn">–</div></div></div></div>
          <div class="col-6 col-lg-3"><div class="card"><div class="card-body"><h6 class="card-title text-muted mb-2">Facebook stránky</h6><div class="h2 mb-0" id="statFb">–</div></div></div></div>
          <div class="col-6 col-lg-3"><div class="card"><div class="card-body"><h6 class="card-title text-muted mb-2">Instagram účty</h6><div class="h2 mb-0" id="statIg">–</div></div></div></div>
          <div class="col-6 col-lg-3"><div class="card"><div class="card-body"><h6 class="card-title text-muted mb-2">Threads účty</h6><div class="h2 mb-0" id="statTh">–</div></div></div></div>
        </div>

        <div class="card">
          <div class="card-header">
            <div class="row g-2 align-items-center">
              <div class="col-12 col-md-4"><input type="search" id="q" class="form-control" placeholder="Hledat podle názvu…" autocomplete="off"></div>
              <div class="col-6 col-md-2">
                <select id="fState" class="form-select" aria-label="Filtr stavu">
                  <option value="">Všechny stavy</option><option value="1">Povolené</option><option value="0">Zakázané</option>
                </select>
              </div>
              <div class="col-6 col-md-2">
                <select id="fNet" class="form-select" aria-label="Filtr sítě">
                  <option value="">Všechny sítě</option><option value="facebook">Facebook</option><option value="instagram">Instagram</option><option value="threads">Threads</option>
                </select>
              </div>
              <div class="col-12 col-md-4 text-md-end">
                <button type="button" class="btn btn-outline-primary btn-sm" id="btnCheck" title="Ověří, že tokeny účtů stále platí">Zkontrolovat účty</button>
                <button type="button" class="btn btn-success btn-sm" id="bulkOn">Povolit zobrazené</button>
                <button type="button" class="btn btn-outline-danger btn-sm" id="bulkOff">Zakázat zobrazené</button>
              </div>
            </div>
          </div>
          <div class="table-responsive">
            <table class="table table-hover align-middle my-0">
              <thead>
                <tr>
                  <th style="width:90px" data-sort="enabled" class="sortable">Povoleno</th>
                  <th style="width:140px" data-sort="platform" class="sortable">Síť</th>
                  <th data-sort="name" class="sortable">Účet</th>
                  <th class="d-none d-md-table-cell" data-sort="page_name">Facebook stránka</th>
                  <th>Připojení</th>
                </tr>
              </thead>
              <tbody id="rows"><tr><td colspan="5" class="text-center text-muted py-4">Načítám…</td></tr></tbody>
            </table>
          </div>
          <div class="card-footer text-muted small" id="foot"></div>
        </div>
<?php layout_foot(['ucty.js']);
