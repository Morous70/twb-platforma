<?php
// Přihlášení pomocí Passkey (WebAuthn), veřejný vstup: ?a=options vrátí výzvu, ?a=verify ověří odpověď zařízení.
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/schema.php';
require __DIR__ . '/lib/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pk_out($data, int $code = 200) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
if (!installed()) pk_out(['error' => 'Aplikace není nainstalovaná.'], 400);
migrate();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') pk_out(['error' => 'Neplatný požadavek.'], 405);
if (!wa_available()) pk_out(['error' => 'Passkey vyžaduje zabezpečené spojení (HTTPS).'], 400);
if (auth_lock_remaining() > 0) pk_out(['error' => auth_lock_message()], 429);

$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
try {
    switch ($_GET['a'] ?? '') {
        case 'options':
            pk_out(wa_login_options());
        case 'verify':
            try {
                $row = wa_login_verify($in);
            } catch (RuntimeException $e) {
                auth_register_fail('Passkey');
                pk_out(['error' => 'Přihlášení pomocí Passkey se nezdařilo.'], 401);
            }
            auth_login('Passkey (' . $row['name'] . ')');
            pk_out(['ok' => true]);
        default:
            pk_out(['error' => 'Neznámá akce.'], 404);
    }
} catch (RuntimeException $e) {
    pk_out(['error' => $e->getMessage()], 429);
}
