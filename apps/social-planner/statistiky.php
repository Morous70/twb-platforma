<?php
declare(strict_types=1);
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/layout.php';
require_login();
layout_head('Statistiky', 'statistiky.php');
?>
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
          <h1 class="h3 mb-0">Statistiky</h1>
          <div class="d-flex gap-2">
            <select id="days" class="form-select form-select-sm w-auto"><option value="7">7 dní</option><option value="30" selected>30 dní</option><option value="90">90 dní</option><option value="365">rok</option></select>
            <button class="btn btn-primary btn-sm" id="btnRefresh">Obnovit ze sítí</button>
          </div>
        </div>
        <div class="row" id="cards"></div>
        <div class="card">
          <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div><h5 class="card-title mb-0">Nejlepší čas k publikování</h5><div class="small text-muted">Podle vašich vlastních příspěvků za poslední 4 měsíce</div></div>
            <select id="btAcc" class="form-select form-select-sm w-auto"><option value="">Všechny účty</option></select>
          </div>
          <div class="card-body">
            <div id="btTop" class="mb-3"></div>
            <div class="table-responsive"><table class="bt-map" id="btMap"></table></div>
            <div class="d-flex flex-wrap align-items-center gap-3 small text-muted mt-2">
              <span><span class="bt-sw" style="background:#f1f3f5"></span> nic nevyšlo</span>
              <span><span class="bt-sw" style="background:rgba(232,140,48,.45)"></span> pod průměrem</span>
              <span><span class="bt-sw" style="background:rgba(59,125,221,.25)"></span><span class="bt-sw" style="background:rgba(59,125,221,.9)"></span> nad průměrem</span>
              <span>číslo = počet příspěvků</span>
            </div>
          </div>
          <div class="card-footer small text-muted">Každý příspěvek se porovná s průměrem svého účtu (reakce, 2× komentáře, 2× uložení, 3× sdílení), takže velký účet nepřehluší malý. Počítají se jen příspěvky změřené aspoň den po vydání. Dokud je v nějakém čase málo příspěvků, výsledek se drží blízko průměru. Doporučení se ukáže od 8 změřených příspěvků a s každým dalším týdnem bude přesnější. Najdete ho i u pole <em>Kdy publikovat</em> v novém příspěvku.</div>
        </div>
        <div class="card">
          <div class="card-header"><h5 class="card-title mb-0">Publikované příspěvky</h5><div class="small text-muted" id="stamp"></div></div>
          <div class="table-responsive">
            <table class="table table-hover align-middle my-0">
              <thead><tr><th>Příspěvek</th><th>Účet</th><th class="text-end">Dosah</th><th class="text-end">Reakce</th><th class="text-end">Komentáře</th><th class="text-end">Sdílení</th><th class="text-end">Uložení</th></tr></thead>
              <tbody id="rows"><tr><td colspan="7" class="text-center text-muted py-4">Načítám…</td></tr></tbody>
            </table>
          </div>
          <div class="card-footer small text-muted">Čísla se jednou denně obnovují z cronu (příspěvky za posledních 30 dní). Dosah a uložení vyžadují oprávnění k přehledům (<code>read_insights</code> / <code>instagram_manage_insights</code> / <code>threads_manage_insights</code>); bez nich zůstane „–“. Sdílení a uložení jsou jen u platforem, které je poskytují. U Threads je dosah počet zobrazení.</div>
        </div>
<?php layout_foot(['statistiky.js']);
