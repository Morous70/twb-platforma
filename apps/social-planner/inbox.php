<?php
declare(strict_types=1);
// API pro příjem konceptů z jiných aplikací. Autorizace: hlavička „Authorization: Bearer spk_…“.
//   POST inbox.php                      – vytvoří koncept (JSON)
//   GET  inbox.php?id=12                – stav příspěvku (nebo ?external_id=…)
//   GET  inbox.php?resource=accounts    – seznam účtů a skupin (ID pro výběr účtů)
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/graph.php';
require_once __DIR__ . '/app/lib/inbox.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function out(int $code, array $data): never { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$pdo = db();
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true);
if (!$https) out(400, ['error' => 'Použijte HTTPS.']);
// jednoduchá ochrana proti hádání klíčů: po 20 chybných pokusech za minutu blokace
$bucket = 'ib_fail_' . intdiv(time(), 60);
if ((int)meta_get($bucket, '0') >= 20) out(429, ['error' => 'Příliš mnoho chybných pokusů, zkuste to za minutu.']);
$key = apikey_check($pdo);
if (!$key) { meta_set($bucket, (string)((int)meta_get($bucket, '0') + 1)); out(401, ['error' => 'Neplatný nebo chybějící API klíč (hlavička Authorization: Bearer …).']); }

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    if (($_GET['resource'] ?? '') === 'accounts') {
        out(200, [
            'accounts' => $pdo->query("SELECT id, platform, name, enabled FROM accounts ORDER BY platform, name")->fetchAll(PDO::FETCH_ASSOC),
            'groups'   => $pdo->query("SELECT name, account_ids FROM account_groups ORDER BY name")->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }
    if (isset($_GET['id'])) { $q = $pdo->prepare("SELECT * FROM posts WHERE id = ? AND source = ?"); $q->execute([(int)$_GET['id'], $key['name']]); }
    elseif (isset($_GET['external_id'])) { $q = $pdo->prepare("SELECT * FROM posts WHERE external_id = ? AND source = ?"); $q->execute([(string)$_GET['external_id'], $key['name']]); }
    else out(400, ['error' => 'Zadejte id, external_id nebo resource=accounts.']);
    $p = $q->fetch();
    if (!$p) out(404, ['error' => 'Příspěvek nenalezen (vidíte jen koncepty vytvořené tímto klíčem).']);
    $t = $pdo->prepare("SELECT a.platform, a.name, t.status, t.error, t.published_at FROM targets t JOIN accounts a ON a.id = t.account_id WHERE t.post_id = ?");
    $t->execute([$p['id']]);
    out(200, ['id' => (int)$p['id'], 'status' => $p['status'], 'scheduled_at' => date('c', (int)$p['scheduled_at']), 'external_id' => $p['external_id'], 'targets' => $t->fetchAll(PDO::FETCH_ASSOC)]);
}
if ($method !== 'POST') out(405, ['error' => 'Povolené jsou metody GET a POST.']);

$raw = file_get_contents('php://input', false, null, 0, 1_000_001);
if ($raw === false || strlen($raw) > 1_000_000) out(413, ['error' => 'Požadavek je příliš velký (max. 1 MB; obrázky posílejte jako URL).']);
$in = json_decode($raw, true);
if (!is_array($in)) out(400, ['error' => 'Tělo požadavku musí být JSON.']);
set_time_limit(240);
try {
    [$id, $dup] = inbox_create($pdo, $key, $in);
} catch (InvalidArgumentException $e) {
    out(422, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    log_line('API chyba: ' . $e->getMessage());
    out(500, ['error' => 'Chyba serveru při ukládání konceptu.']);
}
$base = rtrim((string)cfg('base_url'), '/');
out($dup ? 200 : 201, ['id' => $id, 'status' => 'draft', 'duplicate' => $dup, 'edit_url' => $base . '/novy.php?edit=' . $id]);
