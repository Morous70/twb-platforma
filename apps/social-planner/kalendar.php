<?php
declare(strict_types=1);
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/layout.php';
require_login();
layout_head('Kalendář', 'kalendar.php');
?>
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <h1 class="h3 mb-0">Kalendář</h1>
          <a href="novy.php" class="btn btn-primary"><i class="align-middle me-1" data-feather="plus"></i> Nový příspěvek</a>
        </div>

        <div id="badAccounts"></div>

        <div class="row">
          <div class="col-6 col-lg-3"><div class="card"><div class="card-body"><h6 class="card-title text-muted mb-2">Naplánované příspěvky</h6><div class="h2 mb-0" id="stScheduled">–</div></div></div></div>
          <div class="col-6 col-lg-3"><div class="card"><div class="card-body"><h6 class="card-title text-muted mb-2">Odesláno za 7 dní</h6><div class="h2 mb-0 text-success" id="stSent">–</div></div></div></div>
          <div class="col-6 col-lg-3"><a href="seznam.php?status=attention" class="text-reset text-decoration-none"><div class="card"><div class="card-body"><h6 class="card-title text-muted mb-2">Vyžaduje pozornost</h6><div class="h2 mb-0" id="stAttention">–</div></div></div></a></div>
          <div class="col-6 col-lg-3"><a href="ucty.php" class="text-reset text-decoration-none"><div class="card"><div class="card-body"><h6 class="card-title text-muted mb-2">Povolené účty</h6><div class="h2 mb-0" id="stAccounts">–</div></div></div></a></div>
        </div>

        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="card-title mb-0" id="calTitle"></h5>
            <div class="btn-group" role="group" aria-label="Měsíc">
              <button type="button" class="btn btn-outline-secondary btn-sm" id="prev" aria-label="Předchozí měsíc">‹</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" id="today">Dnes</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" id="next" aria-label="Další měsíc">›</button>
            </div>
          </div>
          <div class="card-body">
            <div id="cal" class="cal">Načítám…</div>
            <p class="text-muted small mt-2 mb-0">Klik na den otevře nový příspěvek s tímto datem, klik na příspěvek zobrazí detail. Čárkované jsou budoucí výskyty opakování.</p>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><h5 class="card-title mb-0">Nejbližší naplánované</h5></div>
          <div class="table-responsive">
            <table class="table table-hover my-0 align-middle">
              <tbody id="nextRows"><tr><td class="text-center text-muted py-3">Načítám…</td></tr></tbody>
            </table>
          </div>
        </div>
<?php layout_foot(['kalendar.js']);
