<?php
declare(strict_types=1);
// Veřejný vstup pro přihlášení Passkey: POST ?a=options | verify (JSON)
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/auth.php';
require __DIR__ . '/app/lib/webauthn.php';
session_start_safe();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function pk_out(array $d, int $code = 200): never { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') pk_out(['error' => 'Jen POST.'], 405);
$pdo = db();
if ($left = auth_lock_remaining()) pk_out(['error' => 'Příliš mnoho pokusů, zkuste to za ' . (int)ceil($left / 60) . ' min.'], 429);
$a = $_GET['a'] ?? '';
if ($a === 'options') {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM auth_tokens WHERE purpose = 'wa-login' AND used = 0 AND expires >= " . time())->fetchColumn();
    if ($n > 100) pk_out(['error' => 'Zkuste to za chvíli.'], 429);
    $ch = random_bytes(32);
    auth_token_create($pdo, 'wa-login', 300, bin2hex($ch));
    pk_out(['publicKey' => ['challenge' => b64u_enc($ch), 'rpId' => wa_rp_id(), 'timeout' => 60000, 'userVerification' => 'required', 'allowCredentials' => []]]);
}
if ($a === 'verify') {
    $cred = json_decode((string)file_get_contents('php://input'), true);
    try {
        if (!is_array($cred)) throw new RuntimeException('Neplatný požadavek.');
        $pk = wa_authenticate($pdo, $cred);
        auth_login('Passkey (' . $pk['name'] . ')');
        pk_out(['ok' => true]);
    } catch (Throwable $e) {
        auth_fail('passkey: ' . $e->getMessage());
        pk_out(['error' => 'Přihlášení Passkey se nepodařilo.'], 401);
    }
}
pk_out(['error' => 'Neznámá akce.'], 404);
