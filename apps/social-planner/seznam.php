<?php
declare(strict_types=1);
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/layout.php';
require_login();
layout_head('Příspěvky', 'seznam.php');
?>
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <h1 class="h3 mb-0">Příspěvky</h1>
          <a href="novy.php" class="btn btn-primary"><i class="align-middle me-1" data-feather="plus"></i> Nový příspěvek</a>
        </div>

        <div class="card">
          <div class="card-header">
            <div class="row g-2 align-items-center">
              <div class="col-12 col-md-6">
                <input type="search" id="q" class="form-control" placeholder="Hledat v textu nebo podle účtu…" autocomplete="off">
              </div>
              <div class="col-12 col-md-3">
                <select id="fStatus" class="form-select" aria-label="Filtr stavu">
                  <option value="">Všechny příspěvky</option>
                  <option value="draft">Koncepty</option>
                  <option value="scheduled">Naplánované</option>
                  <option value="done">Odeslané</option>
                  <option value="attention">Vyžadují pozornost (selhalo / částečně)</option>
                  <option value="repeat">Opakované</option>
                </select>
              </div>
            </div>
          </div>
          <div class="table-responsive">
            <table class="table table-hover align-middle my-0">
              <thead>
                <tr>
                  <th class="sortable" data-sort="scheduled_at">Termín</th>
                  <th>Příspěvek</th>
                  <th class="d-none d-md-table-cell">Účty</th>
                  <th>Stav</th>
                  <th class="text-end">Akce</th>
                </tr>
              </thead>
              <tbody id="rows"><tr><td colspan="5" class="text-center text-muted py-4">Načítám…</td></tr></tbody>
            </table>
          </div>
          <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="text-muted small" id="foot"></span>
            <ul class="pagination pagination-sm mb-0" id="pager"></ul>
          </div>
        </div>
<?php layout_foot(['seznam.js']);
