<?php
declare(strict_types=1);
// RFC 9728: metadata chráněného zdroje (/.well-known/oauth-protected-resource, i …/mcp.php) – obsluhuje oauth.php.
$_GET = ['akce' => 'metadata-zdroje'];
require dirname(__DIR__) . '/oauth.php';
