<?php
declare(strict_types=1);
// OAuth: výměna kódu za tokeny a obnova tokenů.
require __DIR__ . '/../app/lib/bootstrap.php';
require __DIR__ . '/../app/lib/oauth.php';
oauth_log_request('token');
oauth_cors_preflight('POST');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') oauth_error('invalid_request', 'Použijte POST.', 405);
$in = $_POST;
if (!$in && str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json')) $in = (array)json_decode((string)file_get_contents('php://input'), true);
oauth_log_detail('grant_type=' . (string)($in['grant_type'] ?? '?'));
oauth_json(oauth_token(db(), $in));
