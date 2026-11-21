<?php
declare(strict_types=1);
// RFC 9728: metadata chráněného zdroje (MCP server /mcp/)
require __DIR__ . '/../app/lib/bootstrap.php';
require __DIR__ . '/../app/lib/oauth.php';
oauth_log_request('metadata PR');
oauth_cors_preflight('GET');
oauth_log_detail((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
oauth_json(oauth_pr_metadata());
