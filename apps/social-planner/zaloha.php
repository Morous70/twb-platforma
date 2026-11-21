<?php
declare(strict_types=1);
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/layout.php';
require __DIR__ . '/app/lib/backup.php';
require_login();

// Stažení (GET, jen pro přihlášeného): ?get=zip | ?get=db | ?get=file&name=planner-….sqlite
$get = $_GET['get'] ?? '';
if ($get !== '') {
    $stamp = date('Ymd-His');
    try {
        if ($get === 'file') {
            $name = basename((string)($_GET['name'] ?? ''));
            $path = backup_dir() . '/' . $name;
            if (!preg_match('/^planner-[0-9-]+\.sqlite$/', $name) || !is_file($path)) { http_response_code(404); exit('Nenalezeno'); }
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path); exit;
        }
        if ($get === 'db') {
            $tmp = tempnam(sys_get_temp_dir(), 'spdb'); backup_db_snapshot(db(), $tmp);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="planner-' . $stamp . '.sqlite"');
            header('Content-Length: ' . filesize($tmp));
            readfile($tmp); @unlink($tmp); exit;
        }
        if ($get === 'zip') {
            set_time_limit(300);
            $zip = backup_make_zip(db(), true);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="zaloha-planovac-' . $stamp . '.zip"');
            header('Content-Length: ' . filesize($zip));
            readfile($zip); @unlink($zip); exit;
        }
    } catch (Throwable $e) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); exit('Záloha selhala: ' . $e->getMessage()); }
}

layout_head('Zálohy', 'zaloha.php');
?>
        <h1 class="h3 mb-3">Zálohy</h1>
        <div class="row">
          <div class="col-lg-6">
            <div class="card">
              <div class="card-header"><h5 class="card-title mb-0">Stáhnout zálohu</h5></div>
              <div class="card-body">
                <p class="text-muted" id="info">Načítám…</p>
                <div class="d-flex flex-wrap gap-2">
                  <a class="btn btn-primary" id="dlZip" href="zaloha.php?get=zip">Vše (databáze + obrázky)</a>
                  <a class="btn btn-outline-primary" href="zaloha.php?get=db">Jen databáze</a>
                </div>
                <div class="alert alert-warning mt-3 mb-0 small">Databáze obsahuje přístupové tokeny vašich účtů. Zálohu ukládejte na bezpečné místo a nikomu ji neposílejte.</div>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card">
              <div class="card-header"><h5 class="card-title mb-0">Automatická záloha na hostingu</h5></div>
              <div class="card-body">
                <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="auto"><label class="form-check-label" for="auto">Jednou týdně uložit snímek databáze (drží se 4 nejnovější)</label></div>
                <p class="small text-muted">Běží z cronu. Chrání před chybou v aplikaci nebo přepsáním dat, ne před ztrátou celého hostingu – k tomu stahujte zálohu i k sobě.</p>
                <button class="btn btn-outline-secondary btn-sm mb-3" id="btnNow">Zálohovat teď</button>
                <div class="list-group" id="files"></div>
              </div>
            </div>
          </div>
        </div>
        <div class="row">
          <div class="col-lg-12">
            <div class="card">
              <div class="card-header"><h5 class="card-title mb-0">Záloha na Google Drive</h5></div>
              <div class="card-body" id="gd">Načítám…</div>
            </div>
          </div>
        </div>
<?php layout_foot(['zaloha.js']);
