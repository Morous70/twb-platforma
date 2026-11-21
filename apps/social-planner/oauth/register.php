<?php
declare(strict_types=1);
// OAuth: dynamická registrace klienta (RFC 7591). Volá ji Claude / ChatGPT sám při přidání konektoru.
require __DIR__ . '/../app/lib/bootstrap.php';
require __DIR__ . '/../app/lib/oauth.php';
oauth_log_request('register');
oauth_cors_preflight('POST');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') oauth_error('invalid_request', 'Použijte POST s JSON.', 405);
$in = json_decode((string)file_get_contents('php://input', false, null, 0, 20000), true);
if (!is_array($in)) oauth_error('invalid_client_metadata', 'Tělo musí být JSON.');
oauth_json(oauth_register(db(), $in), 201);
