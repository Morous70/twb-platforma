<?php
declare(strict_types=1);
// RFC 8414: metadata autorizačního serveru (/.well-known/oauth-authorization-server)
require __DIR__ . '/../app/lib/bootstrap.php';
require __DIR__ . '/../app/lib/oauth.php';
oauth_log_request('metadata AS');
oauth_cors_preflight('GET');
oauth_json(oauth_as_metadata());
