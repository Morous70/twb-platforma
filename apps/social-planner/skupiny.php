<?php
declare(strict_types=1);
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/layout.php';
require_login();
layout_head('Skupiny účtů', 'skupiny.php');
?>
        <h1 class="h3 mb-3">Skupiny účtů</h1>
        <p class="text-muted">Skupina je pojmenovaný výběr účtů. Při psaní příspěvku jedním klikem přidáte celou skupinu.</p>
        <div class="row">
          <div class="col-lg-6">
            <div class="card">
              <div class="card-header"><h5 class="card-title mb-0" id="formTitle">Nová skupina</h5></div>
              <div class="card-body pb-2">
                <form id="form" novalidate>
                  <input type="hidden" id="gId">
                  <div class="mb-3"><label class="form-label" for="gName">Název</label><input class="form-control" id="gName" maxlength="60" placeholder="např. Automobilové stránky" autocomplete="off"></div>
                  <label class="form-label d-flex justify-content-between"><span>Účty</span><span class="badge bg-primary" id="gCount">0</span></label>
                  <input type="search" id="q" class="form-control mb-2" placeholder="Hledat účet…" autocomplete="off">
                  <div class="mb-2 d-flex gap-1">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="selAll">Vybrat zobrazené</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="selNone">Zrušit výběr</button>
                  </div>
                </form>
              </div>
              <div class="list-group list-group-flush acc-list" id="accList"><div class="p-3 text-muted">Načítám…</div></div>
              <div class="card-footer">
                <button type="submit" form="form" class="btn btn-primary">Uložit</button>
                <button type="button" class="btn btn-link" id="gReset" hidden>Zrušit úpravu</button>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card">
              <div class="card-header"><h5 class="card-title mb-0">Vaše skupiny</h5></div>
              <div class="table-responsive">
                <table class="table table-hover align-middle my-0"><tbody id="rows"><tr><td class="text-center text-muted py-4">Načítám…</td></tr></tbody></table>
              </div>
            </div>
          </div>
        </div>
<?php layout_foot(['skupiny.js']);
