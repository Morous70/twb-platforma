<?php
declare(strict_types=1);
// OAuth: zrušení tokenu klientem (RFC 7009). Zruší celé připojení. Vždy vrací 200.
require __DIR__ . '/../app/lib/bootstrap.php';
require __DIR__ . '/../app/lib/oauth.php';
oauth_log_request('revoke');
oauth_cors_preflight('POST');
if ($_SERVER['REQUEST_METHOD'] === 'POST') oauth_revoke_token(db(), (string)($_POST['token'] ?? ''));
http_response_code(200); exit;
