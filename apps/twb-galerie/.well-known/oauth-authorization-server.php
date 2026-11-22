<?php
declare(strict_types=1);
// RFC 8414: metadata autorizačního serveru (/.well-known/oauth-authorization-server) – obsluhuje oauth.php.
$_GET = ['akce' => 'metadata'];
require dirname(__DIR__) . '/oauth.php';
