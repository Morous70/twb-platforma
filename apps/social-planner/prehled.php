<?php
declare(strict_types=1);
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/layout.php';
require_login();
layout_head('Přehled', 'prehled.php');
?>
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <h1 class="h3 mb-0">Přehled</h1>
          <div class="d-flex gap-2">
            <a href="novy.php" class="btn btn-primary"><i class="align-middle me-1" data-feather="plus"></i> Nový příspěvek</a>
            <a href="kalendar.php" class="btn btn-outline-secondary">Kalendář</a>
          </div>
        </div>

        <div id="alerts"></div>

        <div class="row" id="cards"></div>

        <div class="row">
          <div class="col-lg-8">
            <div class="card">
              <div class="card-header"><h5 class="card-title mb-0">Odesláno za posledních 14 dní</h5></div>
              <div class="card-body"><div id="chart" class="dash-chart">Načítám…</div><div id="byNet" class="small mt-3"></div></div>
            </div>
          </div>
          <div class="col-lg-4">
            <div class="card">
              <div class="card-header"><h5 class="card-title mb-0">Výsledky za 30 dní</h5></div>
              <div class="card-body" id="totals">Načítám…</div>
              <div class="card-footer small"><a href="statistiky.php">Podrobné statistiky</a></div>
            </div>
          </div>
        </div>

        <div class="row">
          <div class="col-lg-7">
            <div class="card">
              <div class="card-header d-flex justify-content-between"><h5 class="card-title mb-0">Nejbližší naplánované</h5><a href="seznam.php?status=scheduled" class="small">Všechny</a></div>
              <div class="table-responsive"><table class="table table-hover my-0 align-middle"><tbody id="nextRows"><tr><td class="text-center text-muted py-3">Načítám…</td></tr></tbody></table></div>
            </div>
          </div>
          <div class="col-lg-5">
            <div class="card">
              <div class="card-header"><h5 class="card-title mb-0">Nejúspěšnější příspěvky (30 dní)</h5></div>
              <div class="table-responsive"><table class="table my-0 align-middle"><tbody id="topRows"><tr><td class="text-center text-muted py-3">Načítám…</td></tr></tbody></table></div>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><h5 class="card-title mb-0">Stav systému</h5></div>
          <div class="card-body" id="sys">Načítám…</div>
        </div>
<?php layout_foot(['prehled.js']);
